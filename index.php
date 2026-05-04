<?php
// Main UI – single page; data is fetched via api.php
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
      <button id="btn-refresh" title="Alle Feeds aktualisieren">&#x21bb;</button>
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
