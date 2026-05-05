<?php
require __DIR__ . '/auth.php';
require_login_html();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>RSS Reader</title>
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="theme-color" content="#175d3b">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="RSS">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon-180.png?v=4">
  <link rel="apple-touch-icon" sizes="167x167" href="apple-touch-icon-167.png?v=4">
  <link rel="apple-touch-icon" sizes="152x152" href="apple-touch-icon-152.png?v=4">
  <link rel="apple-touch-icon"                 href="apple-touch-icon.png?v=4">
  <link rel="icon" type="image/svg+xml" href="assets/icon.svg?v=4">
  <link rel="icon" type="image/png" sizes="32x32" href="icon-32.png?v=4">
  <link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body>
  <aside id="sidebar">
    <header class="brand">
      <h1>RSS</h1>
      <div class="brand-actions">
        <button id="btn-refresh" title="Alle Feeds aktualisieren (r)">&#x21bb;</button>
        <button id="btn-settings" title="Einstellungen">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.5-2-3.5-2.4.8a7.5 7.5 0 0 0-2-1.2l-.4-2.4h-4l-.4 2.4a7.5 7.5 0 0 0-2 1.2l-2.4-.8-2 3.5 2 1.5A7.4 7.4 0 0 0 4.6 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.5 2.4-.8a7.5 7.5 0 0 0 2 1.2l.4 2.4h4l.4-2.4a7.5 7.5 0 0 0 2-1.2l2.4.8 2-3.5-2-1.5c.1-.4.1-.8.1-1.2Z"/>
          </svg>
        </button>
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
      <button type="submit" title="Feed hinzufügen">+</button>
    </form>

    <div class="sidebar-tools">
      <button id="btn-new-folder" type="button" title="Neuer Ordner">
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
          <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
            d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 11v6M9 14h6"/>
        </svg>
        <span>neuer ordner</span>
      </button>
    </div>

    <nav id="feed-list"></nav>

    <footer>
      <label class="toggle">
        <input type="checkbox" id="hide-read"> nur ungelesene
      </label>
      <label class="toggle">
        <input type="checkbox" id="only-starred"> nur markierte
      </label>
    </footer>
  </aside>

  <div id="ctx-sheet" class="hidden" aria-hidden="true">
    <div class="ctx-backdrop"></div>
    <div class="ctx-panel" role="menu">
      <div class="ctx-title"></div>
      <button data-ctx="toggle-read"></button>
      <button data-ctx="toggle-star"></button>
      <button data-ctx="share">teilen</button>
      <button data-ctx="open">im browser öffnen</button>
      <button data-ctx="cancel" class="ctx-cancel">abbrechen</button>
    </div>
  </div>

  <div id="pull-indicator" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="22" height="22"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/></svg>
  </div>

  <main id="content">
    <header id="content-header">
      <div class="content-titles">
        <h2 id="current-feed">Alle Artikel</h2>
        <span id="status"></span>
      </div>
      <div class="content-actions">
        <input type="search" id="search" placeholder="suchen …" aria-label="Suche">
        <button id="btn-mark-all" title="Alles als gelesen markieren">alles gelesen</button>
      </div>
    </header>
    <button id="new-pill" class="hidden" type="button" aria-live="polite"></button>
    <ul id="items"></ul>
  </main>

  <dialog id="settings-dialog">
    <form method="dialog" id="settings-form">
      <h2>einstellungen</h2>

      <label>Cache-Dauer (Min.)
        <input type="number" id="set-ttl" min="1" max="1440" required>
      </label>

      <label>Auto-Refresh im Browser (Min., 0 = aus)
        <input type="number" id="set-refresh" min="0" max="240" required>
      </label>

      <label class="toggle">
        <input type="checkbox" id="set-hideread"> Standard: nur ungelesene anzeigen
      </label>

      <h3>Push-Benachrichtigungen</h3>
      <p class="hint">Bei neuen Artikeln (durch den Cron) bekommst du eine Mitteilung. Erfordert PWA-Installation auf iOS 16.4+.</p>
      <div class="token-row">
        <button type="button" id="btn-push-toggle">aktivieren</button>
        <button type="button" id="btn-push-test" title="Test-Notification senden">testen</button>
      </div>

      <h3>Widget-Token</h3>
      <p class="hint">Wird vom iOS-Widget zum Lesen genutzt. Nicht weitergeben.</p>
      <div class="token-row">
        <input type="text" id="set-token" readonly>
        <button type="button" id="btn-copy-token" title="Kopieren">copy</button>
        <button type="button" id="btn-regen-token" title="Neuen Token erzeugen">neu</button>
      </div>

      <h3>OPML</h3>
      <div class="opml-row">
        <a id="btn-export" href="api.php?action=opml-export">export</a>
        <label class="opml-import">
          <input type="file" id="opml-file" accept=".opml,.xml,text/xml,application/xml">
          <span>import</span>
        </label>
      </div>

      <h3>Tastenkürzel</h3>
      <ul class="shortcuts">
        <li><kbd>j</kbd> / <kbd>k</kbd> nächster / voriger Artikel</li>
        <li><kbd>o</kbd> / <kbd>Enter</kbd> öffnen / schließen</li>
        <li><kbd>m</kbd> als gelesen markieren</li>
        <li><kbd>s</kbd> mit Stern markieren</li>
        <li><kbd>r</kbd> aktualisieren</li>
        <li><kbd>/</kbd> suchen · <kbd>Esc</kbd> schließen</li>
      </ul>

      <div class="dialog-actions">
        <button type="button" id="btn-cancel">abbrechen</button>
        <button type="submit" id="btn-save">speichern</button>
      </div>
    </form>
  </dialog>

  <script src="assets/app.js?v=4"></script>
</body>
</html>
