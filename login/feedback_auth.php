
<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/users_store.php';

function feedback_login_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/login/index.php'));
    $appBase = '';
    foreach (['/login/', '/feedback/'] as $marker) {
        $pos = strpos($scriptName, $marker);
        if ($pos !== false) {
            $appBase = substr($scriptName, 0, $pos);
            break;
        }
    }

    return ($appBase === '' ? '' : $appBase) . '/login/index.php';
}

function feedback_redirect_to_login(): void
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . feedback_login_path() . '?next=' . rawurlencode($requestUri));
    exit;
}

function current_feedback_user(): ?array
{
    if (empty($_SESSION['logged_in'])) {
        return null;
    }

    $loginEmail = trim((string)($_SESSION['login_email'] ?? ''));
    if ($loginEmail === '') {
        return null;
    }

    foreach (load_users() as $user) {
        $email = trim((string)($user['email'] ?? ''));
        $status = (string)($user['status'] ?? 'inactive');
        if ($email !== '' && hash_equals($email, $loginEmail) && $status === 'active') {
            $_SESSION['login_role'] = normalize_role($user['role'] ?? 'user');
            return $user;
        }
    }

    return null;
}

function require_feedback_user(): array
{
    $user = current_feedback_user();
    if ($user === null) {
        feedback_redirect_to_login();
    }

    return $user;
}

function require_feedback_admin(): array
{
    $user = require_feedback_user();
    if (normalize_role($user['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        echo '403 Forbidden';
        exit;
    }

    return $user;
}
