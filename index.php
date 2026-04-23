<?php

declare(strict_types=1);

$scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
    ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME'])
    : '/index.php';
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$normalizedScriptDir = $scriptDir === '/' ? '/' : rtrim($scriptDir, '/');
$appBasePath = ($normalizedScriptDir === '/' ? '' : $normalizedScriptDir) . '/';

if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    $query = parse_url($requestUri, PHP_URL_QUERY);

    if (is_string($path) && $path !== '' && substr($path, -1) !== '/') {
        if ($normalizedScriptDir !== '/' && $path === $normalizedScriptDir) {
            $target = $normalizedScriptDir . '/';
            if (is_string($query) && $query !== '') {
                $target .= '?' . $query;
            }
            header('Location: ' . $target, true, 302);
            exit;
        }
    }
}
require_once __DIR__ . '/login_check.php';

function normalize_curriculum(string $curriculum): string
{
    return $curriculum === 'lesson' ? 'lesson' : 'practice';
}

function invalid_page_fallback(string $curriculum, string $appBasePath): string
{
    if ($curriculum === 'lesson') {
        return $appBasePath . '?page=index&curriculum=lesson';
    }

    return $appBasePath . '?page=index';
}

function is_mobile_client(): bool
{
    $chUaMobile = strtolower(trim((string) ($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? '')));
    if ($chUaMobile === '?1' || $chUaMobile === '1') {
        return true;
    }
    if ($chUaMobile === '?0' || $chUaMobile === '0') {
        return false;
    }

    $userAgent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($userAgent === '') {
        return false;
    }

    // iPadOS Safari can expose a desktop-like UA ("Macintosh") while still being touch/mobile.
    if (strpos($userAgent, 'macintosh') !== false && strpos($userAgent, 'mobile') !== false) {
        return true;
    }

    return preg_match('/iphone|ipod|ipad|android|windows phone|blackberry|bb10|webos|opera mini|iemobile|mobile/i', $userAgent) === 1;
}
$requestedCurriculum = isset($_GET['curriculum']) && is_string($_GET['curriculum'])
    ? normalize_curriculum($_GET['curriculum'])
    : null;
if ($requestedCurriculum !== null) {
    $_SESSION['curriculum'] = $requestedCurriculum;
}
$curriculum = normalize_curriculum((string)($_SESSION['curriculum'] ?? 'practice'));
$isLessonCurriculum = $curriculum === 'lesson';

$page = $_GET['page'] ?? 'index';
if (!is_string($page) || trim($page) === '') {
    $page = 'index';
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $page)) {
    header('Location: ' . invalid_page_fallback($curriculum, $appBasePath));
    exit;
}

$includeDirectoryName = $isLessonCurriculum ? 'include_lesson' : 'include';
$includeDir = __DIR__ . '/' . $includeDirectoryName;
$requested = $includeDir . '/' . $page . '.html';
$realIncludeDir = realpath($includeDir);
$realRequested = realpath($requested);

if (
    $realIncludeDir === false
    || $realRequested === false
    || strncmp($realRequested, $realIncludeDir, strlen($realIncludeDir)) !== 0
    || !is_file($realRequested)
) {
    header('Location: ' . invalid_page_fallback($curriculum, $appBasePath));
    exit;
}
$html = file_get_contents($realRequested);
/**
 * @param array<int, array{label:string,title:string,items:array<int, array{num:string,title:string,page:?string}>}> $phases
 * @param array<string, bool> $lockedPhases
 * @param array<string, bool> $lockedPages
 */
