<?php

declare(strict_types=1);

require_once __DIR__ . '/login_audit.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$logPath = login_audit_file_path();
$rows = [];
$error = '';

if (is_file($logPath)) {
    $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $error = 'ログファイルの読み込みに失敗しました。';
    } else {
        foreach ($lines as $lineNumber => $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                $rows[] = [
                    'line' => $lineNumber + 1,
                    'timestamp' => '-',
                    'result' => 'invalid_json',
                    'email_input' => '-',
                    'reason' => '-',
                    'raw' => $line,
                ];
                continue;
            }

            $rows[] = [
                'line' => $lineNumber + 1,
                'timestamp' => (string)($decoded['timestamp'] ?? '-'),
                'result' => (string)($decoded['result'] ?? '-'),
                'email_input' => (string)($decoded['email_input'] ?? '-'),
                'reason' => (string)($decoded['context']['reason'] ?? '-'),
                'raw' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        $rows = array_reverse($rows);
    }
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ログイン試行ログ</title>
  <link rel="stylesheet" href="styke.css?v=<?= time() ?>">
</head>
<body>
  <header class="header">
    <div class="header-inner">
      <strong>ログイン管理画面</strong>
      <nav class="nav">
        <a href="admin.php">ユーザ管理</a>
        <a href="article_editor.php">記事編集</a>
        <a href="index.php">Login</a>
      </nav>
    </div>
  </header>

  <main class="wrap admin-wrap">
    <section class="card admin-card">
      <h1>login_attempts.jsonl</h1>
      <p class="muted">保存先: <?= h($logPath) ?></p>

      <?php if ($error !== ''): ?>
        <div class="notice error"><?= h($error) ?></div>
      <?php elseif (!is_file($logPath)): ?>
        <div class="notice">ログファイルがまだ作成されていません。</div>
      <?php elseif ($rows === []): ?>
        <div class="notice">ログファイルは存在しますが、表示できるデータがありません。</div>
      <?php else: ?>
        <div class="table-wrap table-wrap-log">
          <table class="table table-log-attempts">
            <thead>
              <tr>
                <th>Line</th>
                <th>Timestamp (UTC)</th>
                <th>Result</th>
                <th>Email</th>
                <th>Reason</th>
                <th>Raw JSON</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td class="log-col-line"><?= h((string)$row['line']) ?></td>
                  <td class="log-col-time"><?= h((string)$row['timestamp']) ?></td>
                  <td class="log-col-result"><?= h((string)$row['result']) ?></td>
                  <td class="log-col-email"><?= h((string)$row['email_input']) ?></td>
                  <td class="log-col-reason"><?= h((string)$row['reason']) ?></td>
                  <td class="log-col-raw"><code><?= h((string)$row['raw']) ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
