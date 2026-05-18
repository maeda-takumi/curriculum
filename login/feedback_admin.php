<?php

declare(strict_types=1);

require_once __DIR__ . '/feedback_auth.php';
require_once __DIR__ . '/feedback_store.php';

require_feedback_admin();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';
$users = load_users();
$feedbacks = load_feedbacks();
$curriculumOptions = feedback_curriculum_options();

if (isset($_GET['message']) && is_string($_GET['message'])) {
    $message = $_GET['message'];
}
if (isset($_GET['error']) && is_string($_GET['error'])) {
    $error = $_GET['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_feedback') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $curriculum = feedback_normalize_curriculum($_POST['curriculum'] ?? 'practice');
        $name = trim((string)($_POST['name'] ?? ''));
        $targetUser = find_feedback_user($users, $userId);
        $uploadedFile = $_FILES['html_file'] ?? null;

        if ($targetUser === null) {
            $error = '対象ユーザを選択してください。';
        } elseif ($name === '') {
            $error = 'フィードバック名を入力してください。';
        } elseif (!is_array($uploadedFile) || (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'HTMLファイルをアップロードしてください。';
        } else {
            $originalName = (string)($uploadedFile['name'] ?? '');
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $tmpName = (string)($uploadedFile['tmp_name'] ?? '');

            if (!in_array($extension, ['html', 'htm'], true)) {
                $error = 'HTMLファイル（.html / .htm）のみアップロードできます。';
            } elseif ($tmpName === '' || !is_uploaded_file($tmpName)) {
                $error = 'アップロードファイルを確認できませんでした。';
            } else {
                $feedbackId = next_feedback_id($feedbacks);
                $htmlFile = 'feedback_' . $feedbackId . '.html';
                $uploadDir = feedback_upload_directory();
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $error = 'アップロード先ディレクトリを作成できませんでした。';
                } elseif (!move_uploaded_file($tmpName, feedback_upload_path($htmlFile))) {
                    $error = 'HTMLファイルの保存に失敗しました。';
                } else {
                    $now = gmdate('c');
                    $feedbacks[] = [
                        'id' => $feedbackId,
                        'user_id' => $userId,
                        'curriculum' => $curriculum,
                        'name' => $name,
                        'html_file' => $htmlFile,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    save_feedbacks($feedbacks);
                    header('Location: feedback_admin.php?message=' . rawurlencode('フィードバックをアップロードしました。'));
                    exit;
                }
            }
        }
    } elseif ($action === 'delete_feedback') {
        $feedbackId = (int)($_POST['feedback_id'] ?? 0);
        $nextFeedbacks = [];
        $deleted = false;
        foreach ($feedbacks as $feedback) {
            if ((int)$feedback['id'] === $feedbackId) {
                $deleted = true;
                $path = feedback_upload_path((string)$feedback['html_file']);
                if (is_file($path)) {
                    unlink($path);
                }
                continue;
            }
            $nextFeedbacks[] = $feedback;
        }

        if ($deleted) {
            save_feedbacks($nextFeedbacks);
            header('Location: feedback_admin.php?message=' . rawurlencode('フィードバックを削除しました。'));
            exit;
        }
        $error = '削除対象が見つかりませんでした。';
    }

    $feedbacks = load_feedbacks();
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/login/feedback_admin.php'));
$appBase = '';
$markerPos = strpos($scriptName, '/login/');
if ($markerPos !== false) {
    $appBase = substr($scriptName, 0, $markerPos);
}
$feedbackIndexPath = ($appBase === '' ? '' : $appBase) . '/feedback/index.php';

/**
 * @param array<int, array<string, mixed>> $users
 */
function feedback_admin_user_label(array $users, int $userId): string
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
  <title>フィードバック管理</title>
  <link rel="stylesheet" href="styke.css?v=<?= time() ?>">
</head>
<body>
  <header class="header">
    <div class="header-inner">
      <strong>フィードバック管理画面</strong>
      <nav class="nav">
        <a href="admin.php">ユーザ管理</a>
        <a href="article_editor.php">記事編集</a>
        <a href="login_attempts.php">ログイン試行ログ</a>
        <a href="<?= h($feedbackIndexPath) ?>">フィードバック目次</a>
        <a href="index.php">Login</a>
      </nav>
    </div>
  </header>

  <main class="wrap admin-wrap">
    <section class="card admin-card">
      <div class="title-row">
        <div>
          <h1>フィードバック管理</h1>
          <p class="muted">管理者が生成したフルHTMLファイルを、ユーザ別・カリキュラム別にアップロードできます。</p>
        </div>
      </div>

      <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="card-sub feedback-upload-form">
        <input type="hidden" name="action" value="create_feedback">
        <div class="feedback-form-grid">
          <label>対象ユーザ
            <select name="user_id" required>
              <option value="">選択してください</option>
              <?php foreach ($users as $user): ?>
                <?php if (normalize_status($user['status'] ?? 'inactive') !== 'active') { continue; } ?>
                <option value="<?= h((string)$user['id']) ?>"><?= h((string)($user['line_name'] ?? '')) ?> / <?= h((string)($user['email'] ?? '')) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>カリキュラム
            <select name="curriculum" required>
              <?php foreach ($curriculumOptions as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>フィードバック名
            <input type="text" name="name" required placeholder="例: Week1 フィードバック">
          </label>
          <label>HTMLファイル
            <input type="file" name="html_file" accept=".html,.htm,text/html" required>
          </label>
        </div>
        <button type="submit" class="btn-inline">アップロード</button>
      </form>

      <h2>アップロード済みフィードバック</h2>
      <?php if ($feedbacks === []): ?>
        <div class="notice">アップロード済みのフィードバックはありません。</div>
      <?php else: ?>
        <div class="feedback-list">
          <?php foreach ($feedbacks as $feedback): ?>
            <?php
            $viewPath = $feedbackIndexPath === '/feedback/index.php'
                ? '/feedback/view.php?id=' . rawurlencode((string)$feedback['id'])
                : dirname($feedbackIndexPath) . '/view.php?id=' . rawurlencode((string)$feedback['id']);
            $viewUrl = feedback_absolute_url($viewPath);
            ?>
            <article class="feedback-item">
              <div>
                <h3><?= h((string)$feedback['name']) ?></h3>
                <p class="muted">
                  <?= h(feedback_admin_user_label($users, (int)$feedback['user_id'])) ?> / <?= h($curriculumOptions[$feedback['curriculum']] ?? $feedback['curriculum']) ?> / <?= h((string)$feedback['created_at']) ?>
                </p>
              </div>
              <div class="feedback-actions">
                <a class="btn btn-sub btn-inline" href="<?= h($viewPath) ?>" target="_blank" rel="noopener">閲覧</a>
                <button type="button" class="btn-inline js-copy-url" data-url="<?= h($viewUrl) ?>">URL</button>
                <form method="post" onsubmit="return confirm('このフィードバックを削除しますか？');">
                  <input type="hidden" name="action" value="delete_feedback">
                  <input type="hidden" name="feedback_id" value="<?= h((string)$feedback['id']) ?>">
                  <button type="submit" class="btn-danger btn-inline">削除</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
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
