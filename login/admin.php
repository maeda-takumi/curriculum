<?php

declare(strict_types=1);

require_once __DIR__ . '/users_store.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';
if (isset($_GET['message']) && is_string($_GET['message'])) {
    $message = $_GET['message'];
}
if (isset($_GET['error']) && is_string($_GET['error'])) {
    $error = $_GET['error'];
}

$users = load_users();

$phaseOptions = [
    'phase0' => 'PHASE 0',
    'phase1' => 'PHASE 1',
    'phase2' => 'PHASE 2',
    'phase3' => 'PHASE 3',
    'phase4' => 'PHASE 4',
    'phase5' => 'PHASE 5',
    'phase6' => 'PHASE 6',
];
$lessonWeekOptions = [
    'week1' => 'Week 1',
    'week2' => 'Week 2',
    'week3' => 'Week 3',
    'week4' => 'Week 4',
    'week5' => 'Week 5',
    'week6' => 'Week 6',
    'week7' => 'Week 7',
    'week8' => 'Week 8',
    'week9' => 'Week 9',
    'week10' => 'Week 10',
    'week11' => 'Week 11',
    'week12' => 'Week 12',
];

/**
 * @param array<string, string> $phaseOptions
 * @return array<string, bool>
 */
function collect_phase_locks_from_post(array $phaseOptions): array
{
    $locks = default_phase_locks();
    foreach ($phaseOptions as $phaseKey => $_) {
        $locks[$phaseKey] = (string)($_POST['lock_' . $phaseKey] ?? '0') === '1';
    }

    return normalize_phase_locks($locks);
}

/**
 * @return array<string, bool>
 */
function default_create_phase_locks(): array
{
    $locks = default_phase_locks();
    foreach ($locks as $phaseKey => $_) {
        $locks[$phaseKey] = true;
    }

    return $locks;
}

/**
 * @param array<string, string> $lessonWeekOptions
 * @return array<string, bool>
 */
function collect_lesson_week_locks_from_post(array $lessonWeekOptions): array
{
    $locks = default_lesson_week_locks();
    foreach ($lessonWeekOptions as $weekKey => $_) {
        $locks[$weekKey] = (string)($_POST['lesson_lock_' . $weekKey] ?? '0') === '1';
    }

    return normalize_lesson_week_locks($locks);
}

/**
 * @return array<string, bool>
 */
