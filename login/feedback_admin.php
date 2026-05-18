<?php

declare(strict_types=1);

require_once __DIR__ . '/feedback_auth.php';
require_once __DIR__ . '/feedback_store.php';

require_feedback_admin();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

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
    $realName = (string)($user['real_name'] ?? '');
    $email = (string)($user['email'] ?? '');
    $parts = array_values(array_filter([$lineName, $realName, $email], static fn(string $part): bool => $part !== ''));

    return $parts === [] ? 'ユーザ #' . $userId : implode(' / ', $parts);
}

function feedback_admin_redirect(int $userId, string $curriculum, string $message = '', string $error = ''): void
{
    $params = [];
    if ($userId > 0) {
        $params['user_id'] = (string)$userId;
    }
    $params['curriculum'] = feedback_normalize_curriculum($curriculum);
    if ($message !== '') {
        $params['message'] = $message;
    }
    if ($error !== '') {
        $params['error'] = $error;
    }

    header('Location: feedback_admin.php?' . http_build_query($params));
    exit;
}

function feedback_admin_uploaded_html_file(mixed $uploadedFile, int $feedbackId, ?string &$error): ?string
{
    if (!is_array($uploadedFile) || (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int)($uploadedFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $error = 'HTMLファイルをアップロードしてください。';
        return null;
    }

    $originalName = (string)($uploadedFile['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $tmpName = (string)($uploadedFile['tmp_name'] ?? '');

    if (!in_array($extension, ['html', 'htm'], true)) {
        $error = 'HTMLファイル（.html / .htm）のみアップロードできます。';
        return null;
    }
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $error = 'アップロードファイルを確認できませんでした。';
        return null;
    }

    $htmlFile = 'feedback_' . $feedbackId . '.html';
    $uploadDir = feedback_upload_directory();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        $error = 'アップロード先ディレクトリを作成できませんでした。';
        return null;
    }
    if (!move_uploaded_file($tmpName, feedback_upload_path($htmlFile))) {
        $error = 'HTMLファイルの保存に失敗しました。';
        return null;
    }

    return $htmlFile;
}
$message = '';
$error = '';
$users = load_users();
$feedbacks = load_feedbacks();
$curriculumOptions = feedback_curriculum_options();
$selectedUserId = (int)($_GET['user_id'] ?? 0);
$selectedCurriculum = feedback_normalize_curriculum($_GET['curriculum'] ?? 'practice');
$lineNameQuery = trim((string)($_GET['line_name'] ?? ''));
$realNameQuery = trim((string)($_GET['real_name'] ?? ''));
$emailQuery = trim((string)($_GET['email'] ?? ''));

if (isset($_GET['message']) && is_string($_GET['message'])) {
    $message = $_GET['message'];
}
if (isset($_GET['error']) && is_string($_GET['error'])) {
    $error = $_GET['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $curriculum = feedback_normalize_curriculum($_POST['curriculum'] ?? 'practice');

    if ($action === 'create_feedback') {
        $name = trim((string)($_POST['name'] ?? ''));
        $targetUser = find_feedback_user($users, $userId);
        $feedbackId = next_feedback_id($feedbacks);
        $uploadError = null;
        $htmlFile = feedback_admin_uploaded_html_file($_FILES['html_file'] ?? null, $feedbackId, $uploadError);

        if ($targetUser === null) {
            $error = '対象ユーザを選択してください。';
        } elseif ($name === '') {
            $error = 'フィードバックタイトルを入力してください。';
        } elseif ($htmlFile === null) {
            $error = $uploadError ?? 'HTMLファイルをアップロードしてください。';
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
            feedback_admin_redirect($userId, $curriculum, 'フィードバックを追加しました。');
        }
    } elseif ($action === 'update_feedback') {
        $feedbackId = (int)($_POST['feedback_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $updated = false;

        if ($name === '') {
            $error = 'フィードバックタイトルを入力してください。';
        } else {
            foreach ($feedbacks as $index => $feedback) {
                if ((int)$feedback['id'] !== $feedbackId) {
                    continue;
                }

                $userId = (int)$feedback['user_id'];
                $uploadError = null;
                $htmlFile = feedback_admin_uploaded_html_file($_FILES['html_file'] ?? null, $feedbackId, $uploadError);
                if ($uploadError !== null) {
                    $error = $uploadError;
                    break;
                }

                $feedbacks[$index]['name'] = $name;
                $feedbacks[$index]['curriculum'] = $curriculum;
                if ($htmlFile !== null) {
                    $feedbacks[$index]['html_file'] = $htmlFile;
                }
                $feedbacks[$index]['updated_at'] = gmdate('c');
                $updated = true;
                break;
            }
        }
        if ($updated) {
            save_feedbacks($feedbacks);
            feedback_admin_redirect($userId, $curriculum, 'フィードバックを更新しました。');
        } elseif ($error === '') {
            $error = '更新対象が見つかりませんでした。';
        }
    } elseif ($action === 'delete_feedback') {
        $feedbackId = (int)($_POST['feedback_id'] ?? 0);
        $nextFeedbacks = [];
        $deleted = false;
        foreach ($feedbacks as $feedback) {
            if ((int)$feedback['id'] === $feedbackId) {
                $deleted = true;
                $userId = (int)$feedback['user_id'];
                $curriculum = feedback_normalize_curriculum($feedback['curriculum'] ?? $curriculum);
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
            feedback_admin_redirect($userId, $curriculum, 'フィードバックを削除しました。');
        }
        $error = '削除対象が見つかりませんでした。';
    }

    $feedbacks = load_feedbacks();
    $selectedUserId = $userId > 0 ? $userId : $selectedUserId;
    $selectedCurriculum = $curriculum;
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/login/feedback_admin.php'));
$appBase = '';
$markerPos = strpos($scriptName, '/login/');
if ($markerPos !== false) {
    $appBase = substr($scriptName, 0, $markerPos);
}
$feedbackIndexPath = ($appBase === '' ? '' : $appBase) . '/feedback/index.php';
$viewBasePath = dirname($feedbackIndexPath) . '/view.php';
$selectedUser = $selectedUserId > 0 ? find_feedback_user($users, $selectedUserId) : null;

$filteredUsers = array_values(array_filter($users, static function (array $user) use ($lineNameQuery, $realNameQuery, $emailQuery): bool {
    if ($lineNameQuery !== '' && stripos((string)($user['line_name'] ?? ''), $lineNameQuery) === false) {
        return false;
    }
    if ($realNameQuery !== '' && stripos((string)($user['real_name'] ?? ''), $realNameQuery) === false) {
        return false;
    }
    if ($emailQuery !== '' && stripos((string)($user['email'] ?? ''), $emailQuery) === false) {
        return false;
    }

    return true;
}));

$selectedFeedbacks = array_values(array_filter($feedbacks, static fn(array $feedback): bool =>
    (int)$feedback['user_id'] === $selectedUserId
    && feedback_normalize_curriculum($feedback['curriculum'] ?? 'practice') === $selectedCurriculum
));
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
          <p class="muted">ユーザを検索して設定ボタンを押すと、ユーザ別・カリキュラム別のフィードバックを管理できます。</p>
        </div>
      </div>

      <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

      <section class="card-sub feedback-user-search">
        <h2>ユーザー検索</h2>
        <form method="get" class="feedback-search-grid">
          <label>LINE名検索
            <input type="search" name="line_name" value="<?= h($lineNameQuery) ?>" placeholder="LINE名で検索">
          </label>
          <label>本名検索
            <input type="search" name="real_name" value="<?= h($realNameQuery) ?>" placeholder="本名で検索">
          </label>
          <label>メールアドレス検索
            <input type="search" name="email" value="<?= h($emailQuery) ?>" placeholder="メールアドレスで検索">
          </label>
          <div class="feedback-search-actions">
            <button type="submit" class="btn-inline">検索</button>
            <a class="btn btn-sub btn-inline" href="feedback_admin.php">クリア</a>
          </div>
        </form>
      </section>

      <section class="feedback-section">
        <div class="title-row">
          <div>
            <h2>ユーザ一覧</h2>
            <p class="result-meta"><?= h((string)count($filteredUsers)) ?>件表示中</p>
          </div>
        </div>
        <?php if ($filteredUsers === []): ?>
          <div class="notice">条件に一致するユーザはありません。</div>
        <?php else: ?>
          <div class="feedback-user-list">
            <?php foreach ($filteredUsers as $user): ?>
              <article class="feedback-user-item<?= (int)$user['id'] === $selectedUserId ? ' is-selected' : '' ?>">
                <div>
                  <h3><?= h((string)($user['line_name'] ?? '')) ?></h3>
                  <p class="muted">本名: <?= h((string)($user['real_name'] ?? '')) ?> / メール: <?= h((string)($user['email'] ?? '')) ?></p>
                </div>
                <a class="btn btn-inline" href="feedback_admin.php?user_id=<?= h((string)$user['id']) ?>&curriculum=<?= h($selectedCurriculum) ?>">設定</a>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="feedback-section">
        <div class="feedback-manager-head">
          <div>
            <h2>フィードバック一覧</h2>
            <p class="muted">
              <?php if ($selectedUser === null): ?>
                設定ボタンから管理するユーザを選択してください。
              <?php else: ?>
                <?= h(feedback_admin_user_label($users, $selectedUserId)) ?> のフィードバックを管理中です。
              <?php endif; ?>
            </p>
          </div>
          <?php if ($selectedUser !== null): ?>
            <button type="button" class="btn-inline js-open-feedback-modal" data-mode="create">追加</button>
          <?php endif; ?>
        </div>

        <?php if ($selectedUser !== null): ?>
          <form method="get" class="feedback-curriculum-form">
            <input type="hidden" name="user_id" value="<?= h((string)$selectedUserId) ?>">
            <label>カリキュラム切り替え
              <select name="curriculum" onchange="this.form.submit()">
                <?php foreach ($curriculumOptions as $key => $label): ?>
                  <option value="<?= h($key) ?>"<?= $key === $selectedCurriculum ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </form>

          <?php if ($selectedFeedbacks === []): ?>
            <div class="notice">このカリキュラムのフィードバックはまだありません。</div>
          <?php else: ?>
            <div class="feedback-list">
              <?php foreach ($selectedFeedbacks as $feedback): ?>
                <?php $viewPath = $viewBasePath . '?id=' . rawurlencode((string)$feedback['id']); ?>
                <article class="feedback-item">
                  <div>
                    <h3><?= h((string)$feedback['name']) ?></h3>
                    <p class="muted"><?= h((string)$feedback['updated_at']) ?></p>
                  </div>
                  <div class="feedback-actions">
                    <a class="btn btn-sub btn-inline" href="<?= h($viewPath) ?>" target="_blank" rel="noopener">閲覧</a>
                    <button
                      type="button"
                      class="btn-inline js-open-feedback-modal"
                      data-mode="edit"
                      data-feedback-id="<?= h((string)$feedback['id']) ?>"
                      data-name="<?= h((string)$feedback['name']) ?>"
                      data-curriculum="<?= h(feedback_normalize_curriculum($feedback['curriculum'] ?? 'practice')) ?>"
                    >編集</button>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </section>
  </main>

  <dialog class="modal feedback-modal" id="feedback-modal">
    <div class="modal-header">
      <strong id="feedback-modal-title">フィードバック追加</strong>
      <button type="button" class="text-btn js-close-feedback-modal">閉じる</button>
    </div>
    <form method="post" enctype="multipart/form-data" class="feedback-upload-form" id="feedback-form">
      <input type="hidden" name="action" id="feedback-action" value="create_feedback">
      <input type="hidden" name="feedback_id" id="feedback-id" value="">
      <input type="hidden" name="user_id" value="<?= h((string)$selectedUserId) ?>">
      <div class="grid">
        <label>カリキュラム
          <select name="curriculum" id="feedback-curriculum" required>
            <?php foreach ($curriculumOptions as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= $key === $selectedCurriculum ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>フィードバックタイトル
          <input type="text" name="name" id="feedback-name" required placeholder="例: Week1 フィードバック">
        </label>
        <label>HTMLファイル
          <input type="file" name="html_file" id="feedback-html-file" accept=".html,.htm,text/html">
          <span class="form-note" id="feedback-file-note">追加時はHTMLファイルが必須です。</span>
        </label>
      </div>
      <div class="feedback-modal-actions">
        <button type="submit" class="btn-inline" id="feedback-submit">追加する</button>
        <button type="submit" form="feedback-delete-form" class="btn-danger btn-inline" id="feedback-delete-button">削除</button>
      </div>
    </form>
    <form method="post" id="feedback-delete-form" onsubmit="return confirm('このフィードバックを削除しますか？');">
      <input type="hidden" name="action" value="delete_feedback">
      <input type="hidden" name="feedback_id" id="delete-feedback-id" value="">
      <input type="hidden" name="user_id" value="<?= h((string)$selectedUserId) ?>">
      <input type="hidden" name="curriculum" value="<?= h($selectedCurriculum) ?>">
    </form>
  </dialog>
  <script>
    (() => {
      const dialog = document.getElementById('feedback-modal');
      if (!dialog) {
        return;
      }

      const title = document.getElementById('feedback-modal-title');
      const action = document.getElementById('feedback-action');
      const feedbackId = document.getElementById('feedback-id');
      const deleteFeedbackId = document.getElementById('delete-feedback-id');
      const name = document.getElementById('feedback-name');
      const curriculum = document.getElementById('feedback-curriculum');
      const htmlFile = document.getElementById('feedback-html-file');
      const fileNote = document.getElementById('feedback-file-note');
      const submit = document.getElementById('feedback-submit');
      const deleteButton = document.getElementById('feedback-delete-button');
      const selectedCurriculum = <?= json_encode($selectedCurriculum, JSON_UNESCAPED_UNICODE) ?>;

      document.querySelectorAll('.js-open-feedback-modal').forEach((button) => {
        button.addEventListener('click', () => {
          const mode = button.getAttribute('data-mode') || 'create';
          htmlFile.value = '';

          if (mode === 'edit') {
            title.textContent = 'フィードバック編集';
            action.value = 'update_feedback';
            feedbackId.value = button.getAttribute('data-feedback-id') || '';
            deleteFeedbackId.value = feedbackId.value;
            name.value = button.getAttribute('data-name') || '';
            curriculum.value = button.getAttribute('data-curriculum') || selectedCurriculum;
            htmlFile.required = false;
            fileNote.textContent = 'HTMLファイルを選択すると既存ファイルを差し替えます。';
            submit.textContent = '更新する';
            deleteButton.hidden = false;
          } else {
            title.textContent = 'フィードバック追加';
            action.value = 'create_feedback';
            feedbackId.value = '';
            deleteFeedbackId.value = '';
            name.value = '';
            curriculum.value = selectedCurriculum;
            htmlFile.required = true;
            fileNote.textContent = '追加時はHTMLファイルが必須です。';
            submit.textContent = '追加する';
            deleteButton.hidden = true;
          }

          if (typeof dialog.showModal === 'function') {
            dialog.showModal();
          } else {
            dialog.setAttribute('open', 'open');
          }
        });
      });
      document.querySelectorAll('.js-close-feedback-modal').forEach((button) => {
        button.addEventListener('click', () => {
          dialog.close();
        });
      });
    })();
  </script>
</body>
</html>
