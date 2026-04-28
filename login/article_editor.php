<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$articlesFile = __DIR__ . '/../include/articles.json';
$message = '';
$error = '';

/**
 * @return array<int, array{id:string,date:string,title:string,body:string,visibility:string,publish_types:array<int, string>}>
 */
function load_articles(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $items = $decoded['articles'] ?? [];
    if (!is_array($items)) {
        return [];
    }

    $articles = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $articles[] = [
            'id' => trim((string)($item['id'] ?? '')),
            'date' => trim((string)($item['date'] ?? '')),
            'title' => trim((string)($item['title'] ?? '')),
            'body' => (string)($item['body'] ?? ''),
            'visibility' => normalize_visibility($item['visibility'] ?? 'public'),
            'publish_types' => normalize_publish_types($item['publish_types'] ?? null),
        ];
    }

    return $articles;
}

/**
 * @param array<int, array{id:string,date:string,title:string,body:string,visibility:string,publish_types:array<int, string>}> $articles
 */
function save_articles(string $path, array $articles): bool
{
    $payloadArticles = [];
    foreach ($articles as $article) {
        $payloadArticles[] = [
            'id' => $article['id'],
            'date' => $article['date'],
            'title' => $article['title'],
            'excerpt' => '',
            'body' => $article['body'],
            'visibility' => normalize_visibility($article['visibility'] ?? 'public'),
            'publish_types' => normalize_publish_types($article['publish_types'] ?? null),
        ];
    }

    $payload = ['articles' => $payloadArticles];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function normalize_visibility(mixed $visibility): string
{
    $normalized = strtolower(trim((string)$visibility));
    if (in_array($normalized, ['private', 'admin', 'public'], true)) {
        return $normalized;
    }

    return 'public';
}
/**
 * @return array<int, string>
 */
function normalize_publish_types(mixed $publishTypes): array
{
    $allowed = ['lesson', 'practice'];
    if (is_string($publishTypes)) {
        $publishTypes = array_filter(array_map('trim', explode(',', $publishTypes)), static fn(string $value): bool => $value !== '');
    }
    if (!is_array($publishTypes)) {
        return $allowed;
    }

    $normalized = [];
    foreach ($publishTypes as $type) {
        $typeValue = strtolower(trim((string)$type));
        if (in_array($typeValue, $allowed, true) && !in_array($typeValue, $normalized, true)) {
            $normalized[] = $typeValue;
        }
    }

    return $normalized === [] ? $allowed : $normalized;
}
$articles = load_articles($articlesFile);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedArticles = $_POST['articles'] ?? [];
    if (!is_array($postedArticles)) {
        $postedArticles = [];
    }

    $nextArticles = [];

    foreach ($postedArticles as $article) {
        if (!is_array($article)) {
            continue;
        }

        $date = trim((string)($article['date'] ?? ''));
        $title = trim((string)($article['title'] ?? ''));
        $body = (string)($article['body'] ?? '');
        $visibility = normalize_visibility($article['visibility'] ?? 'public');

        $publishTypes = normalize_publish_types($article['publish_types'] ?? null);
        if ($date === '' && $title === '' && trim($body) === '') {
            continue;
        }

        if ($date === '' || $title === '' || trim($body) === '') {
            $error = '投稿日付・タイトル・本文は必須です。';
            break;
        }
        $nextArticles[] = [
            'id' => '',
            'date' => $date,
            'title' => $title,
            'body' => $body,
            'visibility' => $visibility,
            'publish_types' => $publishTypes,
        ];
    }

    if ($error === '') {
        foreach ($nextArticles as $index => &$article) {
            $article['id'] = (string)($index + 1);
        }
        unset($article);

        if (save_articles($articlesFile, $nextArticles)) {
            $articles = $nextArticles;
            $message = 'articles.json を保存しました。';
        } else {
            $error = '保存に失敗しました。権限を確認してください。';
        }
    }
}
$articlesForJs = array_values(array_map(
    static fn(array $article): array => [
        'id' => (string)($article['id'] ?? ''),
        'date' => (string)($article['date'] ?? ''),
        'title' => (string)($article['title'] ?? ''),
        'body' => (string)($article['body'] ?? ''),
        'visibility' => normalize_visibility($article['visibility'] ?? 'public'),
        'publish_types' => normalize_publish_types($article['publish_types'] ?? null),
    ],
    $articles
));
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>記事編集</title>
  <link rel="stylesheet" href="styke.css?v=<?= time() ?>">
  <style>
    .article-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 10px;
    }

    .article-item {
      border: 1px solid var(--line);
      border-radius: 12px;
      background: #fff;
      padding: 12px;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
    }

    .article-title {
      font-weight: 700;
      margin: 0 0 6px;
    }

    .article-meta {
      margin: 0;
      color: var(--muted);
      font-size: 14px;
    }

    .article-body-preview {
      margin: 6px 0 0;
      color: var(--muted);
      font-size: 13px;
      white-space: pre-wrap;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(18, 49, 95, .45);
      display: none;
      place-items: center;
      padding: 20px;
      z-index: 1000;
    }

    .modal-backdrop.open {
      display: grid;
    }

    .modal {
      width: min(680px, 96vw);
      background: #fff;
      border-radius: 14px;
      border: 1px solid var(--line);
      box-shadow: 0 20px 40px rgba(18, 49, 95, .24);
      padding: 16px;
    }

    .modal h2 {
      margin-bottom: 10px;
    }

    .modal textarea {
      min-height: 200px;
      width: 100%;
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid var(--line);
      margin-top: 6px;
      font-family: inherit;
      line-height: 1.45;
      resize: vertical;
    }
  </style>