function renderHamburgerMenu(array $phases, string $currentPage, array $lockedPhases, array $lockedPages, bool $isLessonCurriculum = false): string
{
    global $appBasePath;
    $html = '<button class="hb-toggle" id="hb-toggle" type="button" aria-label="メニューを開く" aria-controls="hb-nav" aria-expanded="false">';
    $html .= '<span class="hb-toggle__line"></span>';
    $html .= '<span class="hb-toggle__line"></span>';
    $html .= '<span class="hb-toggle__line"></span>';
    $html .= '</button>';
    $html .= '<nav class="hb-nav" id="hb-nav" aria-hidden="true">';
    $html .= '<div class="hb-nav__header"><strong>講義メニュー</strong><button class="hb-close" id="hb-close" type="button" aria-label="メニューを閉じる">×</button></div>';
    $html .= '<ul class="hb-phase-list">';

    foreach ($phases as $index => $phase) {
        $phaseKey = 'phase' . $index;
        $isPhaseLocked = ($lockedPhases[$phaseKey] ?? false) === true;
        $phaseId = 'hb-phase-' . $index;
        $html .= '<li class="hb-phase-item">';
        $html .= '<button class="hb-phase-toggle' . ($isPhaseLocked ? ' is-locked' : '') . '" type="button" data-target="' . $phaseId . '" aria-expanded="false">';
        $html .= '<span class="hb-phase-header">';
        $html .= '<span class="hb-phase-label">' . htmlspecialchars($phase['label'], ENT_QUOTES, 'UTF-8') . '</span>';
        $html .= '<span class="hb-phase-arrow" aria-hidden="true"></span>';
        $html .= '</span>';
        if ($isPhaseLocked) {
            $html .= '<span class="hb-lock-overlay"><img src="img/lock.png" alt="ロック中"></span>';
        }
        $html .= '</button>';

        $html .= '<ul class="hb-child-list" id="' . $phaseId . '" hidden>';
        foreach ($phase['items'] as $item) {
            $num = htmlspecialchars($item['num'], ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
            $html .= '<li class="hb-child-item">';
            if ($item['page'] !== null) {
                $target = htmlspecialchars($item['page'], ENT_QUOTES, 'UTF-8');
                $isLockedPage = ($lockedPages[$item['page']] ?? false) === true;
                $active = $currentPage === $item['page'] ? ' is-active' : '';
                $lockClass = $isLockedPage ? ' is-locked' : '';
                $targetHref = $isLockedPage ? lock_page_url($item['page'], $isLessonCurriculum) : $appBasePath . '?page=' . $target;
                $html .= '<a class="hb-link' . $active . $lockClass . '" href="' . htmlspecialchars($targetHref, ENT_QUOTES, 'UTF-8') . '"><span class="hb-num">' . $num . '</span>' . $title;
                if ($isLockedPage) {
                    $html .= '<span class="hb-lock-overlay"><img src="img/lock.png" alt="ロック中"></span>';
                }
                $html .= '</a>';
            } else {
                $html .= '<span class="hb-nolink"><span class="hb-num">' . $num . '</span>' . $title . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        $html .= '</li>';
    }

    $html .= '</ul>';
    $html .= '<div class="hb-nav__footer">';
    $html .= '<div class="hb-nav__quick-links">';
    $indexLink = $appBasePath . '?page=index' . ($isLessonCurriculum ? '&curriculum=lesson' : '');
    $articleLink = $appBasePath . '?page=article' . ($isLessonCurriculum ? '&curriculum=lesson' : '');
    $html .= '<a class="hb-quick-link" href="' . htmlspecialchars($indexLink, ENT_QUOTES, 'UTF-8') . '">目次へ</a>';
    $html .= '<a class="hb-quick-link" href="' . htmlspecialchars($articleLink, ENT_QUOTES, 'UTF-8') . '">記事一覧へ</a>';
    $html .= '</div>';
    $logoutHref = $appBasePath . 'login/logout.php' . ($isLessonCurriculum ? '?curriculum=lesson' : '');
    $html .= '<div class="hb-nav__logout"><a class="hb-logout-link" href="' . htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8') . '">ログアウト</a></div>';
    $html .= '</div>';
    $html .= '</nav>';

    return $html;
}

/**
 * @return array{label:string,page:string}|null
 */
function resolveNextNavigation(string $currentPage): ?array
{
    if ($currentPage === 'index') {
        return null;
    }

    $phaseTransitionMap = [
        '08' => '11',
        '17' => '21',
        '28' => '31',
        '37' => '41',
        '47' => '51',
        '57' => '61',
    ];

    if ($currentPage === '67') {
        return ['label' => '一覧へ', 'page' => 'index'];
    }

    if (isset($phaseTransitionMap[$currentPage])) {
        return ['label' => '次のフェーズへ', 'page' => $phaseTransitionMap[$currentPage]];
    }

    if (preg_match('/^\d{2}$/', $currentPage) === 1) {
        $nextPage = str_pad((string) ((int) $currentPage + 1), 2, '0', STR_PAD_LEFT);
        return ['label' => '次の章へ', 'page' => $nextPage];
    }

    return null;
}
/**
 * @param array<int, array{items:array<int, array{page:?string}>}> $phases
 * @return array<string, array{label:string,page:string}>
 */
function build_sequential_next_navigation(array $phases): array
{
    $orderedPages = [];
    foreach ($phases as $phase) {
        foreach ($phase['items'] as $item) {
            if ($item['page'] !== null) {
                $orderedPages[] = $item['page'];
            }
        }
    }

    $map = [];
    $pageCount = count($orderedPages);
    foreach ($orderedPages as $index => $currentPage) {
        $nextPage = $orderedPages[$index + 1] ?? null;
        if ($nextPage !== null) {
            $map[$currentPage] = ['label' => '次の章へ', 'page' => $nextPage];
        } elseif ($pageCount > 0) {
            $map[$currentPage] = ['label' => '一覧へ', 'page' => 'index'];
        }
    }

    return $map;
}
function lock_page_url(string $targetPage, bool $isLessonCurriculum = false): string
{
    global $appBasePath;

    $query = '?page=lock&target=' . rawurlencode($targetPage);
    if ($isLessonCurriculum) {
        $query .= '&curriculum=lesson';
    }

    return $appBasePath . $query;
}

function with_lesson_curriculum_for_status_pages(string $url, bool $isLessonCurriculum): string
{
    if (!$isLessonCurriculum) {
        return $url;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }

    $query = $parts['query'] ?? null;
    if (!is_string($query) || $query === '') {
        return $url;
    }

    parse_str($query, $params);
    $page = is_string($params['page'] ?? null) ? $params['page'] : null;
    if ($page !== 'lock' && $page !== 'submitted') {
        return $url;
    }

    if (($params['curriculum'] ?? null) === 'lesson') {
        return $url;
    }

    $params['curriculum'] = 'lesson';
    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    if (isset($parts['path'])) {
        $rebuilt .= $parts['path'];
    }
    $rebuilt .= '?' . http_build_query($params);
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

/**
 * @param array<int, array{items:array<int, array{page:?string}>}> $phases
 * @return array<string, string>
 */
function build_page_phase_map(array $phases): array
{
    $map = [];
    foreach ($phases as $phaseIndex => $phase) {
        foreach ($phase['items'] as $item) {
            if ($item['page'] !== null) {
                $map[$item['page']] = 'phase' . $phaseIndex;
            }
        }
    }

    return $map;
}

/**
 * @return array<string, bool>
 */
function resolve_login_user_phase_locks(): array
{
    $default = function_exists('default_phase_locks') ? default_phase_locks() : [];
    $loginEmail = trim((string)($_SESSION['login_email'] ?? ''));
    if ($loginEmail === '') {
        return $default;
    }

    foreach (load_users() as $user) {
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '' && hash_equals($email, $loginEmail)) {
            return normalize_phase_locks($user['phase_locks'] ?? null);
        }
    }

    return $default;
}
/**
 * @return array<string, bool>
 */
function resolve_login_user_lesson_week_locks(): array
{
    $default = function_exists('default_lesson_week_locks') ? default_lesson_week_locks() : [];
    $loginEmail = trim((string)($_SESSION['login_email'] ?? ''));
    if ($loginEmail === '') {
        return $default;
    }

    foreach (load_users() as $user) {
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '' && hash_equals($email, $loginEmail)) {
            return normalize_lesson_week_locks($user['lesson_week_locks'] ?? null);
        }
    }

    return $default;
}

function resolve_lesson_week_key_from_page(string $page): ?string
{
    if (!preg_match('/^\d{2,3}$/', $page)) {
        return null;
    }

    $pageNumber = (int)$page;
    $ranges = [
        'week1' => [11, 18],
        'week2' => [21, 28],
        'week3' => [31, 38],
        'week4' => [41, 48],
        'week5' => [51, 58],
        'week6' => [61, 68],
        'week7' => [71, 78],
        'week8' => [81, 88],
        'week9' => [91, 98],
        'week10' => [101, 108],
        'week11' => [111, 118],
        'week12' => [121, 128],
    ];

    foreach ($ranges as $weekKey => [$min, $max]) {
        if ($pageNumber >= $min && $pageNumber <= $max) {
            return $weekKey;
        }
    }

    return null;
}
function maybe_unlock_phase1_from_phase0_next(string $currentPage): void
{
    if ($currentPage !== '11') {
        return;
    }

    $shouldUnlock = (string)($_GET['unlock_phase1'] ?? '') === '1'
        && (string)($_GET['from'] ?? '') === '08';
    if (!$shouldUnlock) {
        return;
    }

    $loginEmail = trim((string)($_SESSION['login_email'] ?? ''));
    if ($loginEmail === '') {
        return;
    }

    $users = load_users();
    $updated = false;

    foreach ($users as &$user) {
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !hash_equals($email, $loginEmail)) {
            continue;
        }

        $phaseLocks = normalize_phase_locks($user['phase_locks'] ?? null);
        if (($phaseLocks['phase1'] ?? false) === true) {
            $phaseLocks['phase1'] = false;
            $user['phase_locks'] = $phaseLocks;
            $updated = true;
        }
        break;
    }
    unset($user);

    if ($updated) {
        save_users($users);
    }
}
$practicePhases = [
    [
        'label' => 'PHASE 0',
        'title' => 'フェーズ0｜思考OSのインストール編',
        'items' => [
            ['num' => '01.', 'title' => 'AI副業が乱立する時代に“軸”がないと起きること', 'page' => '01'],
            ['num' => '02.', 'title' => 'すべてのAI副業に共通する「言語設計」という土台', 'page' => '02'],
            ['num' => '03.', 'title' => '画像・動画・自動化・SNSの前に必ず存在する工程', 'page' => '03'],
            ['num' => '04.', 'title' => 'このスクールで定義する「ライティング」とは何か', 'page' => '04'],
            ['num' => '05.', 'title' => 'ライティングが“つぶしが効くスキル”である理由', 'page' => '05'],
            ['num' => '06.', 'title' => 'ChatGPTと人間の役割分担の考え方', 'page' => '06'],
            ['num' => '07.', 'title' => '「書く人」ではなく「価値を生む編集者」になる思考', 'page' => '07'],
            ['num' => '08.', 'title' => '実践ワーク：あらゆるコンテンツを“言語設計”で分解する', 'page' => '08'],
        ],
    ],
    [
        'label' => 'PHASE 1',
        'title' => 'フェーズ1｜ChatGPT×ライティング基礎構築編',
        'items' => [
            ['num' => '1-1.', 'title' => 'ChatGPTを「ライティングアシスタント」にする基本操作', 'page' => '11'],
            ['num' => '1-2.', 'title' => '売れる文章の型（構造・流れ・役割）を理解する', 'page' => '12'],
            ['num' => '1-3.', 'title' => '読まれる文章と読まれない文章の決定的な違い', 'page' => '13'],
            ['num' => '1-4.', 'title' => 'STP・4P・AIDMAを“ライティング視点”で理解する', 'page' => '14'],
            ['num' => '1-5.', 'title' => 'ChatGPTに「狙った文章」を書かせる指示の出し方', 'page' => '15'],
            ['num' => '1-6.', 'title' => '短文・長文・SNS・セールスでの使い分け', 'page' => '16'],
            ['num' => '1-7.', 'title' => '実践：ChatGPTで“使える文章”を量産する', 'page' => '17'],
        ],
    ],
    [
        'label' => 'PHASE 2',
        'title' => 'フェーズ2｜最短で初収益を出すライティング実践編',
        'items' => [
            ['num' => '2-1.', 'title' => '7Days Complete Manual', 'page' => '21'],
            ['num' => '2-2.', 'title' => '初心者が狙うべき「応募型ライティング案件」の見極め方', 'page' => '22'],
            ['num' => '2-3.', 'title' => 'ChatGPT前提で成立する“低リスク・即金型”の稼ぎ方', 'page' => '23'],
            ['num' => '2-4.', 'title' => 'クラウドワークス攻略｜待たずに案件を取りに行く思考', 'page' => '24'],
            ['num' => '2-5.', 'title' => '実績ゼロでも通る「応募文＝営業ライティング」設計', 'page' => '25'],
            ['num' => '2-6.', 'title' => '「作業者」で終わらないための納品コミュニケーション', 'page' => '26'],
            ['num' => '2-7.', 'title' => '単価が上がる人・上がらない人の分岐点', 'page' => '27'],
            ['num' => '2-8.', 'title' => '実践：案件応募 → 受注 → 納品 → 初収益までを1周させる', 'page' => '28'],
        ],
    ],
    [
        'label' => 'PHASE 3',
        'title' => 'フェーズ3｜ライティングを“武器”にする応用編',
        'items' => [
            ['num' => '3-1.', 'title' => 'セールスライティングの基本構造', 'page' => '31'],
            ['num' => '3-2.', 'title' => 'LP・セールス文章をChatGPTで設計する', 'page' => '32'],
            ['num' => '3-3.', 'title' => 'SNS・動画台本・広告文への横展開', 'page' => '33'],
            ['num' => '3-4.', 'title' => '文章×画像×動画をつなぐ“設計思考”', 'page' => '34'],
            ['num' => '3-5.', 'title' => '「書ける人」から「全体を設計できる人」へ', 'page' => '35'],
            ['num' => '3-6.', 'title' => 'クライアントワークの幅を広げる考え方', 'page' => '36'],
            ['num' => '3-7.', 'title' => '実践：複数ジャンルに応用するライティング設計', 'page' => '37'],
        ],
    ],
    [
        'label' => 'PHASE 4',
        'title' => 'フェーズ4｜再現性・自動化・仕組み化編',
        'items' => [
            ['num' => '4-1.', 'title' => 'ライティングを“使い回す”という発想', 'page' => '41'],
            ['num' => '4-2.', 'title' => 'ChatGPT×テンプレ化の考え方', 'page' => '42'],
            ['num' => '4-3.', 'title' => '自分専用プロンプトの作り方', 'page' => '43'],
            ['num' => '4-4.', 'title' => 'クライアント案件を効率化する設計', 'page' => '44'],
            ['num' => '4-5.', 'title' => '自動化ツールを「ライティング視点」で使う', 'page' => '45'],
            ['num' => '4-6.', 'title' => '時間単価を上げるための仕組み設計', 'page' => '46'],
            ['num' => '4-7.', 'title' => '実践：自分の作業を半分に減らす', 'page' => '47'],
        ],
    ],
    [
        'label' => 'PHASE 5',
        'title' => 'フェーズ5｜資産化・教育・展開編',
        'items' => [
            ['num' => '5-1.', 'title' => '自分のノウハウを商品化する考え方', 'page' => '51'],
            ['num' => '5-2.', 'title' => 'ライティング×コンテンツ販売の設計', 'page' => '52'],
            ['num' => '5-3.', 'title' => 'note／Kindle／楽天ROOMへの展開', 'page' => '53'],
            ['num' => '5-4.', 'title' => 'SNS・LINE・動画と組み合わせた導線設計', 'page' => '54'],
            ['num' => '5-5.', 'title' => '「信用」を積み上げる発信戦略', 'page' => '55'],
            ['num' => '5-6.', 'title' => '教える側に回ると収益が安定する理由', 'page' => '56'],
            ['num' => '5-7.', 'title' => '実践：自分のAI副業ロードマップを完成させる', 'page' => '57'],
        ],
    ],
    [
        'label' => 'PHASE 6',
        'title' => 'フェーズ6｜ライティング以外のAI副業展開編',
        'items' => [
            ['num' => '6-1.', 'title' => 'ライティング以外で稼げるAI副業の全体マップ', 'page' => '61'],
            ['num' => '6-2.', 'title' => '楽天ROOM×AIで成立する“仕組み化副業”の構造理解', 'page' => '62'],
            ['num' => '6-3.', 'title' => 'ChatGPT×CANVAで「売れるビジュアル」を量産する設計', 'page' => '63'],
            ['num' => '6-4.', 'title' => 'ChatGPT×画像生成AIで“素材を自作”する実践フロー', 'page' => '64'],
            ['num' => '6-5.', 'title' => '楽天ROOM以外への横展開モデル', 'page' => '65'],
            ['num' => '6-6.', 'title' => '初心者でも再現できる“AI×ビジュアル副業”の案件化', 'page' => '66'],
            ['num' => '6-7.', 'title' => '実践：自分の「ライティング以外の収益ルート」を1本完成させる', 'page' => '67'],
        ],
    ],
];
$lessonPhases = [
    [
        'label' => 'Week 1',
        'title' => 'Week 1｜ChatGPT',
        'items' => [
            ['num' => '1-1.', 'title' => 'ChatGPTとは何か？', 'page' => '11'],
            ['num' => '1-2.', 'title' => 'ChatGPTの基本', 'page' => '12'],
            ['num' => '1-3.', 'title' => 'よくある失敗例と修正例', 'page' => '13'],
            ['num' => '1-4.', 'title' => 'ChatGPTを使いこなすためのマインド', 'page' => '14'],
            ['num' => '1-5.', 'title' => '【テスト課題】ChatGPTで「冷蔵庫の中身から料理案を出してみよう」', 'page' => '15'],
            ['num' => '1-6.', 'title' => '【実践課題①】ChatGPTで「洋楽のタイトルを打ち込んで和訳してみよう」', 'page' => '16'],
            ['num' => '1-7.', 'title' => '【実践課題②】ChatGPTで「今日の出来事を川柳にしてもらおう」', 'page' => '17'],
            ['num' => '1-8.', 'title' => '【実践課題③】ChatGPTで「簡単な自己紹介文を3パターン作ってみよう」', 'page' => '18'],
        ],
    ],
    [
        'label' => 'Week 2',
        'title' => 'Week 2｜ChatGPT応用編',
        'items' => [
            ['num' => '2-1.', 'title' => 'ChatGPTプロンプト応用完全ガイド', 'page' => '21'],
            ['num' => '2-2.', 'title' => 'ChatGPTの役割設定の意味と効力', 'page' => '22'],
            ['num' => '2-3.', 'title' => 'ChatGPTと「文章リライト」', 'page' => '23'],
            ['num' => '2-4.', 'title' => 'ChatGPTで「情報整理」する力', 'page' => '24'],
            ['num' => '2-5.', 'title' => '【テスト課題】インフルエンサーになってSNS広告文を作ろう', 'page' => '25'],
            ['num' => '2-6.', 'title' => '【実践課題①】一日の出来事を長文でChatGPTに入力し、「日記風・ニュース風・友だちへのLINE風」でそれぞれ生成させよう！', 'page' => '26'],
            ['num' => '2-7.', 'title' => '【実践課題②】ニュース記事をコピペして入力し、「3行要約」「子供むけ説明」「成人向け解説」にリライトさせよう！', 'page' => '27'],
        ],
    ],
    [
        'label' => 'Week 3',
        'title' => 'Week 3｜Canva',
        'items' => [
            ['num' => '3-1.', 'title' => 'Canvaとは?初心者のための徹底入門ガイド', 'page' => '31'],
            ['num' => '3-2.', 'title' => 'テンプレートの使い方 完全ガイド', 'page' => '32'],
            ['num' => '3-3.', 'title' => 'フォントと色彩設定の基礎', 'page' => '33'],
            ['num' => '3-4.', 'title' => '画像と素材の挿入方法 完全ガイド', 'page' => '34'],
            ['num' => '3-5.', 'title' => '【テスト課題】自分の名刺を作ろう！', 'page' => '35'],
            ['num' => '3-6.', 'title' => '【応用課題①】自己紹介カードを作ろう', 'page' => '36'],
            ['num' => '3-7.', 'title' => '【応用課題②】「誕生日おめでとうカードを作成しよう」', 'page' => '37'],
            ['num' => '3-8.', 'title' => '【応用課題③】「LINEアイコンを作成しよう」', 'page' => '38'],
        ],
    ],
    [
        'label' => 'Week 4',
        'title' => 'Week 4｜Canva応用',
        'items' => [
            ['num' => '4-1.', 'title' => 'Canvaのデザイン原則', 'page' => '41'],
            ['num' => '4-2.', 'title' => 'テンプレートの応用方法', 'page' => '42'],
            ['num' => '4-3.', 'title' => '写真と文字のバランス', 'page' => '43'],
            ['num' => '4-4.', 'title' => '実用的なデータ書き出し', 'page' => '44'],
            ['num' => '4-5.', 'title' => '【テスト課題】Youtubeのサムネイルを作成しよう', 'page' => '45'],
            ['num' => '4-6.', 'title' => '【実践課題①】「オリジナル壁紙を作ろう！」', 'page' => '46'],
            ['num' => '4-7.', 'title' => '【実践課題②】「お礼LINEで送れる一言画像」', 'page' => '47'],
            ['num' => '4-8.', 'title' => '【実践課題③】「季節のイベント告知ポスター作製」', 'page' => '48'],
        ],
    ],
    [
        'label' => 'Week 5',
        'title' => 'Week 5｜Notion',
        'items' => [
            ['num' => '5-1.', 'title' => 'Notionとは？', 'page' => '51'],
            ['num' => '5-2.', 'title' => 'Notionの基本の操作', 'page' => '52'],
            ['num' => '5-3.', 'title' => '日常生活での活用例（家計簿・習慣トラッカー）', 'page' => '53'],
            ['num' => '5-4.', 'title' => '副業に向けた整理術', 'page' => '54'],
            ['num' => '5-5.', 'title' => '【テスト課題】1週間の献立表をNotionで管理しよう', 'page' => '55'],
            ['num' => '5-6.', 'title' => '【実践課題①】「ToDoリストを作る」', 'page' => '56'],
            ['num' => '5-7.', 'title' => '【実践課題②】「欲しいものリスト作成」', 'page' => '57'],
        ],
    ],
    [
        'label' => 'Week 6',
        'title' => 'Week 6｜Notion応用',
        'items' => [
            ['num' => '6-1.', 'title' => 'ChatGPT×Notionで作る「データベース活用」', 'page' => '61'],
            ['num' => '6-2.', 'title' => 'テンプレート活用とカスタマイズ', 'page' => '62'],
            ['num' => '6-3.', 'title' => 'チーム利用を意識したページ共有方法', 'page' => '63'],
            ['num' => '6-4.', 'title' => '副業への直結例（ブログネタ管理／SNSカレンダー）', 'page' => '64'],
            ['num' => '6-5.', 'title' => '【テスト課題】副業アイデアリストを作成しよう', 'page' => '65'],
            ['num' => '6-6.', 'title' => '【実践課題①】「副業アイデアリストを作る」', 'page' => '66'],
            ['num' => '6-7.', 'title' => '【実践課題②】「夢・目標のロードマップを作る」', 'page' => '67'],
        ],
    ],
    [
        'label' => 'Week 7',
        'title' => 'Week 7｜CapCut',
        'items' => [
            ['num' => '7-1.', 'title' => 'CapCutとは？スマホで出来る動画編集の基本', 'page' => '71'],
            ['num' => '7-2.', 'title' => 'CapCut編集の第一歩：素材の取り込み（動画・編集・音楽）', 'page' => '72'],
            ['num' => '7-3.', 'title' => 'CapCut編集の基本操作', 'page' => '73'],
            ['num' => '7-4.', 'title' => 'CapCut×VOICEVOXで作るナレーション動画入門', 'page' => '74'],
            ['num' => '7-5.', 'title' => 'TikTok/Instagram向け縦動画の作り方', 'page' => '75'],
            ['num' => '7-6.', 'title' => '【テスト課題】テロップ入りのショート動画（30秒）', 'page' => '76'],
            ['num' => '7-7.', 'title' => '【実践課題①】「今日の1日を写真」＋音楽でスライド化', 'page' => '77'],
        ],
    ],
    [
        'label' => 'Week 8',
        'title' => 'Week 8｜CapCut応用',
        'items' => [
            ['num' => '8-1.', 'title' => 'TikTokやリールで伸びる動画の特徴', 'page' => '81'],
            ['num' => '8-2.', 'title' => '動画にフック（納富でひきつける要素）を入れる', 'page' => '82'],
            ['num' => '8-3.', 'title' => 'ChatGPTでスクリプトを作って動画化', 'page' => '83'],
            ['num' => '8-4.', 'title' => '【テスト課題】AIナレーションを入れた3分動画を作ろう', 'page' => '84'],
            ['num' => '8-5.', 'title' => '【実践課題①】「ChatGPTに原稿を書かせ、CapCutで編集」', 'page' => '85'],
        ],
    ],
    [
        'label' => 'Week 9',
        'title' => 'Week 9｜マーケティング①',
        'items' => [
            ['num' => '9-1.', 'title' => 'マーケティングとは？', 'page' => '91'],
            ['num' => '9-2.', 'title' => 'マーケティング基礎編テキスト「STP分析の超入門」', 'page' => '92'],
            ['num' => '9-3.', 'title' => 'マーケティング基礎「4P分析の超入門」', 'page' => '93'],
            ['num' => '9-4.', 'title' => 'マーケティング基礎「消費者心理の基本 AIDMAモデル」', 'page' => '94'],
            ['num' => '9-5.', 'title' => '【テスト課題①】「ユニクロ」と「GU」の商品の具体例を挙げて違いを4Pで整理し、のマーケティング戦略を細分化してまとめよう！', 'page' => '95'],
            ['num' => '9-6.', 'title' => '【実践課題①】「今日買った商品を1つ選び、『値段以外の価値（意味）』を3つ書き出す」', 'page' => '96'],
            ['num' => '9-7.', 'title' => '【実践課題②】自分が良く買う用品を1つ選んで、『誰向け（ターゲット）』かを考える', 'page' => '97'],
            ['num' => '9-8.', 'title' => '【実践課題③】「最近買った商品の購入の流れをAIDMAに当てはめて書いてみる」', 'page' => '98'],
        ],
    ],
    [
        'label' => 'Week 10',
        'title' => 'Week 10｜マーケティング②',
        'items' => [
            ['num' => '10-1.', 'title' => 'カスタマージャーニーとは？', 'page' => '101'],
            ['num' => '10-2.', 'title' => 'マーケティング応用編「ロゴ・色・キャッチコピーがなぜ大事なのか」', 'page' => '102'],
            ['num' => '10-3.', 'title' => 'マーケティング応用編「競合優位性と差別化戦略」', 'page' => '103'],
            ['num' => '10-4.', 'title' => 'マーケティング応用編「LTVを考える」', 'page' => '104'],
            ['num' => '10-5.', 'title' => '【テスト課題①】「架空の飲料ブランドを作り、企画書を各（5W1Hに基づいて細分化）」', 'page' => '105'],
            ['num' => '10-6.', 'title' => '【実践課題①】「自分が最近買った商品のカスタマージャーニーを書き出す」', 'page' => '106'],
            ['num' => '10-7.', 'title' => '【実践課題②】「家にある商品を1つ選び、ブランド戦略の観点から色・デザインを分析する」', 'page' => '107'],
            ['num' => '10-8.', 'title' => '【実践課題③】「競合が多いジャンル（例：ペットボトル飲料）を比較して差別化ポイントを書く」', 'page' => '108'],
            ['num' => '10-9.', 'title' => '【実践課題④】「自分のよく使うサービスのLTVを計算してみる」', 'page' => '109'],
        ],
    ],
    [
        'label' => 'Week 11',
        'title' => 'Week 11｜マーケティング応用①',
        'items' => [
            ['num' => '11-1.', 'title' => '「ファネル設計とは？」', 'page' => '111'],
            ['num' => '11-2.', 'title' => '「KPI・KGLの考え方」', 'page' => '112'],
            ['num' => '11-3.', 'title' => '「オンライン広告の基本構造」', 'page' => '113'],
            ['num' => '11-4.', 'title' => '「ストーリーテリングの力」', 'page' => '114'],
            ['num' => '11-5.', 'title' => '【テスト課題】自分の副業アイデアをファネルに落とし込む', 'page' => '115'],
            ['num' => '11-6.', 'title' => '【実践課題①】「コンビニの新商品ポスターを見てストーリーテリング要素を抽出する」', 'page' => '116'],
            ['num' => '11-7.', 'title' => '【実践課題②】「架空の商品を設定し、KGI・KPIを設計してみる」', 'page' => '117'],
            ['num' => '11-8.', 'title' => '【実践課題③】「自分のよく使うサービスのLTVを計算してみる」', 'page' => '118'],
        ],
    ],
    [
        'label' => 'Week 12',
        'title' => 'Week 12｜マーケティング応用②',
        'items' => [
            ['num' => '12-1.', 'title' => 'ブルーオーシャン戦略', 'page' => '121'],
            ['num' => '12-2.', 'title' => '価値戦略の心理学', 'page' => '122'],
            ['num' => '12-3.', 'title' => 'データ分析と改善', 'page' => '123'],
            ['num' => '12-4.', 'title' => '持続的なブランド作り', 'page' => '124'],
            ['num' => '12-5.', 'title' => '【テスト課題】日常で体験したマーケティングの罠を分析し、利益向上の施策を考える', 'page' => '125'],
            ['num' => '12-6.', 'title' => '【実践課題①】「衝動買いした商品の行動経済学的理由を説明する」', 'page' => '126'],
            ['num' => '12-7.', 'title' => '【実践課題②】「コンビニの棚を見てアンカリング効果を探す」', 'page' => '127'],
            ['num' => '12-8.', 'title' => '【実践課題③】「自分のSNSに希少性を取り入れた投稿を考える」', 'page' => '128'],
        ],
    ],
];

$phaseLocks = [];
$lockedPages = [];
if (!$isLessonCurriculum) {
    maybe_unlock_phase1_from_phase0_next($page);
    $pagePhaseMap = build_page_phase_map($practicePhases);
    $phaseLocks = resolve_login_user_phase_locks();
    foreach ($pagePhaseMap as $targetPage => $phaseKey) {
        $lockedPages[$targetPage] = ($phaseLocks[$phaseKey] ?? false) === true;
    }

    if ($page !== 'index' && $page !== 'lock' && (($lockedPages[$page] ?? false) === true)) {
        header('Location: ' . lock_page_url($page, false));
        exit;
    }
} else {
    $pagePhaseMap = build_page_phase_map($lessonPhases);
    $lessonWeekLocks = resolve_login_user_lesson_week_locks();
    $lessonWeekIndexMap = [
        'week1' => 'phase0',
        'week2' => 'phase1',
        'week3' => 'phase2',
        'week4' => 'phase3',
        'week5' => 'phase4',
        'week6' => 'phase5',
        'week7' => 'phase6',
        'week8' => 'phase7',
        'week9' => 'phase8',
        'week10' => 'phase9',
        'week11' => 'phase10',
        'week12' => 'phase11',
    ];
    foreach ($pagePhaseMap as $targetPage => $phaseKey) {
        $weekKey = array_search($phaseKey, $lessonWeekIndexMap, true);
        $lockedPages[$targetPage] = ($weekKey !== false && (($lessonWeekLocks[$weekKey] ?? false) === true));
    }
    foreach ($lessonWeekIndexMap as $weekKey => $phaseKey) {
        $phaseLocks[$phaseKey] = ($lessonWeekLocks[$weekKey] ?? false) === true;
    }
    $lessonWeekKey = resolve_lesson_week_key_from_page($page);
    if ($page !== 'index' && $page !== 'lock' && $lessonWeekKey !== null && (($lessonWeekLocks[$lessonWeekKey] ?? false) === true)) {
        header('Location: ' . lock_page_url($page, true));
        exit;
    }
}
$menuStyle = <<<'CSS'
<style>
.hb-toggle {
  position: fixed;
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  top: 14px;
  right: 30px;
  z-index: 2147483647;
  width: 52px;
  height: 52px;
  border: none;
  border-radius: 14px;
  background: linear-gradient(135deg, #6b35ff 0%, #b938ff 50%, #ff6a4d 100%);
  cursor: pointer;
  box-shadow: 0 10px 26px rgba(105, 61, 220, 0.35);
}

.hb-toggle__line {
  width: 22px;
  height: 2px;
  border-radius: 999px;
  background: #fff;
  transition: transform 0.28s ease, opacity 0.2s ease;
  transform-origin: center;
}

.hb-toggle.is-open .hb-toggle__line:nth-child(1) {
  transform: translateY(8px) rotate(45deg);
}

.hb-toggle.is-open .hb-toggle__line:nth-child(2) {
  opacity: 0;
}

.hb-toggle.is-open .hb-toggle__line:nth-child(3) {
  transform: translateY(-8px) rotate(-45deg);
}
.hb-nav {
  position: fixed;
  inset: 0 0 0 auto;
  width: min(92vw, 520px);
  background: #f6f6f6;
  border-left: 1px solid #e3e3e3;
  box-shadow: -12px 0 32px rgba(0,0,0,0.18);
  transform: translateX(105%);
  transition: transform 0.26s ease;
  z-index: 2147483646;
  overflow-y: auto;
  padding: 16px 18px 20px;
  display: flex;
  flex-direction: column;
}

.hb-nav.is-open {
  transform: translateX(0);
}

.hb-nav__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 8px 6px 14px;
  border-bottom: 1px solid #e6e6e6;
  margin-bottom: 14px;
  font-size: 20px;
}

.hb-close {
  border: none;
  background: transparent;
  font-size: 32px;
  line-height: 1;
  cursor: pointer;
  color: #746a8f;
  display:none;
}

.hb-phase-list,
.hb-child-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.hb-phase-item + .hb-phase-item {
  margin-top: 12px;
}

.hb-phase-toggle {
  width: 100%;
  border: 1px solid #dfdfdf;
  border-radius: 16px;
  background: #efefef;
  position: relative;
  cursor: pointer;
  text-align: left;
  padding: 12px 13px;
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 14px;
}

.hb-phase-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.hb-phase-label {
  font-size: 12px;
  color: #6f6882;
  letter-spacing: 0.08em;
}

.hb-phase-title {
  font-weight: 700;
  background: linear-gradient(90deg, #5f37ff 0%, #ca31c7 56%, #ff774f 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.hb-phase-toggle.is-locked {
  background: #d8d8d8;
  border-color: #bcbcbc;
}

.hb-phase-arrow {
  width: 9px;
  height: 9px;
  border-right: 2px solid #7a6e96;
  border-bottom: 2px solid #7a6e96;
  transform: rotate(45deg);
  transition: transform 0.18s ease;
}

.hb-phase-toggle[aria-expanded="true"] .hb-phase-arrow {
  transform: rotate(225deg);
}

.hb-child-list {
  margin-top: 7px;
  padding-left: 0;
}

.hb-child-item + .hb-child-item {
  margin-top: 4px;
}

.hb-link,
.hb-nolink {
  display: block;
  position: relative;
  border-radius: 10px;
  padding: 9px 11px;
  font-size: 13px;
  line-height: 1.35;
  border: 1px solid #ddd;
}

.hb-link {
  color: #1f2024;
  text-decoration: none;
  background: #f3f3f3;
  font-weight: 700;
}

.hb-link:hover {
  background: #f0e9ff;
}

.hb-link.is-active {
  background: #ece1ff;
  border-color: #ccb5ff;
  font-weight: 700;
}

.hb-link.is-locked {
  background: #d8d8d8;
  border-color: #bebebe;
  color: #666;
  opacity: 0.62;
}
.hb-nolink {
  background: #ededed;
  color: #767676;
}

.hb-num {
  display: inline-block;
  min-width: 54px;
  margin-right: 4px;
  font-weight: 700;
  color: #7d2fde;
}

.hb-nav__footer {
  margin-top: auto;
  padding-top: 18px;
}

.hb-nav__quick-links {
  display: grid;
  gap: 10px;
  margin-bottom: 20px;
}

.hb-quick-link {
  display: block;
  width: 100%;
  text-align: center;
  border-radius: 12px;
  padding: 12px 16px;
  text-decoration: none;
  color: #2a3158;
  background: #f1f5ff;
  border: 1px solid #cdd9ff;
  font-weight: 700;
  box-sizing: border-box;
}

.hb-quick-link:hover {
  background: #e7eeff;
}

.hb-nav__logout {
  border-top: 1px solid #e2e6f3;
  padding-top: 18px;
}
.hb-logout-link {
  display: block;
  width: 100%;
  text-align: center;
  border-radius: 12px;
  padding: 12px 16px;
  text-decoration: none;
  color: #8b2a2a;
  background: #fff1f1;
  border: 1px solid #efcccc;
  font-weight: 700;
  box-sizing: border-box;
}
.hb-lock-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  justify-content: center;
  align-items: center;
  pointer-events: none;
}

.hb-lock-overlay img {
  width: 26px;
  height: 26px;
  opacity: 0.92;
}
</style>
CSS;

$nextLinkStyle = <<<'CSS'
<style>
.page-actions {
  width: min(920px, calc(100% - 40px));
  margin: 36px auto 12px;
  box-sizing: border-box;
  position: relative;
  z-index: 2147483000;
}

.page-actions__submit-link {
    display: block;
    width: 100%;
    margin: 0 0 14px;
    padding: 12px 18px;
    border-radius: 12px;
    border: 2px solid #db57b8;
    background: #ffffff;
    color: #db57b8 !important;
    text-decoration: none;
    font-weight: 700;
    font-size: 24px;
    line-height: 1.25;
    text-align: center;
    box-shadow: 0 6px 14px #ffcaf1;
}

.page-actions__submit-link:hover {
  background: #ffd9f5;
}

.next-nav {
  width: 100%;
  margin: 0;
  box-sizing: border-box;
  position: relative;
}

.next-nav__link {
  display: block;
  position: relative;
  width: 100%;
  padding: 14px 18px;
  border-radius: 12px;
  background: linear-gradient(90deg, #5d2eea 0%, #d84fa2 52%, #ef8a3b 100%);
  color: #fff !important;
  text-decoration: none;
  font-weight: 700;
  font-size: 30px;
  line-height: 1.2;
  text-align: center;
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
}

.next-nav__link:hover {
  opacity: 0.92;
}
.next-nav__link--locked {
  background: #9d9d9d;
  color: #f5f5f5 !important;
  opacity: 0.7;
}

.next-nav__lock {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.next-nav__lock img {
  width: 36px;
  height: 36px;
}
@media (max-width: 768px) {
  /* body {
    padding-bottom: max(96px, calc(env(safe-area-inset-bottom, 0px) + 84px));
  } */
  .page-actions {
    width: min(920px, calc(100% - 28px));
    margin: 24px auto max(16px, calc(env(safe-area-inset-bottom, 0px) + 8px));
  }

  .page-actions__submit-link {
    margin-bottom: 12px;
    padding: 11px 14px;
    font-size: 18px;
  }
  .next-nav__link {
    font-size: 20px;
    padding: 12px 14px;
  }
  .css-12ny5y4,
  [data-node-view-content-inner="cardLayoutItem"]:first-child,
  [data-node-view-content-inner="card"]:first-child {
    margin-top: 8px !important;
  }
}
</style>
CSS;
$menuScript = <<<'JS'
<script>
(function () {
  var toggle = document.getElementById('hb-toggle');
  var closeBtn = document.getElementById('hb-close');
  var nav = document.getElementById('hb-nav');
  if (!toggle || !closeBtn || !nav) {
    return;
  }

  function openMenu() {
    nav.classList.add('is-open');
    toggle.classList.add('is-open');
    nav.setAttribute('aria-hidden', 'false');
    toggle.setAttribute('aria-label', 'メニューを閉じる');
    toggle.setAttribute('aria-expanded', 'true');
  }

  function closeMenu() {
    nav.classList.remove('is-open');
    toggle.classList.remove('is-open');
    nav.setAttribute('aria-hidden', 'true');
    toggle.setAttribute('aria-label', 'メニューを開く');
    toggle.setAttribute('aria-expanded', 'false');
  }

  toggle.addEventListener('click', function () {
    if (nav.classList.contains('is-open')) {
      closeMenu();
    } else {
      openMenu();
    }
  });

  closeBtn.addEventListener('click', closeMenu);

  document.addEventListener('click', function (event) {
    if (!nav.classList.contains('is-open')) {
      return;
    }
    if (nav.contains(event.target) || toggle.contains(event.target)) {
      return;
    }
    closeMenu();
  });

  document.querySelectorAll('.hb-phase-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
      var targetId = button.getAttribute('data-target');
      if (!targetId) {
        return;
      }

      var childList = document.getElementById(targetId);
      if (!childList) {
        return;
      }

      var willExpand = childList.hasAttribute('hidden');
      if (willExpand) {
        childList.removeAttribute('hidden');
      } else {
        childList.setAttribute('hidden', 'hidden');
      }
      button.setAttribute('aria-expanded', willExpand ? 'true' : 'false');
    });
  });
})();
</script>
JS;

