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
 * @return array<int, array{id:string,date:string,title:string,body:string}>
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
        ];
    }

    return $articles;
}

/**
 * @param array<int, array{id:string,date:string,title:string,body:string}> $articles
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
        ];
    }

    $payload = ['articles' => $payloadArticles];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
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
          <p class="muted">入力項目は「タイトル」「投稿日付」「本文」のみです。ID は保存時に自動採番されます。</p>
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
      const modalDelete = document.getElementById('modal-delete');
      const modalCancel = document.getElementById('modal-cancel');
      const modalSave = document.getElementById('modal-save');
      const todayDefault = '<?= date('Y-m-d') ?>';

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
            <p class="article-meta">ID: ${article.id || '-'} / 投稿日付: ${truncate(article.date || '-', 30)}</p>
            <p class="article-body-preview">${truncate((article.body || '').replace(/\s+/g, ' ').trim(), 110)}</p>
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
          modalDelete.style.display = 'none';
        } else {
          const article = articles[editingIndex] || { date: '', title: '', body: '' };
          modalTitle.textContent = '記事を編集';
          modalDate.value = article.date || '';
          modalTitleInput.value = article.title || '';
          modalBody.value = article.body || '';
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

        if (date === '' || title === '' || body.trim() === '') {
          alert('投稿日付・タイトル・本文は必須です。');
          return;
        }

        const next = { id: '', date, title, body };

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
