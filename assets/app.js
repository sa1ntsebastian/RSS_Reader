(() => {
  const API = 'api.php';

  const els = {
    addForm:    document.getElementById('add-form'),
    urlInput:   document.getElementById('feed-url'),
    feedList:   document.getElementById('feed-list'),
    items:      document.getElementById('items'),
    current:    document.getElementById('current-feed'),
    status:     document.getElementById('status'),
    refresh:    document.getElementById('btn-refresh'),
    settings:   document.getElementById('btn-settings'),
    markAll:    document.getElementById('btn-mark-all'),
    hideRead:   document.getElementById('hide-read'),
    onlyStar:   document.getElementById('only-starred'),
    search:     document.getElementById('search'),
    dialog:     document.getElementById('settings-dialog'),
    setTtl:     document.getElementById('set-ttl'),
    setRefresh: document.getElementById('set-refresh'),
    setHideRead:document.getElementById('set-hideread'),
    setToken:   document.getElementById('set-token'),
    btnCopy:    document.getElementById('btn-copy-token'),
    btnRegen:   document.getElementById('btn-regen-token'),
    btnCancel:  document.getElementById('btn-cancel'),
    btnSave:    document.getElementById('btn-save'),
    btnExport:  document.getElementById('btn-export'),
    opmlFile:   document.getElementById('opml-file'),
  };

  const state = {
    feeds:    [],
    activeId: 'all',
    items:    [],
    read:     new Set(),
    starred:  new Set(),
    settings: { cache_ttl: 900, refresh_interval: 300, hide_read_default: false, api_token: null },
    cursorIdx: -1,         // currently focused entry index
    visibleEntries: [],    // entries currently rendered (after filters)
    refreshTimer: null,
    pendingState: { read: { add: new Set(), remove: new Set() }, star: { add: new Set(), remove: new Set() } },
    pendingFlush: null,
  };

  // ---------- API ----------
  async function api(action, opts = {}) {
    const url = `${API}?action=${encodeURIComponent(action)}` +
                (opts.query ? '&' + new URLSearchParams(opts.query) : '');
    const init = { method: opts.method || 'GET' };
    if (opts.body) {
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(opts.body);
    }
    const r = await fetch(url, init);
    if (r.status === 401) { window.location.href = 'login.php'; throw new Error('not authenticated'); }
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || `HTTP ${r.status}`);
    return data;
  }

  // ---------- read/star sync (debounced) ----------
  function queueState(kind, op, guid) {
    const bucket = state.pendingState[kind];
    const other  = op === 'add' ? bucket.remove : bucket.add;
    other.delete(guid);
    bucket[op].add(guid);
    if (state.pendingFlush) clearTimeout(state.pendingFlush);
    state.pendingFlush = setTimeout(flushState, 600);
  }
  async function flushState() {
    state.pendingFlush = null;
    const ops = [];
    if (state.pendingState.read.add.size)    ops.push(['mark-read',   [...state.pendingState.read.add]]);
    if (state.pendingState.read.remove.size) ops.push(['mark-unread', [...state.pendingState.read.remove]]);
    if (state.pendingState.star.add.size)    ops.push(['star',        [...state.pendingState.star.add]]);
    if (state.pendingState.star.remove.size) ops.push(['unstar',      [...state.pendingState.star.remove]]);
    state.pendingState = { read: { add: new Set(), remove: new Set() }, star: { add: new Set(), remove: new Set() } };
    for (const [op, guids] of ops) {
      try { await api('state', { method: 'POST', body: { op, guids } }); }
      catch (e) { console.warn('state sync failed', e); }
    }
  }

  function markRead(guid)   { if (!state.read.has(guid))    { state.read.add(guid);    queueState('read', 'add',    guid); } }
  function markUnread(guid) { if (state.read.has(guid))     { state.read.delete(guid); queueState('read', 'remove', guid); } }
  function toggleRead(guid) { state.read.has(guid) ? markUnread(guid) : markRead(guid); }
  function toggleStar(guid) {
    if (state.starred.has(guid)) { state.starred.delete(guid); queueState('star', 'remove', guid); }
    else                         { state.starred.add(guid);    queueState('star', 'add',    guid); }
  }

  // ---------- helpers ----------
  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  const escapeAttr = escapeHtml;
  function setStatus(msg) { els.status.textContent = msg || ''; }

  function dayLabel(d) {
    const today = new Date(); today.setHours(0,0,0,0);
    const day   = new Date(d); day.setHours(0,0,0,0);
    const diff  = Math.round((today - day) / 86400000);
    if (diff <= 0) return 'Heute';
    if (diff === 1) return 'Gestern';
    if (diff === 2) return 'Vorgestern';
    const sameYear = day.getFullYear() === today.getFullYear();
    return day.toLocaleDateString('de', { weekday: 'long', day: '2-digit', month: 'long', ...(sameYear ? {} : { year: 'numeric' }) });
  }

  function readingMin(text) {
    if (!text) return 0;
    const words = String(text).replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.round(words / 220));
  }

  // ---------- counts ----------
  function unreadCounts() {
    const counts = { all: 0, folders: {} };
    for (const it of state.items) {
      if (state.read.has(it.guid)) continue;
      counts.all++;
      counts[it.feedId] = (counts[it.feedId] || 0) + 1;
      const fld = (it.folder || '').trim();
      if (fld) counts.folders[fld] = (counts.folders[fld] || 0) + 1;
    }
    return counts;
  }
  function countBadge(n) {
    return `<span class="${n > 0 ? 'count' : 'count zero'}">${n}</span>`;
  }

  // ---------- sidebar ----------
  function setDragPayload(e, payload) {
    try { e.dataTransfer.setData('application/x-rss-drag', JSON.stringify(payload)); } catch {}
    e.dataTransfer.setData('text/plain', JSON.stringify(payload));
  }
  function getDragPayload(e) {
    let raw = '';
    try { raw = e.dataTransfer.getData('application/x-rss-drag'); } catch {}
    if (!raw) raw = e.dataTransfer.getData('text/plain');
    if (!raw) return null;
    try { return JSON.parse(raw); } catch { return { kind: 'feed', id: raw }; } // legacy
  }

  async function assignFeedToFolder(feedId, folder) {
    const f = state.feeds.find(x => x.id === feedId);
    if (!f) return;
    if ((f.folder || '') === folder) return;
    f.folder = folder;
    renderFeeds();
    try { await api('feed-update', { method: 'POST', body: { id: feedId, folder } }); } catch (e) { console.warn(e); }
  }

  async function moveFolderToFolder(srcFolder, dstFolder) {
    if (srcFolder === dstFolder) return;
    const ids = [];
    const srcIds = []; // feeds we're moving
    let dstStartIdx = -1;
    state.feeds.forEach(f => {
      const fld = (f.folder || '').trim();
      if (fld === srcFolder) srcIds.push(f.id);
    });
    if (!srcIds.length) return;
    const remaining = state.feeds.filter(f => !srcIds.includes(f.id));
    // Insert before the first feed of destination folder
    let inserted = false;
    const out = [];
    for (const f of remaining) {
      if (!inserted && (f.folder || '').trim() === dstFolder) {
        for (const id of srcIds) out.push(state.feeds.find(x => x.id === id));
        inserted = true;
      }
      out.push(f);
    }
    if (!inserted) for (const id of srcIds) out.push(state.feeds.find(x => x.id === id));
    state.feeds = out;
    renderFeeds();
    try { await api('reorder', { method: 'POST', body: { ids: state.feeds.map(f => f.id) } }); }
    catch (e) { console.warn(e); }
  }

  function attachDropTarget(el, accept) {
    el.addEventListener('dragover', (e) => { e.preventDefault(); el.classList.add('drag-over'); });
    el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
    el.addEventListener('drop', (e) => {
      e.preventDefault();
      el.classList.remove('drag-over');
      const data = getDragPayload(e);
      if (data) accept(data);
    });
  }

  function renderFeeds() {
    const counts = unreadCounts();
    els.feedList.innerHTML = '';

    const all = document.createElement('div');
    all.className = 'feed-item' + (state.activeId === 'all' ? ' active' : '');
    all.innerHTML = `<span class="title">Alle Artikel</span>${countBadge(counts.all)}`;
    all.onclick = () => selectFeed('all');
    // Drop a feed here to remove it from its folder
    attachDropTarget(all, (data) => {
      if (data.kind === 'feed') assignFeedToFolder(data.id, '');
    });
    els.feedList.appendChild(all);

    // Use insertion order from the feeds array so drag-reorder takes effect.
    // Empty-folder ('no folder') feeds always render first.
    const byFolder = new Map();
    byFolder.set('', []);
    for (const f of state.feeds) {
      const fld = (f.folder || '').trim();
      if (!byFolder.has(fld)) byFolder.set(fld, []);
      byFolder.get(fld).push(f);
    }
    if (!byFolder.get('').length) byFolder.delete('');
    const folderNames = [...byFolder.keys()];

    for (const folder of folderNames) {
      if (folder !== '') {
        const folderId = 'folder:' + folder;
        const h = document.createElement('div');
        h.className = 'folder-header' + (state.activeId === folderId ? ' active' : '');
        h.innerHTML = `<span class="title">${escapeHtml(folder)}</span>${countBadge(counts.folders[folder] || 0)}`;
        h.onclick = () => selectFeed(folderId);
        h.draggable = true;
        h.addEventListener('dragstart', (e) => {
          setDragPayload(e, { kind: 'folder', name: folder });
          h.classList.add('dragging');
        });
        h.addEventListener('dragend', () => h.classList.remove('dragging'));
        attachDropTarget(h, (data) => {
          if (data.kind === 'feed')   assignFeedToFolder(data.id, folder);
          else if (data.kind === 'folder' && data.name !== folder) moveFolderToFolder(data.name, folder);
        });
        els.feedList.appendChild(h);
      }
      for (const f of byFolder.get(folder)) {
        const c = counts[f.id] || 0;
        const el = document.createElement('div');
        el.className = 'feed-item' + (state.activeId === f.id ? ' active' : '');
        el.draggable = true;
        el.dataset.feedId = f.id;
        el.innerHTML = `
          ${f.favicon ? `<img class="favicon" src="${escapeAttr(f.favicon)}" alt="" referrerpolicy="no-referrer" loading="lazy">` : '<span class="favicon placeholder"></span>'}
          <span class="title" title="${escapeAttr(f.url)}">${escapeHtml(f.title)}</span>
          ${countBadge(c)}
          <button class="folder-btn" title="Ordner ändern" aria-label="Ordner ändern">…</button>
          <button class="remove" title="Feed entfernen">&times;</button>`;
        el.querySelector('.title').onclick = () => selectFeed(f.id);
        el.querySelector('.favicon').onclick = () => selectFeed(f.id);
        el.querySelector('.folder-btn').onclick = (e) => { e.stopPropagation(); promptFolder(f); };
        el.querySelector('.remove').onclick = (e) => {
          e.stopPropagation();
          if (confirm(`„${f.title}" entfernen?`)) removeFeed(f.id);
        };
        // Drag & drop: reorder, or drop folder onto a feed = put folder before this feed's group
        el.addEventListener('dragstart', (e) => {
          setDragPayload(e, { kind: 'feed', id: f.id });
          el.classList.add('dragging');
        });
        el.addEventListener('dragend', () => el.classList.remove('dragging'));
        attachDropTarget(el, (data) => {
          if (data.kind === 'feed' && data.id !== f.id) {
            // If dragged feed currently belongs to a different folder, drop also
            // moves it into THIS feed's folder
            const dragged = state.feeds.find(x => x.id === data.id);
            const targetFolder = (f.folder || '').trim();
            if (dragged && (dragged.folder || '').trim() !== targetFolder) {
              dragged.folder = targetFolder;
              api('feed-update', { method: 'POST', body: { id: dragged.id, folder: targetFolder } }).catch(() => {});
            }
            reorderTo(data.id, f.id);
          } else if (data.kind === 'folder') {
            // Dropping a folder onto a feed → move folder right before this feed
            const fld = (f.folder || '').trim();
            if (fld && fld !== data.name) moveFolderToFolder(data.name, fld);
          }
        });
        els.feedList.appendChild(el);
      }
    }
  }

  async function promptFolder(f) {
    const v = prompt(`Ordner für „${f.title}" (leer = ohne Ordner):`, f.folder || '');
    if (v === null) return;
    await api('feed-update', { method: 'POST', body: { id: f.id, folder: v } });
    await loadFeeds();
    renderItems();
  }

  async function reorderTo(draggedId, targetId) {
    const ids = state.feeds.map(f => f.id);
    const from = ids.indexOf(draggedId);
    const to   = ids.indexOf(targetId);
    if (from < 0 || to < 0) return;
    ids.splice(from, 1);
    ids.splice(to, 0, draggedId);
    state.feeds.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
    renderFeeds();
    try { await api('reorder', { method: 'POST', body: { ids } }); } catch (e) { console.warn(e); }
  }

  // ---------- items ----------
  function filteredItems() {
    const q = (els.search.value || '').trim().toLowerCase();
    let items = state.items;
    if (state.activeId.startsWith('folder:')) {
      const folder = state.activeId.slice(7);
      items = items.filter(i => (i.folder || '').trim() === folder);
    } else if (state.activeId !== 'all') {
      items = items.filter(i => i.feedId === state.activeId);
    }
    if (els.onlyStar.checked) items = items.filter(i => state.starred.has(i.guid));
    if (els.hideRead.checked) items = items.filter(i => !state.read.has(i.guid) || state.starred.has(i.guid));
    if (q) {
      items = items.filter(i =>
        (i.title || '').toLowerCase().includes(q) ||
        (i.feedTitle || '').toLowerCase().includes(q) ||
        (i.summary || '').toLowerCase().includes(q));
    }
    return items;
  }

  function renderItems() {
    const items = filteredItems();
    setStatus(items.length ? `${items.length} Artikel` : '');
    state.visibleEntries = [];
    state.cursorIdx = -1;

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
      li.className = 'entry' + (state.read.has(it.guid) ? ' read' : '') + (state.starred.has(it.guid) ? ' starred' : '');
      li.dataset.guid = it.guid;

      const time = date.toLocaleTimeString('de', { hour: '2-digit', minute: '2-digit' });
      const minsRead = readingMin(it.summary);

      li.innerHTML = `
        <a class="open-original" href="${escapeAttr(it.link)}" target="_blank" rel="noopener noreferrer" title="Auf Originalseite öffnen">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
              d="M14 4h6v6M20 4l-9 9M19 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>
          </svg>
        </a>
        <button class="star-btn" title="Mit Stern markieren (s)" aria-label="Mit Stern markieren">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path d="M12 2l3 7h7l-5.5 4.3L18.5 21 12 17l-6.5 4 2-7.7L2 9h7z"
              fill="currentColor" stroke="currentColor" stroke-width="1" stroke-linejoin="round"/>
          </svg>
        </button>
        <div class="entry-head">
          <span class="feed-name">${escapeHtml(it.feedTitle)}</span>
          <time datetime="${date.toISOString()}">${time}${minsRead ? ` · ${minsRead} Min` : ''}</time>
        </div>
        <h3><button class="title-btn" type="button">${escapeHtml(it.title || '(ohne Titel)')}</button></h3>
        <div class="summary"></div>
        <div class="actions">
          <button data-act="read">${state.read.has(it.guid) ? 'als ungelesen' : 'als gelesen'}</button>
        </div>`;

      const summary  = li.querySelector('.summary');
      const titleBtn = li.querySelector('.title-btn');
      const readBtn  = li.querySelector('[data-act="read"]');
      const starBtn  = li.querySelector('.star-btn');

      const setReadLabel = () => readBtn.textContent = state.read.has(it.guid) ? 'als ungelesen' : 'als gelesen';
      const closeAndMarkRead = () => {
        li.classList.remove('open');
        markRead(it.guid);
        li.classList.add('read');
        setReadLabel();
        renderFeeds();
      };
      const openArticle = async () => {
        if (li.classList.contains('article-loaded')) { li.classList.add('open'); return; }
        titleBtn.classList.add('loading');
        try {
          const data = await api('article', { query: { url: it.link } });
          summary.innerHTML = data.html || '<p><em>Kein Inhalt gefunden.</em></p>';
          if (data.reading_min) {
            const t = li.querySelector('time');
            if (t) t.textContent = `${time} · ${data.reading_min} Min`;
          }
          li.classList.add('article-loaded', 'open');
        } catch (e) {
          if (it.summary && it.summary.trim()) {
            summary.innerHTML = it.summary +
              `<p><em>Vollartikel konnte nicht geladen werden — bitte „Auf Originalseite öffnen" nutzen.</em></p>`;
            li.classList.add('article-loaded', 'open');
          } else { alert('Artikel konnte nicht geladen werden: ' + e.message); }
        } finally { titleBtn.classList.remove('loading'); }
      };

      titleBtn.onclick = () => {
        if (li.classList.contains('article-loaded') && li.classList.contains('open')) closeAndMarkRead();
        else openArticle();
      };
      starBtn.onclick = (e) => {
        e.stopPropagation();
        toggleStar(it.guid);
        li.classList.toggle('starred', state.starred.has(it.guid));
      };
      readBtn.onclick = () => {
        toggleRead(it.guid);
        const isRead = state.read.has(it.guid);
        li.classList.toggle('read', isRead);
        setReadLabel();
        renderFeeds();
        if (els.hideRead.checked && isRead && !state.starred.has(it.guid)) li.remove();
      };
      li.addEventListener('click', (e) => {
        if (!li.classList.contains('article-loaded') || !li.classList.contains('open')) return;
        if (e.target.closest('a, button, input, textarea, select, label')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return;
        closeAndMarkRead();
      });

      els.items.appendChild(li);
      state.visibleEntries.push({ li, it, openArticle, closeAndMarkRead });
    }
  }

  // ---------- actions ----------
  async function loadFeeds() {
    const data = await api('list');
    state.feeds = data.feeds || [];
  }
  async function loadState() {
    const s = await api('state');
    state.read    = new Set(s.read    || []);
    state.starred = new Set(s.starred || []);
  }
  async function loadSettings() {
    const s = await api('settings');
    state.settings = { ...state.settings, ...s };
    els.hideRead.checked = !!state.settings.hide_read_default;
    setupAutoRefresh();
  }
  async function loadItems() {
    setStatus('Lädt …');
    try {
      const data = await api('items', { query: { id: 'all' } });
      state.items = data.items || [];
      renderFeeds();
      renderItems();
    } catch (e) { setStatus('Fehler: ' + e.message); }
  }

  function selectFeed(id) {
    state.activeId = id;
    let title = 'Alle Artikel';
    if (id.startsWith('folder:')) {
      title = id.slice(7);
    } else if (id !== 'all') {
      const f = state.feeds.find(x => x.id === id);
      if (f) title = f.title;
    }
    els.current.textContent = title;
    renderFeeds();
    renderItems();
    window.scrollTo({ top: 0 });
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
  async function refreshAll(silent = false) {
    if (!silent) setStatus('Aktualisiert …');
    try {
      await api('refresh', { method: 'POST', body: { id: 'all' } });
      await loadFeeds();
      await loadItems();
    } catch (e) { setStatus('Fehler: ' + e.message); }
  }
  async function markAllRead() {
    const fid = state.activeId;
    const txt = fid === 'all' ? 'Alle Artikel als gelesen markieren?' : 'Diesen Feed als gelesen markieren?';
    if (!confirm(txt)) return;
    await api('state', { method: 'POST', body: { op: 'mark-all-read', feedId: fid } });
    await loadState();
    renderFeeds();
    renderItems();
  }

  function setupAutoRefresh() {
    if (state.refreshTimer) clearInterval(state.refreshTimer);
    const ms = (state.settings.refresh_interval || 0) * 1000;
    if (ms > 0) state.refreshTimer = setInterval(() => refreshAll(true), ms);
  }

  // ---------- settings dialog ----------
  function openSettings() {
    els.setTtl.value      = Math.round((state.settings.cache_ttl || 900) / 60);
    els.setRefresh.value  = Math.round((state.settings.refresh_interval || 0) / 60);
    els.setHideRead.checked = !!state.settings.hide_read_default;
    els.setToken.value    = state.settings.api_token || '';
    if (typeof els.dialog.showModal === 'function') els.dialog.showModal();
    else els.dialog.setAttribute('open', '');
  }
  function closeSettings() {
    if (typeof els.dialog.close === 'function') els.dialog.close();
    else els.dialog.removeAttribute('open');
  }
  els.settings.onclick = openSettings;
  els.btnCancel.onclick = closeSettings;
  document.getElementById('settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      const s = await api('settings', { method: 'POST', body: {
        cache_ttl: Math.max(60, parseInt(els.setTtl.value, 10) * 60),
        refresh_interval: Math.max(0, parseInt(els.setRefresh.value, 10) * 60),
        hide_read_default: els.setHideRead.checked,
      }});
      state.settings = { ...state.settings, ...s };
      setupAutoRefresh();
      closeSettings();
    } catch (err) { alert('Konnte nicht speichern: ' + err.message); }
  });
  els.btnCopy.onclick = async () => {
    try { await navigator.clipboard.writeText(els.setToken.value); els.btnCopy.textContent = 'kopiert ✓'; setTimeout(() => els.btnCopy.textContent = 'copy', 1500); }
    catch { els.setToken.select(); }
  };
  els.btnRegen.onclick = async () => {
    if (!confirm('Neuen Widget-Token erzeugen? Bestehende Widgets müssen aktualisiert werden.')) return;
    const r = await api('regenerate-token', { method: 'POST' });
    state.settings.api_token = r.token;
    els.setToken.value = r.token;
  };

  els.opmlFile.addEventListener('change', async () => {
    const file = els.opmlFile.files[0];
    if (!file) return;
    const xml = await file.text();
    try {
      const r = await api('opml-import', { method: 'POST', body: { opml: xml } });
      alert(`${r.added} Feeds importiert (insgesamt ${r.total}).`);
      els.opmlFile.value = '';
      await loadFeeds();
      await loadItems();
      closeSettings();
    } catch (e) { alert('Import fehlgeschlagen: ' + e.message); }
  });

  // ---------- keyboard ----------
  function focusEntry(idx) {
    if (!state.visibleEntries.length) return;
    idx = Math.max(0, Math.min(state.visibleEntries.length - 1, idx));
    state.cursorIdx = idx;
    document.querySelectorAll('.entry.cursor').forEach(e => e.classList.remove('cursor'));
    const cur = state.visibleEntries[idx].li;
    cur.classList.add('cursor');
    cur.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  document.addEventListener('keydown', (e) => {
    if (e.target.matches('input, textarea, select')) {
      if (e.key === 'Escape' && e.target === els.search) { els.search.blur(); }
      return;
    }
    if (els.dialog.open) return;
    if (e.metaKey || e.ctrlKey || e.altKey) return;

    switch (e.key) {
      case 'j': focusEntry(state.cursorIdx + 1); e.preventDefault(); break;
      case 'k': focusEntry(state.cursorIdx - 1); e.preventDefault(); break;
      case 'o':
      case 'Enter': {
        const cur = state.visibleEntries[state.cursorIdx];
        if (cur) {
          if (cur.li.classList.contains('article-loaded') && cur.li.classList.contains('open')) cur.closeAndMarkRead();
          else cur.openArticle();
        }
        e.preventDefault();
        break;
      }
      case 'm': {
        const cur = state.visibleEntries[state.cursorIdx];
        if (cur) {
          toggleRead(cur.it.guid);
          cur.li.classList.toggle('read', state.read.has(cur.it.guid));
          renderFeeds();
        }
        e.preventDefault(); break;
      }
      case 's': {
        const cur = state.visibleEntries[state.cursorIdx];
        if (cur) { toggleStar(cur.it.guid); cur.li.classList.toggle('starred', state.starred.has(cur.it.guid)); }
        e.preventDefault(); break;
      }
      case 'r': refreshAll(); e.preventDefault(); break;
      case '/': els.search.focus(); els.search.select(); e.preventDefault(); break;
      case 'Escape': {
        const cur = state.visibleEntries[state.cursorIdx];
        if (cur && cur.li.classList.contains('open')) cur.closeAndMarkRead();
        break;
      }
    }
  });

  // ---------- events ----------
  els.addForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const url = els.urlInput.value.trim();
    if (url) addFeed(url);
  });
  els.refresh.addEventListener('click', () => refreshAll());
  els.markAll.addEventListener('click', markAllRead);
  els.hideRead.addEventListener('change', renderItems);
  els.onlyStar.addEventListener('change', renderItems);

  let searchT;
  els.search.addEventListener('input', () => {
    clearTimeout(searchT);
    searchT = setTimeout(renderItems, 120);
  });

  window.addEventListener('beforeunload', () => { if (state.pendingFlush) flushState(); });

  // ---------- init ----------
  (async () => {
    try {
      await loadSettings();
      await loadState();
      await loadFeeds();
      await loadItems();
    } catch (e) { setStatus('Fehler beim Laden: ' + e.message); }
  })();
})();