$loadingOverlayScript = <<<'JS'
<script>
(function () {
  var overlay = document.getElementById('global-loading-overlay');
  if (!overlay) {
    return;
  }

  function showLoadingOverlay() {
    if (overlay.classList.contains('is-visible')) {
      return;
    }
    overlay.classList.add('is-visible');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('is-loading');
  }

  function hideLoadingOverlay() {
    overlay.classList.remove('is-visible');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('is-loading');
  }

  function isGammaHydrationStable() {
    var hasNextData = document.getElementById('__NEXT_DATA__') !== null;
    if (!hasNextData) {
      return false;
    }

    var hasHydratedMarkers =
      document.querySelector('[data-nextjs-scroll-focus-boundary]') !== null ||
      document.querySelector('.chakra-portal') !== null;
    if (!hasHydratedMarkers) {
      return false;
    }

    return true;
  }

  function waitForStableRenderThenHide() {
    var stableDurationMs = 400;
    var maxWaitMs = 10000;
    var checkIntervalMs = 100;
    var startTime = Date.now();
    var lastMutationAt = Date.now();
    var rafId = 0;
    var timeoutId = 0;
    var pollingIntervalId = 0;
    var finished = false;
    var observer = new MutationObserver(function () {
      lastMutationAt = Date.now();
    });

    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
      attributes: true,
      characterData: true
    });

    function cleanup() {
      finished = true;
      if (rafId) {
        cancelAnimationFrame(rafId);
      }
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
      if (pollingIntervalId) {
        clearInterval(pollingIntervalId);
      }
      observer.disconnect();
    }

    function tryHide() {
      var now = Date.now();
      if (isGammaHydrationStable() && now - lastMutationAt >= stableDurationMs) {
        cleanup();
        hideLoadingOverlay();
      }
    }

    function runRafLoop() {
      if (finished) {
        return;
      }
      tryHide();
      rafId = requestAnimationFrame(runRafLoop);
    }

    timeoutId = setTimeout(function () {
      cleanup();
      hideLoadingOverlay();
    }, maxWaitMs);

    pollingIntervalId = setInterval(function () {
      if (Date.now() - startTime >= maxWaitMs) {
        return;
      }
      tryHide();
    }, checkIntervalMs);

    rafId = requestAnimationFrame(runRafLoop);
  }
  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href]');
    if (!link) {
      return;
    }
    if (link.target === '_blank' || link.hasAttribute('download')) {
      return;
    }

    var href = link.getAttribute('href') || '';
    if (href === '' || href.charAt(0) === '#') {
      return;
    }

    var url;
    try {
      url = new URL(href, window.location.href);
    } catch (e) {
      return;
    }

    if (url.origin !== window.location.origin) {
      return;
    }

    showLoadingOverlay();
  }, true);

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    if (form.target === '_blank') {
      return;
    }
    showLoadingOverlay();
  }, true);

  window.addEventListener('pageshow', waitForStableRenderThenHide);
  window.addEventListener('load', waitForStableRenderThenHide, { once: true });
})();
</script>
JS;
$loadingOverlayStyle = <<<'CSS'
<style>
#global-loading-overlay {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 1);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  transition: opacity 0.18s ease, visibility 0.18s ease;
  z-index: 9999;
}
#global-loading-overlay.is-visible {
  opacity: 1;
  visibility: visible;
  pointer-events: auto;
}
.global-loading-overlay__card {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: 0.04em;
  background: linear-gradient(90deg, #5d2eea 0%, #d84fa2 52%, #ef8a3b 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.global-loading-overlay__spinner {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: conic-gradient(from 0deg, #5d2eea, #d84fa2, #ef8a3b, #5d2eea);
  -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 3px));
  mask: radial-gradient(farthest-side, transparent calc(100% - 4px), #000 calc(100% - 3px));
  animation: global-loading-overlay-spin 0.85s linear infinite;
}
@keyframes global-loading-overlay-spin {
  to {
    transform: rotate(360deg);
  }
}
body.is-loading {
  cursor: progress;
}
</style>
CSS;
$pieScriptPath = 'assets/js/pie-chart.js';
$pieScriptVersion = is_file($pieScriptPath) ? (string) filemtime($pieScriptPath) : '';
$pieScriptSrc = $pieScriptPath;
if ($pieScriptVersion !== '') {
    $pieScriptSrc .= '?v=' . rawurlencode($pieScriptVersion);
}
$pieScriptTag = '<script src="' . htmlspecialchars($pieScriptSrc, ENT_QUOTES, 'UTF-8') . '" defer></script>';
$responsiveCssPath = 'assets/css/include-responsive.css';
$responsiveCssVersion = is_file($responsiveCssPath) ? (string) filemtime($responsiveCssPath) : '';
$responsiveCssHref = 'assets/css/include-responsive.css';
if ($responsiveCssVersion !== '') {
    $responsiveCssHref .= '?v=' . rawurlencode($responsiveCssVersion);
}
$responsiveCssTag = '<link rel="stylesheet" href="' . htmlspecialchars($responsiveCssHref, ENT_QUOTES, 'UTF-8') . '">';
$loadingOverlayMarkup = '<div id="global-loading-overlay" class="is-visible" aria-hidden="false" role="status" aria-live="polite"><div class="global-loading-overlay__card"><div class="global-loading-overlay__spinner" aria-hidden="true"></div>読み込み中</div></div>';
if ($html === false) {
    header('Location: ' . $appBasePath . '?page=index');
    exit;
}

