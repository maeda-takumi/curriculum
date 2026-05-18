<?php

declare(strict_types=1);

require_once __DIR__ . '/../login/feedback_auth.php';
require_once __DIR__ . '/../login/feedback_store.php';

$currentUser = require_feedback_user();
$users = load_users();
$feedbacks = load_feedbacks();
$curriculumOptions = feedback_curriculum_options();
$isAdmin = normalize_role($currentUser['role'] ?? 'user') === 'admin';
$currentUserId = (int)($currentUser['id'] ?? 0);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$visibleFeedbacks = array_values(array_filter(
    $feedbacks,
    static fn(array $feedback): bool => $isAdmin || (int)$feedback['user_id'] === $currentUserId
));

$groupedFeedbacks = [];
foreach ($curriculumOptions as $curriculumKey => $_) {
    $groupedFeedbacks[$curriculumKey] = [];
}
foreach ($visibleFeedbacks as $feedback) {
    $curriculum = feedback_normalize_curriculum($feedback['curriculum'] ?? 'practice');
    $groupedFeedbacks[$curriculum][] = $feedback;
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/feedback/index.php'));
$appBase = '';
$markerPos = strpos($scriptName, '/feedback/');
if ($markerPos !== false) {
    $appBase = substr($scriptName, 0, $markerPos);
}
$viewBasePath = ($appBase === '' ? '' : $appBase) . '/feedback/view.php';
$adminPath = ($appBase === '' ? '' : $appBase) . '/login/feedback_admin.php';
$homePath = ($appBase === '' ? '' : $appBase) . '/?page=index';

/**
 * @param array<int, array<string, mixed>> $users
 */
function feedback_user_label_for_index(array $users, int $userId): string
{
    $user = find_feedback_user($users, $userId);
    if ($user === null) {
        return 'ユーザ不明 #' . $userId;
    }

    $lineName = (string)($user['line_name'] ?? '');
    $email = (string)($user['email'] ?? '');

    return trim($lineName . ' / ' . $email, ' /');
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>フィードバック目次</title>
  <link rel="stylesheet" href="../login/styke.css?v=<?= time() ?>">
</head>
<body>
  <header class="header">
    <div class="header-inner">
      <strong>フィードバック目次</strong>
      <nav class="nav">
        <a href="<?= h($homePath) ?>">カリキュラム</a>
        <?php if ($isAdmin): ?><a href="<?= h($adminPath) ?>">フィードバック管理</a><?php endif; ?>
        <a href="../login/logout.php">Logout</a>
      </nav>
    </div>
  </header>

  <main class="wrap admin-wrap">
    <section class="card admin-card">
      <div class="title-row">
        <div>
          <h1>フィードバック目次</h1>
          <p class="muted">閲覧したいフィードバックを選択してください。URLボタンで閲覧ページのURLをコピーできます。</p>
        </div>
      </div>

      <?php if ($visibleFeedbacks === []): ?>
        <div class="notice">閲覧できるフィードバックはまだありません。</div>
      <?php else: ?>
        <?php foreach ($groupedFeedbacks as $curriculumKey => $items): ?>
          <?php if ($items === []) { continue; } ?>
          <section class="feedback-section">
            <h2><?= h($curriculumOptions[$curriculumKey] ?? $curriculumKey) ?></h2>
            <div class="feedback-list">
              <?php foreach ($items as $feedback): ?>
                <?php
                $viewPath = $viewBasePath . '?id=' . rawurlencode((string)$feedback['id']);
                $viewUrl = feedback_absolute_url($viewPath);
                ?>
                <article class="feedback-item">
                  <div>
                    <h3><?= h((string)$feedback['name']) ?></h3>
                    <p class="muted">
                      <?php if ($isAdmin): ?><?= h(feedback_user_label_for_index($users, (int)$feedback['user_id'])) ?> / <?php endif; ?>
                      <?= h((string)$feedback['created_at']) ?>
                    </p>
                  </div>
                  <div class="feedback-actions">
                    <a class="btn btn-sub btn-inline" href="<?= h($viewPath) ?>">閲覧</a>
                    <button type="button" class="btn-inline js-copy-url" data-url="<?= h($viewUrl) ?>">URL</button>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </main>

  <script>
    (() => {
      document.querySelectorAll('.js-copy-url').forEach((button) => {
        button.addEventListener('click', async () => {
          const url = button.getAttribute('data-url') || '';
          if (!url) {
            return;
          }

          try {
            await navigator.clipboard.writeText(url);
            button.textContent = 'コピー済み';
            window.setTimeout(() => { button.textContent = 'URL'; }, 1600);
          } catch (error) {
            window.prompt('URLをコピーしてください', url);
          }
        });
      });
    })();
  </script>
</body>
</html>
