<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function redirect_to_login(): void
{
    $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    $basePath = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
    $loginPath = $basePath . '/login/index.php';
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $nextPath = ($basePath !== '' ? $basePath : '') . '/?page=index';
    if ($requestUri !== '' && str_starts_with($requestUri, ($basePath !== '' ? $basePath : '/'))) {
        $nextPath = $requestUri;
    }

    header('Location: ' . $loginPath . '?next=' . rawurlencode($nextPath));
    exit;
}

if (empty($_SESSION['logged_in'])) {
    redirect_to_login();
}

require_once __DIR__ . '/login/users_store.php';

$loginEmail = trim((string)($_SESSION['login_email'] ?? ''));
if ($loginEmail === '') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, (string)($params['path'] ?? '/'), (string)($params['domain'] ?? ''), (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? false));
    }
    session_destroy();
    redirect_to_login();
}

$isActiveUser = false;
$loginRole = 'user';
foreach (load_users() as $user) {
    $email = trim((string)($user['email'] ?? ''));
    $status = (string)($user['status'] ?? 'inactive');
    if ($email !== '' && hash_equals($email, $loginEmail) && $status === 'active') {
        $isActiveUser = true;
        $loginRole = normalize_role($user['role'] ?? 'user');
        break;
    }
}

if (!$isActiveUser) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, (string)($params['path'] ?? '/'), (string)($params['domain'] ?? ''), (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? false));
    }
    session_destroy();
    redirect_to_login();
}
$_SESSION['login_role'] = $loginRole;