$rewriteRelativeAssetAttributes = static function (string $markup) use ($appBasePath): string {
    return preg_replace_callback(
        '/(href|src)\s*=\s*(["\'])(phase|img)\//i',
        static function (array $matches) use ($appBasePath): string {
            return $matches[1] . '=' . $matches[2] . $appBasePath . $matches[3] . '/';
        },
        $markup
    ) ?? $markup;
};
$html = $rewriteRelativeAssetAttributes($html);
if ($isLessonCurriculum) {
    $html = preg_replace_callback(
        '/\b(href|action)\s*=\s*(["\'])([^"\']+)\2/i',
        static function (array $matches): string {
            $updatedUrl = with_lesson_curriculum_for_status_pages($matches[3], true);
            return $matches[1] . '=' . $matches[2] . $updatedUrl . $matches[2];
        },
        $html
    ) ?? $html;
}

$isMobileClient = is_mobile_client();
header('Vary: User-Agent, Sec-CH-UA-Mobile', false);
if ($isMobileClient) {
    $mobileOrderTargetPages = ['71', '72', '73', '74', '75', '76', '77'];
    if (in_array($page, $mobileOrderTargetPages, true)) {
        $isGammaPublishedRoute =
            strpos($html, '"page":"/published/[docId]"') !== false
            || strpos($html, '"page":"\\/published\\/[docId]"') !== false;

        $requiresMobileRouteLoader = $isGammaPublishedRoute || !$isLessonCurriculum;

        if ($requiresMobileRouteLoader) {
            if ($isGammaPublishedRoute) {
                $html = str_replace('"page":"/published/[docId]"', '"page":"/published_mobile/[docId]"', $html);
                $html = str_replace('"page":"\\/published\\/[docId]"', '"page":"\\/published_mobile\\/[docId]"', $html);
            }

            $mobileRouteLoaderPath = in_array($page, ['72', '73'], true)
                ? 'static/chunks/a9071dc46c486e2f.js'
                : 'static/chunks/a946c26f1dc00c95.js';
            if (strpos($html, $mobileRouteLoaderPath) === false) {
                $mobileRouteLoaderSrc = $appBasePath . ltrim($mobileRouteLoaderPath, '/');
                if (preg_match('#<script[^>]+src=["\'](https?://[^"\']+/_next/)static/chunks/[^"\']+["\'][^>]*>#i', $html, $scriptSourceMatch) === 1) {
                    $mobileRouteLoaderSrc = $scriptSourceMatch[1] . ltrim($mobileRouteLoaderPath, '/');
                }
                $mobileRouteLoaderTag = '<script src="'
                    . htmlspecialchars($mobileRouteLoaderSrc, ENT_QUOTES, 'UTF-8')
                    . '" defer></script>';
                if (stripos($html, '</body>') !== false) {
                    $html = preg_replace('/<\/body>/i', $mobileRouteLoaderTag . "\n</body>", $html, 1) ?? $html;
                } else {
                    $html .= $mobileRouteLoaderTag;
                }
            }
        }
        $html = preg_replace('/\bdata-is-mobile=(["\'])false\1/i', 'data-is-mobile=${1}true${1}', $html) ?? $html;
        $html = str_replace('"isMobile":false', '"isMobile":true', $html);
        $html = str_replace('"isMobile":!1', '"isMobile":!0', $html);
    }
}

