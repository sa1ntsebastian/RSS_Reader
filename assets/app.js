(() => {
  const API = 'api.php';
  const READ_KEY = 'rss.read';
  const HIDE_READ_KEY = 'rss.hideRead';

  const els = {
    addForm:    document.getElementById('add-form'),
    urlInput:   document.getElementById('feed-url'),
    feedList:   document.getElementById('feed-list'),
    items:      document.getElementById('items'),
    current:    document.getElementById('current-feed'),
    status:     document.getElementById('status'),
    refresh:    document.getElementById('btn-refresh'),
    hideRead:   document.getElementById('hide-read'),
  };

  let state = {
    feeds: [],
    activeId: 'all',
    items: [],
    read: new Set(JSON.parse(localStorage.getItem(READ_KEY) || '[]')),
  };

  els.hideRead.checked = localStorage.getItem(HIDE_READ_KEY) === '1';

  // --- API helpers ---
  async function api(action, opts = {}) {
    const url = `${API}?action=${encodeURIComponent(action)}` +
                (opts.query ? '&' + new URLSearchParams(opts.query) : '');
    const init = { method: opts.method || 'GET' };
    if (opts.body) {
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(opts.body);
    }
    const r = await fetch(url, init);
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
    return data;
  }

  // --- Read-state ---
  function persistRead() {
    localStorage.setItem(READ_KEY, JSON.stringify([...state.read]));
  }
  function markRead(guid) {
    if (state.read.has(guid)) return;
    state.read.add(guid);
    persistRead();
  }
  function toggleRead(guid) {
    if (state.read.has(guid)) state.read.delete(guid);
    else state.read.add(guid);
    persistRead();
  }

  // --- UI render ---
  function setStatus(msg) {
    els.status.textContent = msg || '';
  }

  function unreadCounts() {
    const counts = { all: 0 };
    for (const it of state.items) {
      if (state.read.has(it.guid)) continue;
      counts.all++;
      counts[it.feedId] = (counts[it.feedId] || 0) + 1;
    }
    return counts;
  }

  function countBadge(n) {
    const cls = n > 0 ? 'count' : 'count zero';
    return `<span class="${cls}">${n}</span>`;
  }

  function renderFeeds() {
    const counts = unreadCounts();
    els.feedList.innerHTML = '';

    const all = document.createElement('div');
    all.className = 'feed-item' + (state.activeId === 'all' ? ' active' : '');
    all.innerHTML = `<span class="title">Alle Artikel</span>${countBadge(counts.all)}`;
    all.onclick = () => selectFeed('all');
    els.feedList.appendChild(all);

    for (const f of state.feeds) {
      const c = counts[f.id] || 0;
      const el = document.createElement('div');
      el.className = 'feed-item' + (state.activeId === f.id ? ' active' : '');
      el.innerHTML = `
        <span class="title" title="${escapeAttr(f.url)}">${escapeHtml(f.title)}</span>
        ${countBadge(c)}
        <button class="remove" title="Feed entfernen">&times;</button>`;
      el.querySelector('.title').onclick = () => selectFeed(f.id);
      el.querySelector('.remove').onclick = (e) => {
        e.stopPropagation();
        if (confirm(`„${f.title}" entfernen?`)) removeFeed(f.id);
      };
      els.feedList.appendChild(el);
    }
  }

  function dayLabel(d) {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    const diff = Math.round((today - day) / 86400000);
    if (diff <= 0) return 'Heute';
    if (diff === 1) return 'Gestern';
    if (diff === 2) return 'Vorgestern';
    const sameYear = day.getFullYear() === today.getFullYear();
    return day.toLocaleDateString('de', {
      weekday: 'long', day: '2-digit', month: 'long',
      ...(sameYear ? {} : { year: 'numeric' }),
    });
  }

  function renderItems() {
    const hideRead = els.hideRead.checked;
    let items = state.items;
    if (state.activeId !== 'all') items = items.filter(i => i.feedId === state.activeId);
    if (hideRead) items = items.filter(i => !state.read.has(i.guid));

    setStatus(items.length ? `${items.length} Artikel` : '');

    if (!items.length) {
      els.items.innerHTML = `<div class="empty">${
        state.feeds.length === 0
          ? 'Noch keine Feeds. Füge oben links eine RSS-/Atom-URL hinzu.'
          : 'Keine Artikel.'
      }</div>`;
      return;
    }

    els.items.innerHTML = '';
    let lastDay = null;
    for (const it of items) {
      const date = new Date((it.date || 0) * 1000);
      const day  = dayLabel(date);
      if (day !== lastDay) {
        const header = document.createElement('li');
        header.className = 'day-header';
        header.textContent = day;
        els.items.appendChild(header);
        lastDay = day;
      }

      const li = document.createElement('li');
      li.className = 'entry' + (state.read.has(it.guid) ? ' read' : '');
      li.innerHTML = `
        <a class="open-original" href="${escapeAttr(it.link)}" target="_blank" rel="noopener noreferrer" title="Auf Originalseite öffnen" aria-label="Auf Originalseite öffnen">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M14 4h6v6M20 4l-9 9M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>
          </svg>
        </a>
        <div class="entry-head">
          <span class="feed-name">${escapeHtml(it.feedTitle)}</span>
          <time datetime="${date.toISOString()}">${date.toLocaleTimeString('de', { hour: '2-digit', minute: '2-digit' })}</time>
        </div>
        <h3><button class="title-btn" type="button">${escapeHtml(it.title || '(ohne Titel)')}</button></h3>
        <div class="summary"></div>
        <div class="actions">
          <button data-act="read">${state.read.has(it.guid) ? 'Als ungelesen markieren' : 'Als gelesen markieren'}</button>
        </div>`;

      const summary = li.querySelector('.summary');
      const titleBtn = li.querySelector('.title-btn');
      const readBtn  = li.querySelector('[data-act="read"]');

      const setReadLabel = () => {
        readBtn.textContent = state.read.has(it.guid) ? 'Als ungelesen markieren' : 'Als gelesen markieren';
      };

      const closeAndMarkRead = () => {
        li.classList.remove('open');
        markRead(it.guid);
        li.classList.add('read');
        setReadLabel();
        renderFeeds();
      };

      titleBtn.onclick = async () => {
        if (li.classList.contains('article-loaded')) {
          if (li.classList.contains('open')) {
            closeAndMarkRead();
          } else {
            li.classList.add('open');
          }
          return;
        }
        titleBtn.classList.add('loading');
        try {
          const data = await api('article', { query: { url: it.link } });
          summary.innerHTML = data.html || '<p><em>Kein Inhalt gefunden.</em></p>';
          li.classList.add('article-loaded', 'open');
        } catch (e) {
          if (it.summary && it.summary.trim()) {
            summary.innerHTML = it.summary +
              `<p><em>Vollartikel konnte nicht geladen werden — bitte „Auf Originalseite öffnen" nutzen.</em></p>`;
            li.classList.add('article-loaded', 'open');
          } else {
            alert('Artikel konnte nicht geladen werden: ' + e.message);
          }
        } finally {
          titleBtn.classList.remove('loading');
        }
      };

      readBtn.onclick = () => {
        toggleRead(it.guid);
        const isRead = state.read.has(it.guid);
        li.classList.toggle('read', isRead);
        setReadLabel();
        renderFeeds();
        if (els.hideRead.checked && isRead) li.remove();
      };

      // Click anywhere in an open article (except on links/buttons or while
      // a text selection is active) closes it and marks it read.
      li.addEventListener('click', (e) => {
        if (!li.classList.contains('article-loaded') || !li.classList.contains('open')) return;
        if (e.target.closest('a, button, input, textarea, select, label')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return;
        closeAndMarkRead();
      });

      els.items.appendChild(li);
    }
  }

  // --- helpers ---
  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }
  function escapeAttr(s) { return escapeHtml(s); }

  // --- actions ---
  async function loadFeeds() {
    const data = await api('list');
    state.feeds = data.feeds || [];
    renderFeeds();
  }

  async function loadItems() {
    setStatus('Lädt …');
    try {
      const data = await api('items', { query: { id: 'all' } });
      state.items = data.items || [];
      renderFeeds();
      renderItems();
    } catch (e) {
      setStatus('Fehler: ' + e.message);
    }
  }

  function selectFeed(id) {
    state.activeId = id;
    const f = state.feeds.find(x => x.id === id);
    els.current.textContent = f ? f.title : 'Alle Artikel';
    renderFeeds();
    renderItems();
  }

  async function addFeed(url) {
    setStatus('Feed wird geladen …');
    try {
      await api('add', { method: 'POST', body: { url } });
      els.urlInput.value = '';
      await loadFeeds();
      await loadItems();
    } catch (e) {
      alert('Konnte Feed nicht hinzufügen: ' + e.message);
      setStatus('');
    }
  }

  async function removeFeed(id) {
    await api('remove', { method: 'POST', body: { id } });
    if (state.activeId === id) state.activeId = 'all';
    await loadFeeds();
    await loadItems();
  }

  async function refreshAll() {
    setStatus('Aktualisiert …');
    try {
      await api('refresh', { method: 'POST', body: { id: state.activeId } });
      await loadFeeds();
      await loadItems();
    } catch (e) {
      setStatus('Fehler: ' + e.message);
    }
  }

  // --- events ---
  els.addForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const url = els.urlInput.value.trim();
    if (url) addFeed(url);
  });
  els.refresh.addEventListener('click', refreshAll);
  els.hideRead.addEventListener('change', () => {
    localStorage.setItem(HIDE_READ_KEY, els.hideRead.checked ? '1' : '0');
    renderItems();
  });

  // initial load
  (async () => {
    try {
      await loadFeeds();
      await loadItems();
    } catch (e) {
      setStatus('Fehler beim Laden: ' + e.message);
    }
  })();
})();
