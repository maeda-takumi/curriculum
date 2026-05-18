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
function feedback_user_admin_label(array $users, int $userId): string
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

function feedback_user_admin_redirect(int $userId, string $curriculum, string $message = '', string $error = ''): void
{
    $params = [
        'user_id' => (string)$userId,
        'curriculum' => feedback_normalize_curriculum($curriculum),
    ];
    if ($message !== '') {
        $params['message'] = $message;
    }
    if ($error !== '') {
        $params['error'] = $error;
    }

    header('Location: feedback_user_admin.php?' . http_build_query($params));
    exit;
}

function feedback_user_admin_uploaded_html_file(mixed $uploadedFile, int $feedbackId, bool $required, ?string &$error): ?string
{
    if (!is_array($uploadedFile) || (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            $error = 'HTMLファイルをアップロードしてください。';
        }
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
    $targetUser = find_feedback_user($users, $userId);

    if ($targetUser === null) {
        $error = '対象ユーザを選択してください。';
    } elseif ($action === 'create_feedback') {
        $name = trim((string)($_POST['name'] ?? ''));
        $feedbackId = next_feedback_id($feedbacks);
        $uploadError = null;
        $htmlFile = feedback_user_admin_uploaded_html_file($_FILES['html_file'] ?? null, $feedbackId, true, $uploadError);

        if ($name === '') {
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
            feedback_user_admin_redirect($userId, $curriculum, 'フィードバックを追加しました。');
        }
    } elseif ($action === 'update_feedback') {
        $feedbackId = (int)($_POST['feedback_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $updated = false;

        if ($name === '') {
            $error = 'フィードバックタイトルを入力してください。';
        } else {
            foreach ($feedbacks as $index => $feedback) {
                if ((int)$feedback['id'] !== $feedbackId || (int)$feedback['user_id'] !== $userId) {
                    continue;
                }

                $uploadError = null;
                $htmlFile = feedback_user_admin_uploaded_html_file($_FILES['html_file'] ?? null, $feedbackId, false, $uploadError);
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
            feedback_user_admin_redirect($userId, $curriculum, 'フィードバックを更新しました。');
        } elseif ($error === '') {
            $error = '更新対象が見つかりませんでした。';
        }
    } elseif ($action === 'delete_feedback') {
        $feedbackId = (int)($_POST['feedback_id'] ?? 0);
        $nextFeedbacks = [];
        $deleted = false;
        foreach ($feedbacks as $feedback) {
            if ((int)$feedback['id'] === $feedbackId && (int)$feedback['user_id'] === $userId) {
                $deleted = true;
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
            feedback_user_admin_redirect($userId, $curriculum, 'フィードバックを削除しました。');
        }
        $error = '削除対象が見つかりませんでした。';
    }

    $feedbacks = load_feedbacks();
    $selectedUserId = $userId > 0 ? $userId : $selectedUserId;
    $selectedCurriculum = $curriculum;
}

$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/login/feedback_user_admin.php'));
$appBase = '';
$markerPos = strpos($scriptName, '/login/');
if ($markerPos !== false) {
    $appBase = substr($scriptName, 0, $markerPos);
}
$feedbackIndexPath = ($appBase === '' ? '' : $appBase) . '/feedback/index.php';
$viewBasePath = dirname($feedbackIndexPath) . '/view.php';
$selectedUser = $selectedUserId > 0 ? find_feedback_user($users, $selectedUserId) : null;

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
  <title>ユーザ別フィードバック設定</title>
  <link rel="stylesheet" href="styke.css?v=<?= time() ?>">
</head>
<body>
  <header class="header">
    <div class="header-inner">
      <strong>ユーザ別フィードバック設定</strong>
      <nav class="nav">
        <a href="feedback_admin.php">フィードバック管理</a>
        <a href="admin.php">ユーザ管理</a>
        <a href="<?= h($feedbackIndexPath) ?>">フィードバック目次</a>
        <a href="index.php">Login</a>
      </nav>
    </div>
  </header>

  <main class="wrap admin-wrap">
    <section class="card admin-card">
      <div class="title-row">
        <div>
          <h1>ユーザ別フィードバック設定</h1>
          <?php if ($selectedUser === null): ?>
            <p class="muted">フィードバック管理画面のユーザ一覧から、設定するユーザを選択してください。</p>
          <?php else: ?>
            <p class="muted"><?= h(feedback_user_admin_label($users, $selectedUserId)) ?> のフィードバックを管理します。</p>
          <?php endif; ?>
        </div>
        <a class="btn btn-sub btn-inline" href="feedback_admin.php">ユーザ一覧へ戻る</a>
      </div>

      <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

      <?php if ($selectedUser === null): ?>
        <div class="notice error">対象ユーザが見つかりませんでした。</div>
      <?php else: ?>
        <section class="card-sub feedback-user-summary">
          <h2>設定するカリキュラム</h2>
          <form method="get" class="feedback-curriculum-form">
            <input type="hidden" name="user_id" value="<?= h((string)$selectedUserId) ?>">
            <label>カリキュラムを選択
              <select name="curriculum" onchange="this.form.submit()">
                <?php foreach ($curriculumOptions as $key => $label): ?>
                  <option value="<?= h($key) ?>"<?= $key === $selectedCurriculum ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </form>
        </section>

        <section class="feedback-section">
          <div class="feedback-manager-head">
            <div>
              <h2>現在設定されているフィードバック情報</h2>
              <p class="muted"><?= h($curriculumOptions[$selectedCurriculum] ?? $selectedCurriculum) ?> に登録済みのHTMLを一覧表示しています。</p>
            </div>
            <button type="button" class="btn-inline js-open-feedback-modal" data-mode="create">追加</button>
          </div>

          <div class="notice feedback-url-notice" id="feedback-url-notice" hidden></div>

          <?php if ($selectedFeedbacks === []): ?>
            <div class="notice">このカリキュラムのフィードバックはまだありません。</div>
          <?php else: ?>
            <div class="feedback-list">
              <?php foreach ($selectedFeedbacks as $feedback): ?>
                <?php
                  $viewPath = $viewBasePath . '?id=' . rawurlencode((string)$feedback['id']);
                  $viewUrl = feedback_absolute_url($viewPath);
                ?>
                <article class="feedback-item">
                  <div>
                    <h3><?= h((string)$feedback['name']) ?></h3>
                    <p class="muted">更新日時: <?= h((string)$feedback['updated_at']) ?></p>
                  </div>
                  <div class="feedback-actions">
                    <button type="button" class="btn btn-sub btn-inline js-copy-feedback-url" data-url="<?= h($viewUrl) ?>">URL</button>
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
        </section>
      <?php endif; ?>
    </section>
  </main>

  <?php if ($selectedUser !== null): ?>
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
          <label>タイトル
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
  <?php endif; ?>

  <script>
    (() => {
      const urlNotice = document.getElementById('feedback-url-notice');

      const showUrl = (url, copied) => {
        if (!urlNotice) {
          return;
        }
        urlNotice.hidden = false;
        urlNotice.textContent = copied ? `URLをクリップボードにコピーしました: ${url}` : `URL: ${url}`;
      };

      document.querySelectorAll('.js-copy-feedback-url').forEach((button) => {
        button.addEventListener('click', async () => {
          const url = button.getAttribute('data-url') || '';
          if (url === '') {
            return;
          }

          if (navigator.clipboard && window.isSecureContext) {
            try {
              await navigator.clipboard.writeText(url);
              showUrl(url, true);
              return;
            } catch (error) {
              // フォールバックで画面表示します。
            }
          }

          showUrl(url, false);
        });
      });

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
