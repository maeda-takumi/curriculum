<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    append_article_import_log('method_not_allowed', ['method' => (string)($_SERVER['REQUEST_METHOD'] ?? '')]);
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
        'message' => 'POST メソッドのみ利用できます。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    append_article_import_log('invalid_request', ['reason' => 'empty_body']);
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_request',
        'message' => 'リクエストボディが空です。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    append_article_import_log('invalid_json');
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'invalid_json',
        'message' => 'JSON 形式が不正です。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function append_article_import_log(string $status, array $context = []): void
{
    $logPath = __DIR__ . '/article_import_api.log';
    $record = [
        'timestamp' => date('c'),
        'status' => $status,
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'context' => $context,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function build_article_document(string $title, string $bodyHtml): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $headerHtml = '<header data-import-part="header"></header>';
    $footerHtml = '<footer data-import-part="footer"></footer>';

    if (preg_match('/<\s*html[\s>]/iu', $bodyHtml) === 1) {
        $result = preg_replace(
            '/<\s*body([^>]*)>/iu',
            '<body$1>' . $headerHtml,
            $bodyHtml,
            1,
            $bodyOpenReplaced
        );
        if (is_string($result) && ($bodyOpenReplaced ?? 0) > 0) {
            $result = preg_replace('/<\s*\/\s*body\s*>/iu', $footerHtml . '</body>', $result, 1, $bodyCloseReplaced);
            if (is_string($result) && ($bodyCloseReplaced ?? 0) > 0) {
                return $result;
            }
        }
    }
    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safeTitle}</title>
</head>
<body>
{$headerHtml}
{$bodyHtml}
{$footerHtml}
</body>
</html>
HTML;
}

function extract_title_from_body(string $bodyHtml): string
{
    if (trim($bodyHtml) === '') {
        return '';
    }

    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $bodyHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " hero-title ")]');
    if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
        $title = trim($nodes->item(0)?->textContent ?? '');
        if ($title !== '') {
            return preg_replace('/\s+/u', ' ', $title) ?? $title;
        }
    }

    return '';
}
function normalize_visibility(mixed $visibility): string
{
    $normalized = strtolower(trim((string)$visibility));
    if (in_array($normalized, ['private', 'admin', 'public'], true)) {
        return $normalized;
    }

    return '';
}

function normalize_publish_type(mixed $publishType): string
{
    $normalized = strtolower(trim((string)$publishType));
    if (in_array($normalized, ['lesson', 'practice', 'all'], true)) {
        return $normalized;
    }

    return '';
}

/**
 * @return array<int, array{id:string,date:string,title:string,body:string,visibility:string,publish_types:array<int, string>}>
 */
function load_articles(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = $decoded['articles'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $articles = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $visibility = normalize_visibility($item['visibility'] ?? 'public');
        if ($visibility === '') {
            $visibility = 'public';
        }

        $publishTypes = $item['publish_types'] ?? ['lesson', 'practice'];
        if (!is_array($publishTypes)) {
            $publishTypes = ['lesson', 'practice'];
        }

        $normalizedPublishTypes = [];
        foreach ($publishTypes as $publishType) {
            $type = normalize_publish_type($publishType);
            if ($type !== '' && !in_array($type, $normalizedPublishTypes, true)) {
                $normalizedPublishTypes[] = $type;
            }
        }
        if ($normalizedPublishTypes === []) {
            $normalizedPublishTypes = ['lesson', 'practice'];
        }

        $articles[] = [
            'id' => trim((string)($item['id'] ?? '')),
            'date' => trim((string)($item['date'] ?? '')),
            'title' => trim((string)($item['title'] ?? '')),
            'body' => (string)($item['body'] ?? ''),
            'visibility' => $visibility,
            'publish_types' => $normalizedPublishTypes,
        ];
    }

    return $articles;
}

/**
 * @param array<int, array{id:string,date:string,title:string,body:string,visibility:string,publish_types:array<int, string>}> $articles
 */
function save_articles(string $path, array $articles): bool
{
    $payloadArticles = [];
    foreach ($articles as $article) {
        $payloadArticles[] = [
            'id' => $article['id'],
            'date' => $article['date'],
            'title' => $article['title'],
            'excerpt' => '',
            'body' => $article['body'],
            'visibility' => $article['visibility'],
            'publish_types' => $article['publish_types'],
        ];
    }

    $json = json_encode(['articles' => $payloadArticles], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

$html = (string)($payload['html'] ?? '');
$title = trim((string)($payload['title'] ?? ''));
$bodyTitle = extract_title_from_body($html);
$visibility = normalize_visibility($payload['visibility'] ?? '');
$publishType = normalize_publish_type($payload['publish_type'] ?? '');
$date = trim((string)($payload['date'] ?? date('Y.m.d')));

if (trim($html) === '') {
    append_article_import_log('validation_error', ['field' => 'html']);
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'validation_error',
        'message' => 'html は必須です。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($visibility === '') {
    append_article_import_log('validation_error', ['field' => 'visibility', 'value' => (string)($payload['visibility'] ?? '')]);
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'validation_error',
        'message' => 'visibility は public / admin / private のいずれかを指定してください。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($publishType === '') {
    append_article_import_log('validation_error', ['field' => 'publish_type', 'value' => (string)($payload['publish_type'] ?? '')]);
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'validation_error',
        'message' => 'publish_type は lesson / practice / all のいずれかを指定してください。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($bodyTitle !== '') {
    $title = $bodyTitle;
} elseif ($title === '') {
    $title = '外部連携記事 ' . date('Y-m-d H:i:s');
}

$articlesFile = __DIR__ . '/../include/articles.json';
$articles = load_articles($articlesFile);
$newId = (string)(count($articles) + 1);

$articleHtml = build_article_document($title, $html);
$articles[] = [
    'id' => $newId,
    'date' => $date,
    'title' => $title,

    'body' => $articleHtml,
    'visibility' => $visibility,
    'publish_types' => $publishType === 'all' ? ['lesson', 'practice'] : [$publishType],
];

if (!save_articles($articlesFile, $articles)) {
    append_article_import_log('save_failed', ['article_id' => $newId]);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'save_failed',
        'message' => '記事の保存に失敗しました。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

append_article_import_log('saved', ['article_id' => $newId, 'title' => $title, 'visibility' => $visibility, 'publish_type' => $publishType]);
echo json_encode([
    'ok' => true,
    'article_id' => $newId,
    'message' => '記事を保存しました。',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
