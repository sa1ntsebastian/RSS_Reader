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

  function renderFeeds() {
    els.feedList.innerHTML = '';
    const all = document.createElement('div');
    all.className = 'feed-item' + (state.activeId === 'all' ? ' active' : '');
    all.innerHTML = `<span class="title">Alle Artikel</span>`;
    all.onclick = () => selectFeed('all');
    els.feedList.appendChild(all);

    for (const f of state.feeds) {
      const el = document.createElement('div');
      el.className = 'feed-item' + (state.activeId === f.id ? ' active' : '');
      el.innerHTML = `
        <span class="title" title="${escapeAttr(f.url)}">${escapeHtml(f.title)}</span>
        <button class="remove" title="Feed entfernen">&times;</button>`;
      el.querySelector('.title').onclick = () => selectFeed(f.id);
      el.querySelector('.remove').onclick = (e) => {
        e.stopPropagation();
        if (confirm(`„${f.title}" entfernen?`)) removeFeed(f.id);
      };
      els.feedList.appendChild(el);
    }
  }

  function renderItems() {
    const hideRead = els.hideRead.checked;
    const items = hideRead ? state.items.filter(i => !state.read.has(i.guid)) : state.items;

    if (!items.length) {
      els.items.innerHTML = `<div class="empty">${
        state.feeds.length === 0
          ? 'Noch keine Feeds. Füge oben links eine RSS-/Atom-URL hinzu.'
          : 'Keine Artikel.'
      }</div>`;
      return;
    }

    els.items.innerHTML = '';
    for (const it of items) {
      const li = document.createElement('li');
      li.className = 'entry' + (state.read.has(it.guid) ? ' read' : '');
      const date = new Date((it.date || 0) * 1000);
      li.innerHTML = `
        <div class="entry-head">
          <span class="feed-name">${escapeHtml(it.feedTitle)}</span>
          <time datetime="${date.toISOString()}">${formatDate(date)}</time>
        </div>
        <h3><a href="${escapeAttr(it.link)}" target="_blank" rel="noopener noreferrer">${escapeHtml(it.title || '(ohne Titel)')}</a></h3>
        <div class="summary">${it.summary || ''}</div>
        <div class="actions">
          <button data-act="toggle">Vorschau</button>
          <button data-act="read">${state.read.has(it.guid) ? 'Als ungelesen markieren' : 'Als gelesen markieren'}</button>
        </div>`;
      const summary = li.querySelector('.summary');
      li.querySelector('[data-act="toggle"]').onclick = () => {
        li.classList.toggle('open');
        // also mark as read on open
        if (li.classList.contains('open')) {
          markRead(it.guid);
          li.classList.add('read');
          li.querySelector('[data-act="read"]').textContent = 'Als ungelesen markieren';
        }
      };
      li.querySelector('[data-act="read"]').onclick = (e) => {
        toggleRead(it.guid);
        const isRead = state.read.has(it.guid);
        li.classList.toggle('read', isRead);
        e.target.textContent = isRead ? 'Als ungelesen markieren' : 'Als gelesen markieren';
        if (els.hideRead.checked && isRead) li.remove();
      };
      // mark as read when the user clicks the title link
      li.querySelector('h3 a').addEventListener('click', () => {
        markRead(it.guid);
        li.classList.add('read');
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
  function formatDate(d) {
    const now = new Date();
    const diffH = (now - d) / 36e5;
    if (diffH < 24) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (diffH < 24 * 7) return d.toLocaleDateString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString();
  }

  // --- actions ---
  async function loadFeeds() {
    const data = await api('list');
    state.feeds = data.feeds || [];
    renderFeeds();
  }

  async function loadItems() {
    setStatus('Lädt …');
    try {
      const data = await api('items', { query: { id: state.activeId } });
      state.items = data.items || [];
      renderItems();
      setStatus(`${state.items.length} Artikel`);
    } catch (e) {
      setStatus('Fehler: ' + e.message);
    }
  }

  function selectFeed(id) {
    state.activeId = id;
    const f = state.feeds.find(x => x.id === id);
    els.current.textContent = f ? f.title : 'Alle Artikel';
    renderFeeds();
    loadItems();
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