function default_create_lesson_week_locks(): array
{
    $locks = default_lesson_week_locks();
    foreach ($locks as $weekKey => $_) {
        $locks[$weekKey] = $weekKey !== 'week1';
    }

    return $locks;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $lineName = trim((string)($_POST['line_name'] ?? ''));
        $realName = trim((string)($_POST['real_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = trim((string)($_POST['password'] ?? ''));
        $status = normalize_status($_POST['status'] ?? 'inactive');
        $phaseLocks = collect_phase_locks_from_post($phaseOptions);
        $lessonWeekLocks = collect_lesson_week_locks_from_post($lessonWeekOptions);

        if ($lineName === '' || $email === '' || $password === '') {
            $error = 'LINE名・メールアドレス・パスワードは必須です。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'メールアドレス形式が正しくありません。';
        } else {
            $exists = false;
            foreach ($users as $user) {
                if (hash_equals((string)($user['email'] ?? ''), $email)) {
                    $exists = true;
                    break;
                }
            }

            if ($exists) {
                $error = '同じメールアドレスのユーザーが既に存在します。';
            } else {
                $users[] = [
                    'id' => next_user_id($users),
                    'line_name' => $lineName,
                    'real_name' => $realName,
                    'email' => $email,
                    'password' => $password,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'status' => $status,
                    'phase_locks' => $phaseLocks,
                    'lesson_week_locks' => $lessonWeekLocks,
                ];
                save_users($users);
                header('Location: admin.php?message=' . urlencode('ユーザを追加しました。'));
                exit;
            }
        }
    }

    if ($action === 'update_user') {
        $id = (int)($_POST['id'] ?? 0);
        $lineName = trim((string)($_POST['line_name'] ?? ''));
        $realName = trim((string)($_POST['real_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = trim((string)($_POST['password'] ?? ''));
        $status = normalize_status($_POST['status'] ?? 'inactive');
        $phaseLocks = collect_phase_locks_from_post($phaseOptions);
        $lessonWeekLocks = collect_lesson_week_locks_from_post($lessonWeekOptions);

        if ($lineName === '' || $email === '') {
            $error = 'LINE名・メールアドレスは必須です。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'メールアドレス形式が正しくありません。';
        } else {
            $duplicate = false;
            foreach ($users as $user) {
                if ((int)($user['id'] ?? 0) === $id) {
                    continue;
                }
                if (hash_equals((string)($user['email'] ?? ''), $email)) {
                    $duplicate = true;
                    break;
                }
            }
            if ($duplicate) {
                $error = '同じメールアドレスのユーザーが既に存在します。';
            } else {
                foreach ($users as &$user) {
                    if ((int)$user['id'] !== $id) {
                        continue;
                    }
                    $user['line_name'] = $lineName;
                    $user['real_name'] = $realName;
                    $user['email'] = $email;
                    $user['status'] = $status;
                    $user['phase_locks'] = $phaseLocks;
                    $user['lesson_week_locks'] = $lessonWeekLocks;
                    if ($password !== '') {
                        $user['password'] = $password;
                        $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    }
                }
                unset($user);

                save_users($users);
                header('Location: admin.php?message=' . urlencode('ユーザ情報を更新しました。'));
                exit;
            }
        }
    }

    if ($action === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        $users = array_values(array_filter($users, static fn(array $user): bool => (int)$user['id'] !== $id));
        save_users($users);
        header('Location: admin.php?message=' . urlencode('ユーザを削除しました。'));
        exit;
    }

}

$keywordLineName = trim((string)($_GET['line_name'] ?? ''));
$keywordRealName = trim((string)($_GET['real_name'] ?? ''));
$keywordEmail = trim((string)($_GET['email'] ?? ''));

$filteredUsers = array_values(array_filter($users, static function (array $user) use ($keywordLineName, $keywordRealName, $keywordEmail): bool {
    $lineName = (string)($user['line_name'] ?? '');
    $realName = (string)($user['real_name'] ?? '');
    $email = (string)($user['email'] ?? '');

    $lineMatches = $keywordLineName === '' || mb_stripos($lineName, $keywordLineName) !== false;
    $realNameMatches = $keywordRealName === '' || mb_stripos($realName, $keywordRealName) !== false;
    $emailMatches = $keywordEmail === '' || mb_stripos($email, $keywordEmail) !== false;

    return $lineMatches && $realNameMatches && $emailMatches;
}));

$perPage = 25;
$totalUsers = count($filteredUsers);
$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$currentPage = (int)($_GET['page'] ?? 1);
$currentPage = max(1, min($currentPage, $totalPages));
$offset = ($currentPage - 1) * $perPage;
$usersOnPage = array_slice($filteredUsers, $offset, $perPage);
$createDefaultPhaseLocks = default_create_phase_locks();
$createDefaultLessonWeekLocks = default_create_lesson_week_locks();

function page_link(int $page, string $lineName, string $realName, string $email): string
{
    $params = [
        'page' => $page,
    ];
    if ($lineName !== '') {
        $params['line_name'] = $lineName;
    }
    if ($realName !== '') {
        $params['real_name'] = $realName;
    }
    if ($email !== '') {
        $params['email'] = $email;
    }

    return 'admin.php?' . http_build_query($params);
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ログインユーザ管理</title>
  <link rel="stylesheet" href="styke.css?v=<?= time() ?>">
</head>
<body>
  <header class="header">
    <div class="header-inner">
      <strong>ログイン管理画面</strong>
      <nav class="nav">
        <a href="article_editor.php">記事編集</a>
        <a href="login_attempts.php">ログイン試行ログ</a>
        <a href="index.php">Login</a>
      </nav>
    </div>
  </header>

  <main class="wrap admin-wrap">
    <section class="card admin-card">
      <div class="title-row">
        <div>
          <h1>ユーザ管理</h1>
          <p class="muted">登録情報（LINE名 / 本名 / メールアドレス / password / status / フェーズ・Weekロック）を管理できます。</p>
        </div>
        <button type="button" class="btn-inline" data-modal-target="create-user-modal">+ ユーザ追加</button>
      </div>

      <?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

      <form method="get" class="search-row card-sub">
        <label>LINE名検索
          <input type="text" name="line_name" value="<?= h($keywordLineName) ?>" placeholder="例: yamada">
        </label>
        <label>本名検索
          <input type="text" name="real_name" value="<?= h($keywordRealName) ?>" placeholder="例: 山田 太郎">
        </label>
        <label>メールアドレス検索
          <input type="text" name="email" value="<?= h($keywordEmail) ?>" placeholder="example@domain.com">
        </label>
        <button type="submit" class="btn-inline center-row">検索</button>
      </form>

      <p class="result-meta"><?= $totalUsers ?>件中 <?= $offset + 1 ?>〜<?= min($offset + $perPage, $totalUsers === 0 ? 0 : $totalUsers) ?>件を表示</p>

      <ul class="user-list">
        <?php foreach ($usersOnPage as $user): ?>
          <li class="user-item">
            <div class="user-main">
              <div class="user-primary"><?= h((string)$user['line_name']) ?></div>
              <div class="user-secondary">本名: <?= h((string)($user['real_name'] ?? '')) ?></div>
              <div class="user-secondary"><?= h((string)$user['email']) ?></div>
              <div class="user-password">password: <?= h((string)($user['password'] ?? '')) ?></div>
              <div class="user-password">
                Lessonロック中:
                <?php
                $lockedWeekLabels = [];
                $userLessonLocks = normalize_lesson_week_locks($user['lesson_week_locks'] ?? null);
                foreach ($lessonWeekOptions as $weekKey => $weekLabel) {
                    if (($userLessonLocks[$weekKey] ?? false) === true) {
                        $lockedWeekLabels[] = $weekLabel;
                    }
                }
                echo h($lockedWeekLabels === [] ? 'なし' : implode(', ', $lockedWeekLabels));
                ?>
              </div>
              <div class="user-password">
                ロック中:
                <?php
                $lockedLabels = [];
                $userLocks = normalize_phase_locks($user['phase_locks'] ?? null);
                foreach ($phaseOptions as $phaseKey => $phaseLabel) {
                    if (($userLocks[$phaseKey] ?? false) === true) {
                        $lockedLabels[] = $phaseLabel;
                    }
                }
                echo h($lockedLabels === [] ? 'なし' : implode(', ', $lockedLabels));
                ?>
              </div>
            </div>
            <div class="user-right">
              <span class="status-pill <?= ((string)$user['status'] === 'active') ? 'active' : 'inactive' ?>">
                <?= ((string)$user['status'] === 'active') ? '有効' : '無効' ?>
              </span>
              <div class="actions">
                <!-- <form method="post" onsubmit="return confirm('削除しますか？');">
                  <input type="hidden" name="action" value="delete_user">
                  <input type="hidden" name="id" value="<?php //echo (int)$user['id']; ?>">
                  <button type="submit" class="btn-danger">削除</button>
                </form> -->
                <button
                  type="button"
                  class="btn-inline"
                  data-modal-target="edit-user-modal"
                  data-id="<?= (int)$user['id'] ?>"
                  data-line-name="<?= h((string)$user['line_name']) ?>"
                  data-real-name="<?= h((string)($user['real_name'] ?? '')) ?>"
                  data-email="<?= h((string)$user['email']) ?>"
                  data-password="<?= h((string)($user['password'] ?? '')) ?>"
                  data-status="<?= h((string)$user['status']) ?>"
                  data-phase-locks='<?= h(json_encode(normalize_phase_locks($user["phase_locks"] ?? null), JSON_UNESCAPED_UNICODE) ?: "{}") ?>'
                  data-lesson-week-locks='<?= h(json_encode(normalize_lesson_week_locks($user["lesson_week_locks"] ?? null), JSON_UNESCAPED_UNICODE) ?: "{}") ?>'
                >編集</button>
                <button
                  type="button"
                  class="icon-copy-btn"
                  data-copy-email="<?= h((string)$user['email']) ?>"
                  data-copy-password="<?= h((string)($user['password'] ?? '')) ?>"
                  aria-label="メールアドレスとパスワードをコピー"
                  title="メールアドレスとパスワードをコピー"
                >
                  <img src="img/copy.png" alt="コピー">
                </button>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php if ($usersOnPage === []): ?>
        <p class="muted">表示できるユーザがありません。</p>
      <?php endif; ?>

      <div class="pager">
        <a class="pager-link <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="<?= $currentPage <= 1 ? '#' : h(page_link($currentPage - 1, $keywordLineName, $keywordRealName, $keywordEmail)) ?>">前へ</a>
        <span class="pager-current"><?= $currentPage ?> / <?= $totalPages ?></span>
        <a class="pager-link <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="<?= $currentPage >= $totalPages ? '#' : h(page_link($currentPage + 1, $keywordLineName, $keywordRealName, $keywordEmail)) ?>">次へ</a>
      </div>
    </section>
  </main>

  <dialog id="create-user-modal" class="modal">
    <form method="dialog" class="modal-header">
      <h2>新規ユーザ追加</h2>
      <button type="submit" class="text-btn">✕</button>
    </form>
    <form method="post" class="grid">
      <input type="hidden" name="action" value="create_user">
      <label>LINE名<input type="text" name="line_name" required></label>
      <label>本名<input type="text" name="real_name"></label>
      <label>メールアドレス（ログイン情報）<input type="email" name="email" required></label>
        <label>password（ログイン情報）
      <div class="flex_frame">
        <input id="new-user-password" type="text" name="password" required></label>
        <button type="button" id="generate-password" class="btn-sub">パスワード自動生成</button>
    </div>
      <label>status
        <select name="status">
          <option value="active">有効</option>
          <option value="inactive">無効</option>
        </select>
      </label>
      <div class="lock_flex">
        <fieldset>
          <legend>Lesson Week閲覧ロック（チェックで閉場）</legend>
          <div class="permission-grid">
            <?php foreach ($lessonWeekOptions as $weekKey => $weekLabel): ?>
              <label class="permission-item">
                <span class="lock_label"><?= h($weekLabel) ?></span>
                <input type="checkbox" id="create-lesson-lock-<?= h($weekKey) ?>" name="lesson_lock_<?= h($weekKey) ?>" value="1">
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset>
          <legend>フェーズ閲覧ロック（チェックで閉場）</legend>
          <div class="permission-grid">
            <?php foreach ($phaseOptions as $phaseKey => $phaseLabel): ?>
              <label class="permission-item">
                <span class="lock_label"><?= h($phaseLabel) ?></span>
                <input type="checkbox" id="create-lock-<?= h($phaseKey) ?>" name="lock_<?= h($phaseKey) ?>" value="1">
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset> 
      </div>
      <div>
        <button type="submit">登録</button>
      </div>
    </form>
  </dialog>

  <dialog id="edit-user-modal" class="modal">
    <form method="dialog" class="modal-header">
      <h2>ユーザ編集</h2>
      <button type="submit" class="text-btn">✕</button>
    </form>
    <form method="post" class="grid" id="edit-user-form">
      <input type="hidden" name="action" value="update_user">
      <input type="hidden" name="id" id="edit-id">
      <label>LINE名<input type="text" name="line_name" id="edit-line-name" required></label>
      <label>本名<input type="text" name="real_name" id="edit-real-name"></label>
      <label>メールアドレス（ログイン情報）<input type="email" name="email" id="edit-email" required></label>
      <label>password（ログイン情報）<input type="text" name="password" id="edit-password"></label>
      <label>status
        <select name="status" id="edit-status">
          <option value="active">有効</option>
          <option value="inactive">無効</option>
        </select>
      </label>
        <div class="lock_flex">
        <fieldset id="edit-lesson-week-locks">
          <legend>Lesson Week閲覧ロック（チェックで閉場）</legend>
          <div class="permission-grid">
            <?php foreach ($lessonWeekOptions as $weekKey => $weekLabel): ?>
              <label class="permission-item">
                <span class="lock_label"><?= h($weekLabel) ?></span>
                <input type="checkbox" id="edit-lesson-lock-<?= h($weekKey) ?>" name="lesson_lock_<?= h($weekKey) ?>" value="1">
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <fieldset id="edit-phase-locks">
          <legend>フェーズ閲覧ロック（チェックで閉場）</legend>
          <div class="permission-grid">
            <?php foreach ($phaseOptions as $phaseKey => $phaseLabel): ?>
              <label class="permission-item">
                <span class="lock_label"><?= h($phaseLabel) ?></span>
                <input type="checkbox" id="edit-lock-<?= h($phaseKey) ?>" name="lock_<?= h($phaseKey) ?>" value="1">
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        </div>
      <button type="submit">更新</button>
    </form>
  </dialog>
  <script>
    (() => {
      const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
      const button = document.getElementById('generate-password');
      const input = document.getElementById('new-user-password');

      if (button && input) {
        button.addEventListener('click', () => {
          const length = 12;
          let password = '';
          for (let i = 0; i < length; i += 1) {
            password += letters[Math.floor(Math.random() * letters.length)];
          }
          input.value = password;
        });
      }

      <?php foreach ($createDefaultPhaseLocks as $phaseKey => $isLocked): ?>
      const createPhaseCheckbox<?= h($phaseKey) ?> = document.getElementById('create-lock-<?= h($phaseKey) ?>');
      if (createPhaseCheckbox<?= h($phaseKey) ?> instanceof HTMLInputElement) {
        createPhaseCheckbox<?= h($phaseKey) ?>.checked = <?= $isLocked ? 'true' : 'false' ?>;
      }
      <?php endforeach; ?>
      <?php foreach ($createDefaultLessonWeekLocks as $weekKey => $isLocked): ?>
      const createLessonCheckbox<?= h($weekKey) ?> = document.getElementById('create-lesson-lock-<?= h($weekKey) ?>');
      if (createLessonCheckbox<?= h($weekKey) ?> instanceof HTMLInputElement) {
        createLessonCheckbox<?= h($weekKey) ?>.checked = <?= $isLocked ? 'true' : 'false' ?>;
      }
      <?php endforeach; ?>
      const openButtons = document.querySelectorAll('[data-modal-target]');
      openButtons.forEach((openButton) => {
        openButton.addEventListener('click', () => {
          const modalId = openButton.getAttribute('data-modal-target');
          if (!modalId) {
            return;
          }

          const modal = document.getElementById(modalId);
          if (!(modal instanceof HTMLDialogElement)) {
            return;
          }

          if (modalId === 'edit-user-modal') {
            const idInput = document.getElementById('edit-id');
            const lineNameInput = document.getElementById('edit-line-name');
            const realNameInput = document.getElementById('edit-real-name');
            const emailInput = document.getElementById('edit-email');
            const passwordInput = document.getElementById('edit-password');
            const statusInput = document.getElementById('edit-status');
            const editForm = document.getElementById('edit-user-form');

            if (!idInput || !lineNameInput || !realNameInput || !emailInput || !passwordInput || !statusInput || !(editForm instanceof HTMLFormElement)) {
              return;
            }

            idInput.value = openButton.dataset.id ?? '';
            lineNameInput.value = openButton.dataset.lineName ?? '';
            realNameInput.value = openButton.dataset.realName ?? '';
            emailInput.value = openButton.dataset.email ?? '';
            passwordInput.value = openButton.dataset.password ?? '';
            statusInput.value = openButton.dataset.status ?? 'inactive';
            let phaseLocks = {};
            let lessonWeekLocks = {};
            try {
              phaseLocks = JSON.parse(openButton.dataset.phaseLocks ?? '{}');
            } catch (error) {
              phaseLocks = {};
            }

            try {
              lessonWeekLocks = JSON.parse(openButton.dataset.lessonWeekLocks ?? '{}');
            } catch (error) {
              lessonWeekLocks = {};
            }
            ['phase0', 'phase1', 'phase2', 'phase3', 'phase4', 'phase5', 'phase6'].forEach((phaseKey) => {
              const checkbox = editForm.querySelector(`input[name="lock_${phaseKey}"]`);
              if (checkbox instanceof HTMLInputElement) {
                checkbox.checked = Boolean(phaseLocks[phaseKey]);
              }
            });
            ['week1', 'week2', 'week3', 'week4', 'week5', 'week6', 'week7', 'week8', 'week9', 'week10', 'week11', 'week12'].forEach((weekKey) => {
              const checkbox = editForm.querySelector(`input[name="lesson_lock_${weekKey}"]`);
              if (checkbox instanceof HTMLInputElement) {
                checkbox.checked = Boolean(lessonWeekLocks[weekKey]);
              }
            });
          }

          modal.showModal();
        });
      });
      const copyButtons = document.querySelectorAll('.icon-copy-btn');
      copyButtons.forEach((copyButton) => {
        copyButton.addEventListener('click', async () => {
          const email = copyButton.getAttribute('data-copy-email') ?? '';
          const password = copyButton.getAttribute('data-copy-password') ?? '';
          const text = `メールアドレス：${email}\nパスワード：${password}`;

          if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
            window.alert('このブラウザではクリップボード機能を利用できません。');
            return;
          }

          try {
            await navigator.clipboard.writeText(text);
            window.alert('メールアドレスとパスワードをクリップボードに保存しました');
          } catch (error) {
            window.alert('クリップボードへの保存に失敗しました。');
          }
        });
      });
    })();
  </script>
</body>
</html>