$normalizedViewportContent = 'width=device-width, initial-scale=1.0, viewport-fit=cover';
$normalizedViewportTag = '<meta name="viewport" content="' . $normalizedViewportContent . '">';
if (preg_match('/<meta\b(?=[^>]*\bname\s*=\s*["\']viewport["\'])[^>]*>/i', $html) === 1) {
    $html = preg_replace(
        '/(<meta\b(?=[^>]*\bname\s*=\s*["\']viewport["\'])[^>]*\bcontent\s*=\s*)(["\'])[^"\']*\2([^>]*>)/i',
        '$1$2' . $normalizedViewportContent . '$2$3',
        $html,
        1
    ) ?? $html;
    if (preg_match('/<meta\b(?=[^>]*\bname\s*=\s*["\']viewport["\'])[^>]*\bcontent\s*=/i', $html) !== 1) {
        $html = preg_replace(
            '/(<meta\b(?=[^>]*\bname\s*=\s*["\']viewport["\'])[^>]*)(\/?>)/i',
            '$1 content="' . $normalizedViewportContent . '"$2',
            $html,
            1
        ) ?? $html;
    }
} elseif (stripos($html, '</head>') !== false) {
    $html = preg_replace('/<\/head>/i', $normalizedViewportTag . "\n</head>", $html, 1) ?? $html;
} else {
    $html = $normalizedViewportTag . $html;
}