</head>
<body>
  <header class="header">
    <div class="header-inner">
      <strong>記事データ編集</strong>
      <nav class="nav">
        <a href="admin.php">管理画面</a>
        <a href="index.php">Login</a>
      </nav>
    </div>
  </header>

  <main class="wrap admin-wrap">
    <section class="card admin-card">
      <div class="title-row">
        <div>
          <h1>記事編集</h1>
          <p class="muted">入力項目は「タイトル」「投稿日付」「本文」「公開モード」「公開タイプ（lesson / practice）」です。本文は HTML をそのまま保存できます。ID は保存時に自動採番されます。</p>
        </div>
      </div>

      <?php if ($message !== ''): ?>
        <p class="notice success"><?= h($message) ?></p>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <p class="notice error"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post" id="articles-form">
        <div class="actions" style="margin-bottom: 12px;">
          <button class="btn-sub btn-inline" type="button" id="add-article">追加</button>
        </div>
        <ul class="article-list" id="article-list"></ul>
        <div class="pager" id="article-pager"></div>
        <div id="articles-hidden-inputs"></div>
      </form>
    </section>
  </main>
  <div class="modal-backdrop" id="article-modal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="article-modal-title">
      <h2 id="article-modal-title">記事を編集</h2>
      <label>
        投稿日付
        <input type="text" id="modal-date" placeholder="2026-03-24" required>
      </label>
      <label>
        タイトル
        <input type="text" id="modal-title" required>
      </label>
      <label>
        本文
        <textarea id="modal-body" required></textarea>
      </label>
      <label>
        公開モード
        <select id="modal-visibility" required>
          <option value="private">非公開（誰も見れない）</option>
          <option value="admin">管理者公開（管理者ユーザのみ）</option>
          <option value="public">一般公開（管理者ユーザ・通常ユーザ）</option>
        </select>
      </label>
      <fieldset style="border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px;">
        <legend style="padding: 0 4px;">公開タイプ（複数選択可）</legend>
        <label style="display: inline-flex; align-items: center; gap: 8px; margin-right: 12px;">
          <input type="checkbox" id="modal-publish-type-lesson" value="lesson" checked>
          lesson
        </label>
        <label style="display: inline-flex; align-items: center; gap: 8px;">
          <input type="checkbox" id="modal-publish-type-practice" value="practice" checked>
          practice
        </label>
      </fieldset>
      <div class="actions" style="margin-top: 12px; justify-content: flex-end;">
        <button class="btn-danger btn-inline" type="button" id="modal-delete" style="margin-right: auto; display: none;">削除</button>
        <button class="btn-sub btn-inline" type="button" id="modal-cancel">キャンセル</button>
        <button class="btn-inline" type="button" id="modal-save">保存</button>
      </div>
    </div>
  </div>

  <script>
    (() => {
      const ITEMS_PER_PAGE = 25;
      const articles = <?= json_encode($articlesForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];

      const form = document.getElementById('articles-form');
      const listEl = document.getElementById('article-list');
      const pagerEl = document.getElementById('article-pager');
      const hiddenInputsEl = document.getElementById('articles-hidden-inputs');
      const addButton = document.getElementById('add-article');

      const modal = document.getElementById('article-modal');
      const modalTitle = document.getElementById('article-modal-title');
      const modalDate = document.getElementById('modal-date');
      const modalTitleInput = document.getElementById('modal-title');
      const modalBody = document.getElementById('modal-body');
      const modalVisibility = document.getElementById('modal-visibility');
      const modalPublishTypeLesson = document.getElementById('modal-publish-type-lesson');
      const modalPublishTypePractice = document.getElementById('modal-publish-type-practice');
      const modalDelete = document.getElementById('modal-delete');
      const modalCancel = document.getElementById('modal-cancel');
      const modalSave = document.getElementById('modal-save');
      const todayDefault = '<?= date('Y-m-d') ?>';
      const normalizePublishTypes = (publishTypes) => {
        const allowed = ['lesson', 'practice'];
        if (!Array.isArray(publishTypes)) {
          return allowed;
        }
        const normalized = [];
        publishTypes.forEach((type) => {
          const value = String(type || '').trim().toLowerCase();
          if (allowed.includes(value) && !normalized.includes(value)) {
            normalized.push(value);
          }
        });
        return normalized.length > 0 ? normalized : allowed;
      };

      let currentPage = 1;
      let editingIndex = null;

      const totalPages = () => Math.max(1, Math.ceil(articles.length / ITEMS_PER_PAGE));

      const truncate = (value, limit) => {
        if (value.length <= limit) {
          return value;
        }
        return `${value.slice(0, limit)}…`;
      };

      const renderPager = () => {
        const pages = totalPages();
        pagerEl.innerHTML = '';

        if (pages <= 1) {
          return;
        }

        for (let page = 1; page <= pages; page += 1) {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'pager-link';
          button.textContent = String(page);
          if (page === currentPage) {
            button.classList.add('disabled');
          }
          button.addEventListener('click', () => {
            currentPage = page;
            renderList();
            renderPager();
          });
          pagerEl.appendChild(button);
        }
      };

      const renderList = () => {
        const start = (currentPage - 1) * ITEMS_PER_PAGE;
        const end = start + ITEMS_PER_PAGE;
        const visible = articles.slice(start, end);

        listEl.innerHTML = '';

        if (visible.length === 0) {
          const empty = document.createElement('li');
          empty.className = 'article-item';
          empty.innerHTML = '<div><p class="article-title">記事がありません</p><p class="article-meta">「追加」ボタンから作成してください。</p></div>';
          listEl.appendChild(empty);
          return;
        }

        visible.forEach((article, offset) => {
          const index = start + offset;
          const li = document.createElement('li');
          li.className = 'article-item';

          const info = document.createElement('div');
          info.innerHTML = `
            <p class="article-title">${truncate(article.title || '(無題)', 80)}</p>
            <p class="article-meta">ID: ${article.id || '-'} / 投稿日付: ${truncate(article.date || '-', 30)} / 公開: ${article.visibility || 'public'} / 公開タイプ: ${(normalizePublishTypes(article.publish_types).join(', '))}</p>
            <p class="article-body-preview">${truncate((article.body || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim(), 110)}</p>
          `;

          const actions = document.createElement('div');
          actions.className = 'actions';
          actions.innerHTML = '<button type="button" class="btn-inline">編集</button>';
          actions.querySelector('button')?.addEventListener('click', () => openModal(index));

          li.append(info, actions);
          listEl.appendChild(li);
        });
      };

      const openModal = (index = null) => {
        editingIndex = index;

        if (editingIndex === null) {
          modalTitle.textContent = '記事を追加';
          modalDate.value = todayDefault;
          modalTitleInput.value = '';
          modalBody.value = '';
          modalVisibility.value = 'public';
          modalPublishTypeLesson.checked = true;
          modalPublishTypePractice.checked = true;
          modalDelete.style.display = 'none';
        } else {
          const article = articles[editingIndex] || { date: '', title: '', body: '', visibility: 'public', publish_types: ['lesson', 'practice'] };
          const publishTypes = normalizePublishTypes(article.publish_types);
          modalTitle.textContent = '記事を編集';
          modalDate.value = article.date || '';
          modalTitleInput.value = article.title || '';
          modalBody.value = article.body || '';
          modalVisibility.value = article.visibility || 'public';
          modalPublishTypeLesson.checked = publishTypes.includes('lesson');
          modalPublishTypePractice.checked = publishTypes.includes('practice');
          modalDelete.style.display = 'inline-flex';
        }

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        modalDate.focus();
      };

      const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      };

      const normalizeIds = () => {
        articles.forEach((article, index) => {
          article.id = String(index + 1);
        });
      };

      const rebuildHiddenInputs = () => {
        hiddenInputsEl.innerHTML = '';

        articles.forEach((article, index) => {
          const fields = {
            id: article.id || '',
            date: article.date || '',
            title: article.title || '',
            body: article.body || '',
            visibility: article.visibility || 'public',
            publish_types: normalizePublishTypes(article.publish_types).join(','),

          };

          Object.entries(fields).forEach(([key, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `articles[${index}][${key}]`;
            input.value = value;
            hiddenInputsEl.appendChild(input);
          });
        });
      };

      addButton.addEventListener('click', () => openModal(null));

      modalCancel.addEventListener('click', closeModal);

      modalSave.addEventListener('click', () => {
        const date = modalDate.value.trim();
        const title = modalTitleInput.value.trim();
        const body = modalBody.value;
        const publishTypes = [];
        if (modalPublishTypeLesson.checked) {
          publishTypes.push('lesson');
        }
        if (modalPublishTypePractice.checked) {
          publishTypes.push('practice');
        }

        if (date === '' || title === '' || body.trim() === '') {
          alert('投稿日付・タイトル・本文は必須です。');
          return;
        }
        if (publishTypes.length === 0) {
          alert('公開タイプを1つ以上選択してください。');
          return;
        }

        const next = { id: '', date, title, body, visibility, publish_types: publishTypes };


        if (editingIndex === null) {
          articles.push(next);
          currentPage = totalPages();
        } else {
          articles[editingIndex] = next;
        }

        normalizeIds();
        renderList();
        renderPager();
        rebuildHiddenInputs();
        form.requestSubmit();
      });

      modalDelete.addEventListener('click', () => {
        if (editingIndex === null) {
          return;
        }

        if (!confirm('この記事を削除します。よろしいですか？')) {
          return;
        }

        articles.splice(editingIndex, 1);
        normalizeIds();
        currentPage = Math.min(currentPage, totalPages());
        renderList();
        renderPager();
        rebuildHiddenInputs();
        form.requestSubmit();
      });

      form.addEventListener('submit', () => {
        normalizeIds();
        rebuildHiddenInputs();
      });

      normalizeIds();
      renderList();
      renderPager();
      rebuildHiddenInputs();
    })();
  </script>
</body>
</html>
