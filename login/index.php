<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/users_store.php';
require_once __DIR__ . '/login_audit.php';
$error = '';

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/login/index.php');
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$appBase = str_replace('\\', '/', dirname($scriptDir));
if ($appBase === '.' || $appBase === '/') {
    $appBase = '';
}
$defaultNext = ($appBase === '' ? '' : rtrim($appBase, '/')) . '/?page=index';
function normalize_next_path(mixed $candidate, string $defaultNext, string $appBase): string
{
    if (!is_string($candidate) || $candidate === '' || str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://') || str_starts_with($candidate, '//')) {
        return $defaultNext;
    }

    $parts = parse_url($candidate);
    if ($parts === false) {
        return $defaultNext;
    }

    $path = (string)($parts['path'] ?? '/');
    $path = str_starts_with($path, '/') ? $path : '/' . ltrim($path, '/');

    if (str_ends_with($path, '/login/index.php')) {
        return $defaultNext;
    }

    if ($appBase !== '' && !($path === $appBase || str_starts_with($path, $appBase . '/'))) {
        $path = rtrim($appBase, '/') . $path;
    }

    $query = (string)($parts['query'] ?? '');
    return $path . ($query !== '' ? '?' . $query : '');
}

$next = normalize_next_path($_GET['next'] ?? null, $defaultNext, $appBase);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $next = normalize_next_path($_POST['next'] ?? null, $defaultNext, $appBase);
}
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $matchedEmail = false;
    $matchedPasswordForEmail = false;
    $activeAccount = false;
    foreach (load_users() as $user) {
        $nameMatches = hash_equals((string)($user['email'] ?? ''), $email);
        $passMatches = password_verify($password, (string)$user['password_hash']);
        $isActive = ((string)($user['status'] ?? 'inactive')) === 'active';
        if ($nameMatches) {
            $matchedEmail = true;
            $matchedPasswordForEmail = $matchedPasswordForEmail || $passMatches;
            $activeAccount = $activeAccount || $isActive;
        }
        if ($nameMatches && $passMatches && $isActive) {
            write_login_audit_log('success', $email, $password, [
                'reason' => 'authenticated',
            ]);
            $_SESSION['logged_in'] = true;
            $_SESSION['login_user'] = (string)($user['line_name'] ?? ($user['email'] ?? ''));
            $_SESSION['login_email'] = (string)($user['email'] ?? '');
            $_SESSION['login_role'] = normalize_role($user['role'] ?? 'user');
            header('Location: ' . $next);
            exit;
        }
    }

    $reason = 'invalid_credentials';
    if (!$matchedEmail) {
        $reason = 'email_not_found';
    } elseif (!$activeAccount) {
        $reason = 'inactive_account';
    } elseif (!$matchedPasswordForEmail) {
        $reason = 'password_mismatch';
    }
    write_login_audit_log('failure', $email, $password, [
        'reason' => $reason,
    ]);
    $error = 'ログイン情報が正しくありません。';
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ログイン</title>
  <link rel="stylesheet" href="styke.css?v=<?= time() ?>">
</head>
<body class="login-page">
  <form class="card login-card" method="post">
    <h1>ログイン</h1>
    <p class="muted">登録ユーザ情報を入力してください。</p>
    <?php if ($error !== ''): ?>
      <div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <label>
      メールアドレス
      <input type="email" name="email" required>
    </label>
    <label>
      パスワード
      <input type="password" name="password" required>
    </label>
    <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">ログイン</button>
  </form>
</body>
</html>