if (stripos($html, '</head>') !== false) {
    $html = preg_replace('/<\/head>/i', $responsiveCssTag . "\n</head>", $html, 1) ?? $html;
} else {
    $html = $responsiveCssTag . $html;
}
if (stripos($html, '</head>') !== false) {
    $html = preg_replace('/<\/head>/i', $loadingOverlayStyle . "\n</head>", $html, 1) ?? $html;
} else {
    $html = $loadingOverlayStyle . $html;
}
if (preg_match('/<body[^>]*>/i', $html) === 1) {
    $html = preg_replace('/<body[^>]*>/i', '$0' . "\n" . $loadingOverlayMarkup, $html, 1) ?? $html;
} else {
    $html = $loadingOverlayMarkup . $html;
}
if (stripos($html, '</body>') !== false) {
    $html = preg_replace('/<\/body>/i', $loadingOverlayScript . "\n</body>", $html, 1) ?? $html;
} else {
    $html .= $loadingOverlayScript;
}
if ($page !== 'index') {
    $menuPhases = $isLessonCurriculum ? $lessonPhases : $practicePhases;
    $menuMarkup = renderHamburgerMenu($menuPhases, $page, $phaseLocks, $lockedPages, $isLessonCurriculum);
    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $menuStyle . "\n</head>", $html, 1) ?? $html;
    } else {
        $html = $menuStyle . $html;
    }

    if (preg_match('/<body[^>]*>/i', $html) === 1) {
        $html = preg_replace('/<body[^>]*>/i', '$0' . "\n" . $menuMarkup, $html, 1) ?? $html;
    } else {
        $html = $menuMarkup . $html;
    }

    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $menuScript . "\n" . $pieScriptTag . "\n</body>", $html, 1) ?? $html;
    } else {
        $html .= $menuScript . "\n" . $pieScriptTag;
    }
}

