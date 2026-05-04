<?php
require __DIR__ . '/auth.php';
require_login_html();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RSS Reader</title>
  <link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
  <aside id="sidebar">
    <header class="brand">
      <h1>RSS</h1>
      <div class="brand-actions">
        <button id="btn-refresh" title="Alle Feeds aktualisieren">&#x21bb;</button>
        <a id="btn-logout" href="logout.php" title="Abmelden" aria-label="Abmelden">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M15 17l5-5-5-5M20 12H9M12 19a7 7 0 1 1 0-14"/>
          </svg>
        </a>
      </div>
    </header>

    <form id="add-form" autocomplete="off">
      <input type="url" id="feed-url" placeholder="https://example.com/feed.xml" required>
      <button type="submit">Hinzufügen</button>
    </form>

    <nav id="feed-list"></nav>

    <footer>
      <label class="toggle">
        <input type="checkbox" id="hide-read"> nur ungelesene
      </label>
    </footer>
  </aside>

  <main id="content">
    <header id="content-header">
      <h2 id="current-feed">Alle Artikel</h2>
      <span id="status"></span>
    </header>
    <ul id="items"></ul>
  </main>

  <script src="assets/app.js?v=1"></script>
</body>
</html>
