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
    $headerHtml = render_head($title);
    $footerHtml = render_foot();

    return $headerHtml . PHP_EOL . $bodyHtml . PHP_EOL . $footerHtml;
}

function render_head(string $title): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safeTitle}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Hiragino Sans','Noto Sans JP','Yu Gothic',sans-serif;background:#f5f5f0;color:#1a1a1a;line-height:1.7}
.container{max-width:720px;margin:0 auto;padding:24px 16px 48px}
.hero{position:relative;border-radius:16px;overflow:hidden;margin-bottom:28px;background:#0a1628}
.hero-svg{display:block;width:100%;height:200px}
.hero-overlay{padding:20px 28px 24px;background:linear-gradient(to top,rgba(10,22,40,.98) 50%,transparent)}
.hero-badge{display:inline-block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;background:rgba(55,138,221,.2);color:#85B7EB;border:1px solid rgba(55,138,221,.4);border-radius:999px;padding:3px 12px;margin-bottom:10px}
.hero-title{font-size:22px;font-weight:700;color:#fff;line-height:1.4;margin-bottom:6px}
.hero-sub{font-size:13px;color:rgba(255,255,255,.65);line-height:1.65}
.meta-bar{display:flex;align-items:center;gap:14px;font-size:12px;color:#888;margin-bottom:28px;flex-wrap:wrap}
.meta-dot{width:3px;height:3px;border-radius:50%;background:#ccc}
.sec-label{display:flex;align-items:center;gap:10px;margin:28px 0 14px}
.sec-label-text{font-size:11px;font-weight:700;color:#999;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}
.sec-label-line{flex:1;height:1px;background:#ddd}
.card{background:#fff;border:1px solid #e8e8e4;border-radius:14px;overflow:hidden;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.card-inner{padding:20px 24px}
.card-title{font-size:17px;font-weight:700;line-height:1.45;margin-bottom:12px;color:#111}
.card-body{font-size:14px;line-height:1.85;color:#444}
.card-body p{margin-bottom:10px}
.card-body p:last-child{margin-bottom:0}
.tag{display:inline-block;font-size:11px;font-weight:700;padding:3px 11px;border-radius:999px;margin-bottom:10px}
.tag-red{background:#FCEBEB;color:#A32D2D}
.tag-blue{background:#E6F1FB;color:#185FA5}
.tag-green{background:#EAF3DE;color:#3B6D11}
.tag-purple{background:#EEEDFE;color:#534AB7}
.tag-amber{background:#FAEEDA;color:#854F0B}
.tag-teal{background:#E0F7F4;color:#0E6B5E}
.illust-block{width:100%;display:block;border-bottom:1px solid #e8e8e4}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:16px 0}
.stat{background:#f8f8f5;border-radius:10px;padding:12px;text-align:center;border:1px solid #eee}
.stat-n{font-size:22px;font-weight:700;display:block;margin-bottom:3px}
.stat-l{font-size:11px;color:#666;line-height:1.4}
.n-red{color:#A32D2D}.n-blue{color:#185FA5}.n-green{color:#3B6D11}.n-amber{color:#854F0B}
.balloon-wrap{display:flex;align-items:flex-start;gap:12px;margin:14px 0}
.balloon-wrap.right{flex-direction:row-reverse}
.balloon-avatar{width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:20px}
.av-blue{background:#E6F1FB}.av-amber{background:#FAEEDA}.av-green{background:#EAF3DE}.av-red{background:#FCEBEB}.av-teal{background:#E0F7F4}
.balloon{position:relative;border-radius:12px;padding:10px 14px;font-size:13px;line-height:1.7;max-width:calc(100% - 60px)}
.balloon.left{background:#f5f5f0;border:1px solid #e0e0da;border-radius:4px 12px 12px 12px;color:#444}
.balloon.right{background:#E6F1FB;color:#185FA5;border:1px solid rgba(55,138,221,.25);border-radius:12px 4px 12px 12px}
.balloon-name{font-size:11px;font-weight:700;color:#999;margin-bottom:4px}
.balloon.right .balloon-name{color:rgba(24,95,165,.6)}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
.mini-card{background:#f8f8f5;border-radius:10px;padding:14px;border:1px solid #eee}
.mini-card h4{font-size:13px;font-weight:700;margin-bottom:6px;color:#222}
.mini-card p{font-size:12px;color:#555;line-height:1.65}
.point-box{background:#f8f8f5;border-radius:12px;padding:16px 20px;margin-top:14px;border:1px solid #eee}
.point-item{display:flex;gap:10px;font-size:13px;line-height:1.75;color:#444;padding:4px 0}
.pi-icon{color:#1D9E75;flex-shrink:0;font-weight:700;margin-top:2px}
.footer-card{background:#fff;border:1px solid #e8e8e4;border-radius:12px;padding:16px 20px;font-size:13px;color:#555;line-height:1.75;margin-top:24px}
.footer-card strong{color:#222}
@media(max-width:500px){.two-col{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr}.hero-title{font-size:18px}.card-inner{padding:16px}}
</style>
</head>
<body>
HTML;
}

function render_foot(): string
{
    return <<<HTML
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