$lessonNextNavigationMap = build_sequential_next_navigation($lessonPhases);
$nextNavigation = $isLessonCurriculum
    ? ($lessonNextNavigationMap[$page] ?? null)
    : resolveNextNavigation($page);
if ($nextNavigation !== null) {
    $assignmentLinksByCurriculum = [
        'practice' => [
            '17' => [
                'label' => '課題提出',
                'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=RnLvFk&ts=1774261663',
            ],
            '28' => [
                'label' => '課題提出',
                'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=vnO8Wj&ts=1774321859',
            ],
            '37' => [
                'label' => '課題提出',
                'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=M575d6&ts=1774406912',
            ],
            '47' => [
                'label' => '課題提出',
                'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=oGnIEp&ts=1774502845',
            ],
            '57' => [
                'label' => '課題提出',
                'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=rhR3BH&ts=1774407219',
            ],
            '67' => [
                'label' => '課題提出',
                'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=2rc3TF&ts=1774407208',
            ],
        ],
        'lesson' => [
            '15' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=8vNpIx&ts=1776162709'],
            '25' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=vqO53v&ts=1776162709'],
            '35' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=BLJAOL&ts=1776162709'],
            '45' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=zscGqj&ts=1776162709'],
            '55' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=1ZomDX&ts=1776162709'],
            '65' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=UvKnlm&ts=1776162709'],
            '76' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=36THG8&ts=1776162709'],
            '84' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=SNCvMh&ts=1776162709'],
            '95' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=DKL76B&ts=1776162709'],
            '105' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=UamQDK&ts=1776162709'],
            '115' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=WGHgsE&ts=1776162709'],
            '125' => ['label' => '課題提出', 'href' => 'https://liff.line.me/2006803756-gbYNK5eY?unique_key=NuS7lw&ts=1776162709'],
        ],
    ];
    $assignmentLinks = $assignmentLinksByCurriculum[$isLessonCurriculum ? 'lesson' : 'practice'] ?? [];

    $assignmentConfig = $assignmentLinks[$page] ?? null;
    $nextLabelEscaped = htmlspecialchars($nextNavigation['label'], ENT_QUOTES, 'UTF-8');
    $nextPageEscaped = htmlspecialchars($nextNavigation['page'], ENT_QUOTES, 'UTF-8');
    $nextIsLocked = (($lockedPages[$nextNavigation['page']] ?? false) === true);
    $canAutoUnlockPhase1 = $page === '08' && $nextNavigation['page'] === '11' && $nextIsLocked;
    $nextHref = $canAutoUnlockPhase1
        ? $appBasePath . '?page=11&unlock_phase1=1&from=08'
        : ($nextIsLocked ? lock_page_url($nextNavigation['page'], $isLessonCurriculum) : $appBasePath . '?page=' . $nextPageEscaped);
    $showNextLockedState = $nextIsLocked && !$canAutoUnlockPhase1;
    $nextLinkMarkup = '<div class="page-actions">';
    if (is_array($assignmentConfig) && isset($assignmentConfig['label'], $assignmentConfig['href'])) {
        $assignmentLabelEscaped = htmlspecialchars((string) $assignmentConfig['label'], ENT_QUOTES, 'UTF-8');
        $assignmentHrefEscaped = htmlspecialchars((string) $assignmentConfig['href'], ENT_QUOTES, 'UTF-8');
        $nextLinkMarkup .= '<a class="page-actions__submit-link" href="' . $assignmentHrefEscaped . '" target="_blank" rel="noopener noreferrer">' . $assignmentLabelEscaped . '</a>';
    }
    $nextLinkMarkup .= '<div class="next-nav"><a class="next-nav__link' . ($showNextLockedState ? ' next-nav__link--locked' : '') . '" href="' . htmlspecialchars($nextHref, ENT_QUOTES, 'UTF-8') . '">' . $nextLabelEscaped;
    if ($showNextLockedState) {
        $nextLinkMarkup .= '<span class="next-nav__lock"><img src="img/lock.png" alt="ロック中"></span>';
    }
    $nextLinkMarkup .= '</a></div></div>';

    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $nextLinkStyle . "\n</head>", $html, 1) ?? $html;
    } else {
        $html = $nextLinkStyle . $html;
    }

    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $nextLinkMarkup . "\n</body>", $html, 1) ?? $html;
    } else {
        $html .= $nextLinkMarkup;
    }
}
if ($page === '37' || $page === '47' || $page === '57' || $page === '67') {
    $downloadConfigByPage = [
        '37' => [
            'file_name' => 'クライアント情報シート.pdf',
            'target_selector' => '[data-pos="14199"]',
            'use_select_menu' => false,
            'show_assignment_label' => true,
            'assignment_label_text' => '課題用',
        ],
        '47' => [
            'file_name' => '月次振り返りテンプレート.pdf',
            'target_selector' => '[data-pos="17922"] [data-content-reference="true"]',
            'use_select_menu' => false,
            'show_assignment_label' => true,
            'assignment_label_text' => '課題用',
        ],
        '57' => [
            'file_name' => 'ロードマップ.pdf',
            'target_selector' => '[data-pos="10795"] [data-selection-ring="true"]',
            'use_select_menu' => false,
            'show_assignment_label' => true,
            'assignment_label_text' => '課題用',
        ],
        '67' => [
            'file_name' => '7日間の記録シート.pdf',
            'target_selector' => '[data-pos="12328"]',
            'use_select_menu' => false,
            'show_assignment_label' => true,
            'assignment_label_text' => '課題用',
        ],
    ];
    $downloadConfig = $downloadConfigByPage[$page] ?? null;
    if (is_array($downloadConfig)) {
    $downloadFileName = (string)($downloadConfig['file_name'] ?? '');
    $downloadFilePath = '/curriculum/download/' . rawurlencode($downloadFileName);
    $downloadImagePath = '/curriculum/img/download.png';
    $downloadTargetSelector = (string)($downloadConfig['target_selector'] ?? '');
    $useSelectMenu = (($downloadConfig['use_select_menu'] ?? false) === true);
    $showAssignmentLabel = (($downloadConfig['show_assignment_label'] ?? false) === true);
    $assignmentLabelText = (string)($downloadConfig['assignment_label_text'] ?? '課題用');
    $downloadButtonStyle = <<<'CSS'
<style>
.pdf-download-control {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 999;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
}
.pdf-download-anchor {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: #fff;
    border: none;
    /* box-shadow: 0 10px 24px rgba(0, 0, 0, 0.2); */
    text-decoration: none;
    border: 1px solid #d5d5d5;
}


.pdf-download-assignment-label {
    margin: 0;
    color: #e0521d;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 0.02em;
    opacity: 0;
    animation: assignmentPulse 2.0s ease-in-out infinite;
}
.pdf-download-assignment-label.is-hidden {
  display: none;
  animation: none;
}
@keyframes assignmentPulse {
  0% {
    opacity: 0.3;
  }
  50% {
    opacity: 1;
  }
  100% {
    opacity: 0.3;
  }
}
.pdf-download-target {
  position: relative !important;
}
.pdf-download-anchor__image {
  width: 28px;
  height: auto;
  display: block;
}
.pdf-download-select {
  min-width: 220px;
  height: 38px;
  border-radius: 10px;
  border: 1px solid #d5d5d5;
  background-color: #fff;
  color: #272525;
  font-size: 14px;
  padding: 0 12px;
}
@media screen and (max-width: 768px) {
  .pdf-download-control {
      top: -35px;
      right: -15px;
  }
  .pdf-download-anchor {
      width: 40px;
      height: 40px;
  }
  .pdf-download-assignment-label {
      font-size: 15px;
  }
  .pdf-download-anchor__image{
      width: 20px ;
  }
}
</style>
CSS;
    $downloadButtonScript = <<<'SCRIPT'
<script>
(function () {
  const targetSelector = __TARGET_SELECTOR__;
  const targetClass = 'pdf-download-target';
  const controlClass = 'pdf-download-control';
  const anchorClass = 'pdf-download-anchor';
  const selectClass = 'pdf-download-select';
  const useSelectMenu = __USE_SELECT_MENU__;
  const showAssignmentLabel = __SHOW_ASSIGNMENT_LABEL__;
  const assignmentLabelText = __ASSIGNMENT_LABEL_TEXT__;
  const assignmentLabelClass = 'pdf-download-assignment-label';
  const downloadFileName = __DOWNLOAD_FILE_NAME__;
  let mutationObserver = null;
  let rafId = 0;

  const resolveCardRoot = () => {
    const card = document.querySelector(targetSelector);
    if (!(card instanceof HTMLElement)) {
      return null;
    }
    return card;
  };

  const ensureAnchor = (target) => {
    let anchor = target.querySelector(':scope > .' + controlClass + ' > .' + anchorClass);
    if (anchor) {
      return anchor;
    }
    anchor = document.createElement('a');
    anchor.className = anchorClass;
    anchor.href = __DOWNLOAD_FILE__;
    anchor.setAttribute('download', downloadFileName);

    const image = document.createElement('img');
    image.className = 'pdf-download-anchor__image';
    image.src = __DOWNLOAD_IMAGE__;
    image.alt = downloadFileName + 'をダウンロード';
    anchor.appendChild(image);
    return anchor;
  };

  const ensureControlContainer = (target) => {
    let control = target.querySelector(':scope > .' + controlClass);
    if (control) {
      return control;
    }
    control = document.createElement('div');
    control.className = controlClass;
    target.appendChild(control);
    return control;
  };

  const ensureAssignmentLabel = (target) => {
    let label = target.querySelector(':scope > .' + controlClass + ' > .' + assignmentLabelClass);
    if (label) {
      return label;
    }
    label = document.createElement('p');
    label.className = assignmentLabelClass + (showAssignmentLabel ? '' : ' is-hidden');
    label.textContent = assignmentLabelText;
    return label;
  };
  const triggerDownload = (url, fileName) => {
    const tempLink = document.createElement('a');
    tempLink.href = url;
    tempLink.setAttribute('download', fileName);
    document.body.appendChild(tempLink);
    tempLink.click();
    tempLink.remove();
  };
  const ensureSelectMenu = (target) => {
    let select = target.querySelector(':scope > .' + controlClass + ' > .' + selectClass);
    if (select) {
      return select;
    }
    select = document.createElement('select');
    select.className = selectClass;
    select.setAttribute('aria-label', 'ダウンロード操作');
    select.innerHTML = [
      '<option value="">操作を選択してください</option>',
      '<option value="open">ファイルを開く</option>',
      '<option value="download">ファイルをダウンロード</option>'
    ].join('');
    select.addEventListener('change', () => {
      const selectedValue = select.value;
      if (selectedValue === 'open') {
        window.open(__DOWNLOAD_FILE__, '_blank', 'noopener');
      } else if (selectedValue === 'download') {
        triggerDownload(__DOWNLOAD_FILE__, downloadFileName);
      }
      select.value = '';
    });
    return select;
  };

  const resolveTargetElements = (cardRoot) => {
    if (!(cardRoot instanceof HTMLElement)) {
      return [];
    }

    const layoutItems = Array.from(cardRoot.querySelectorAll('[data-node-view-content-inner="cardLayoutItem"]'))
      .filter((el) => el instanceof HTMLElement);
    if (layoutItems.length > 0) {
      return layoutItems;
    }
    return [cardRoot];
  };
  const inject = () => {
    const cardRoot = resolveCardRoot();
    if (!cardRoot || !cardRoot.isConnected) {
      return false;
    }

    const targetElements = resolveTargetElements(cardRoot);
    if (targetElements.length === 0) {
      return false;
    }

    let injectedCount = 0;
    targetElements.forEach((targetElement) => {
      if (!targetElement.isConnected) {
        return;
      }
      targetElement.classList.add(targetClass);
      const control = ensureControlContainer(targetElement);
      const assignmentLabel = ensureAssignmentLabel(targetElement);
      if (!control.contains(assignmentLabel)) {
        control.appendChild(assignmentLabel);
      }
      if (useSelectMenu) {
        const select = ensureSelectMenu(targetElement);
        if (!control.contains(select)) {
          control.appendChild(select);
        }
      } else {
        const anchor = ensureAnchor(targetElement);
        if (!control.contains(anchor)) {
          control.appendChild(anchor);
        }
      }
      injectedCount += 1;
    });

    return injectedCount > 0;
  };

  const teardown = () => {
    if (mutationObserver) {
      mutationObserver.disconnect();
      mutationObserver = null;
    }
    if (rafId) {
      window.cancelAnimationFrame(rafId);
      rafId = 0;
    }
  };

  const scheduleCheck = () => {
    if (rafId) {
      return;
    }
    rafId = window.requestAnimationFrame(() => {
      rafId = 0;
      inject();
    });
  };

  const initialize = () => {
    if (!document.body) {
      return;
    }

    if (!mutationObserver) {
      mutationObserver = new MutationObserver(() => scheduleCheck());
      mutationObserver.observe(document.body, { childList: true, subtree: true });
    }
    scheduleCheck();
  };
  window.addEventListener('beforeunload', teardown, { once: true });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
</script>
SCRIPT;
    $downloadButtonScript = str_replace(
        ['__DOWNLOAD_FILE__', '__DOWNLOAD_IMAGE__', '__TARGET_SELECTOR__', '__USE_SELECT_MENU__', '__DOWNLOAD_FILE_NAME__', '__SHOW_ASSIGNMENT_LABEL__', '__ASSIGNMENT_LABEL_TEXT__'],
        [
            json_encode($downloadFilePath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($downloadImagePath, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($downloadTargetSelector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $useSelectMenu ? 'true' : 'false',
            json_encode($downloadFileName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $showAssignmentLabel ? 'true' : 'false',
            json_encode($assignmentLabelText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
        $downloadButtonScript
    );
    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $downloadButtonStyle . "\n</head>", $html, 1) ?? $html;
    } else {
        $html = $downloadButtonStyle . $html;
    }

    if (stripos($html, '</body>') !== false) {
        $html = preg_replace('/<\/body>/i', $downloadButtonScript . "\n</body>", $html, 1) ?? $html;
    } else {
        $html .= $downloadButtonScript;
    }
    }
}
$loginEmailForClient = trim((string)($_SESSION['login_email'] ?? ''));
if ($loginEmailForClient !== '') {
    $clientUserScript = '<script>window.__LOGIN_USER_EMAIL__ = ' . json_encode($loginEmailForClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
    if (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $clientUserScript . "\n</head>", $html, 1) ?? $html;
    } else {
        $html = $clientUserScript . $html;
    }
}
$loginRoleForClient = normalize_role($_SESSION['login_role'] ?? 'user');
$clientRoleScript = '<script>window.__LOGIN_USER_ROLE__ = ' . json_encode($loginRoleForClient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
if (stripos($html, '</head>') !== false) {
    $html = preg_replace('/<\/head>/i', $clientRoleScript . "\n</head>", $html, 1) ?? $html;
} else {
    $html = $clientRoleScript . $html;
}
echo $html;
