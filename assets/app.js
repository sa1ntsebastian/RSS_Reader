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
    btnNewFolder: document.getElementById('btn-new-folder'),
    newPill:    document.getElementById('new-pill'),
    contentHeader: document.getElementById('content-header'),
  };

  const COLLAPSED_KEY = 'rss.collapsedFolders';
  const READMODE_KEY  = 'rss.readMode';     // { theme: 'sepia'|'light'|'dark', size: 0..4 }

  function loadReadMode() {
    try {
      const v = JSON.parse(localStorage.getItem(READMODE_KEY) || '{}');
      return { theme: v.theme || 'sepia', size: typeof v.size === 'number' ? v.size : 1 };
    } catch { return { theme: 'sepia', size: 1 }; }
  }
  function saveReadMode(m) { localStorage.setItem(READMODE_KEY, JSON.stringify(m)); }
  let readMode = loadReadMode();

  function applyReadMode() {
    document.body.dataset.readTheme = readMode.theme;
    document.body.dataset.readSize  = String(readMode.size);
  }
  applyReadMode();

  function syncHeaderHeight() {
    const h = els.contentHeader?.getBoundingClientRect().height || 64;
    document.documentElement.style.setProperty('--header-h', Math.ceil(h) + 'px');
  }
  window.addEventListener('resize', syncHeaderHeight);
  if (window.ResizeObserver && els.contentHeader) {
    new ResizeObserver(syncHeaderHeight).observe(els.contentHeader);
  }
  // initial measurement happens after render flush
  requestAnimationFrame(syncHeaderHeight);
  const state = {
    feeds:    [],
    folders:  [],
    activeId: 'all',
    items:    [],
    read:     new Set(),
    starred:  new Set(),
    settings: { cache_ttl: 900, refresh_interval: 300, hide_read_default: false, api_token: null },
    cursorIdx: -1,
    visibleEntries: [],
    refreshTimer: null,
    pendingState: { read: { add: new Set(), remove: new Set() }, star: { add: new Set(), remove: new Set() } },
    pendingFlush: null,
    collapsedFolders: new Set(JSON.parse(localStorage.getItem(COLLAPSED_KEY) || '[]')),
    pendingItems: null, // items fetched in background, awaiting user "show" click
  };

  function toggleFolderCollapsed(name) {
    if (state.collapsedFolders.has(name)) state.collapsedFolders.delete(name);
    else state.collapsedFolders.add(name);
    localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...state.collapsedFolders]));
    renderFeeds();
  }

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
    if (folder && !state.folders.includes(folder)) state.folders.push(folder);
    renderFeeds();
    try { await api('feed-update', { method: 'POST', body: { id: feedId, folder } }); } catch (e) { console.warn(e); }
  }

  async function createFolder() {
    const name = prompt('Name des neuen Ordners:');
    if (name === null) return;
    const trimmed = name.trim();
    if (trimmed === '') return;
    if (state.folders.includes(trimmed)) {
      selectFeed('folder:' + trimmed);
      return;
    }
    state.folders.push(trimmed);
    renderFeeds();
    try { await api('folder-create', { method: 'POST', body: { name: trimmed } }); }
    catch (e) { alert('Konnte Ordner nicht anlegen: ' + e.message); }
  }

  async function renameFolder(oldName) {
    const name = prompt(`Ordner umbenennen:`, oldName);
    if (name === null) return;
    const to = name.trim();
    if (to === '' || to === oldName) return;
    try {
      await api('folder-rename', { method: 'POST', body: { from: oldName, to } });
      if (state.activeId === 'folder:' + oldName) state.activeId = 'folder:' + to;
      await loadFeeds();
      renderFeeds();
      renderItems();
    } catch (e) { alert('Umbenennen fehlgeschlagen: ' + e.message); }
  }

  async function deleteFolder(name) {
    const c = state.feeds.filter(f => (f.folder || '') === name).length;
    const msg = c > 0
      ? `Ordner „${name}" löschen? Die ${c} Feeds darin bleiben erhalten (ohne Ordner).`
      : `Leeren Ordner „${name}" löschen?`;
    if (!confirm(msg)) return;
    try {
      await api('folder-delete', { method: 'POST', body: { name } });
      if (state.activeId === 'folder:' + name) state.activeId = 'all';
      await loadFeeds();
      renderFeeds();
      renderItems();
    } catch (e) { alert('Löschen fehlgeschlagen: ' + e.message); }
  }

  async function moveFolderInList(srcFolder, dstFolder) {
    if (srcFolder === dstFolder) return;
    const list = [...state.folders];
    const from = list.indexOf(srcFolder);
    const to   = list.indexOf(dstFolder);
    if (from < 0 || to < 0) return;
    list.splice(from, 1);
    list.splice(to, 0, srcFolder);
    state.folders = list;
    renderFeeds();
    try { await api('folders-reorder', { method: 'POST', body: { names: list } }); }
    catch (e) { console.warn(e); }
  }

  // Folder reorder is now an explicit folder list operation.
  const moveFolderToFolder = moveFolderInList;

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

    // Folder display order is the explicit list from the server; empty-folder
    // ('no folder') feeds render first. Any folder still referenced by a feed
    // but missing from the explicit list is appended.
    const byFolder = new Map();
    byFolder.set('', []);
    for (const name of state.folders) byFolder.set(name, []);
    for (const f of state.feeds) {
      const fld = (f.folder || '').trim();
      if (!byFolder.has(fld)) byFolder.set(fld, []);
      byFolder.get(fld).push(f);
    }
    if (!byFolder.get('').length) byFolder.delete('');
    const folderNames = [...byFolder.keys()];

    for (const folder of folderNames) {
      const collapsed = folder !== '' && state.collapsedFolders.has(folder);
      if (folder !== '') {
        const folderId = 'folder:' + folder;
        const h = document.createElement('div');
        h.className = 'folder-header' + (state.activeId === folderId ? ' active' : '') + (collapsed ? ' collapsed' : '');
        h.innerHTML = `
          <button class="folder-toggle" title="${collapsed ? 'Aufklappen' : 'Einklappen'}" aria-label="Ordner ein-/ausklappen">
            <svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true">
              <path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M8 5l8 7-8 7"/>
            </svg>
          </button>
          <span class="title">${escapeHtml(folder)}</span>
          ${countBadge(counts.folders[folder] || 0)}
          <button class="folder-rename" title="Umbenennen" aria-label="Umbenennen">✎</button>
          <button class="folder-delete" title="Ordner löschen" aria-label="Ordner löschen">×</button>`;
        h.querySelector('.folder-toggle').onclick = (e) => { e.stopPropagation(); toggleFolderCollapsed(folder); };
        h.querySelector('.title').onclick = () => selectFeed(folderId);
        h.querySelector('.count').onclick = () => selectFeed(folderId);
        h.querySelector('.folder-rename').onclick = (e) => { e.stopPropagation(); renameFolder(folder); };
        h.querySelector('.folder-delete').onclick = (e) => { e.stopPropagation(); deleteFolder(folder); };
        h.draggable = true;
        h.addEventListener('dragstart', (e) => {
          setDragPayload(e, { kind: 'folder', name: folder });
          h.classList.add('dragging');
        });
        h.addEventListener('dragend', () => h.classList.remove('dragging'));
        attachDropTarget(h, (data) => {
          // Auto-expand when dropping a feed into a collapsed folder
          if (data.kind === 'feed') {
            if (state.collapsedFolders.has(folder)) {
              state.collapsedFolders.delete(folder);
              localStorage.setItem(COLLAPSED_KEY, JSON.stringify([...state.collapsedFolders]));
            }
            assignFeedToFolder(data.id, folder);
          } else if (data.kind === 'folder' && data.name !== folder) {
            moveFolderToFolder(data.name, folder);
          }
        });
        els.feedList.appendChild(h);
      }
      if (collapsed) continue;
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
    await api('feed-update', { method: 'POST', body: { id: f.id, folder: v.trim() } });
    await loadFeeds();
    renderFeeds();
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
      const injectReadToolbar = () => {
        if (li.querySelector('.read-toolbar')) return;
        const tb = document.createElement('div');
        tb.className = 'read-toolbar';
        tb.innerHTML = `
          <div class="rt-group" role="group" aria-label="Schriftgröße">
            <button data-rt="size-down" aria-label="Kleiner">A−</button>
            <button data-rt="size-up"   aria-label="Größer">A+</button>
          </div>
          <div class="rt-group" role="group" aria-label="Lesefarbe">
            <button data-rt="theme-sepia" title="Sepia">●</button>
            <button data-rt="theme-light" title="Hell">○</button>
            <button data-rt="theme-dark"  title="Dunkel">◐</button>
          </div>`;
        tb.addEventListener('click', (e) => {
          const t = e.target.closest('button')?.dataset.rt;
          if (!t) return;
          e.stopPropagation();
          if (t === 'size-up')   readMode.size = Math.min(4, readMode.size + 1);
          if (t === 'size-down') readMode.size = Math.max(0, readMode.size - 1);
          if (t.startsWith('theme-')) readMode.theme = t.slice(6);
          saveReadMode(readMode);
          applyReadMode();
        });
        summary.parentNode.insertBefore(tb, summary);
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
          injectReadToolbar();
          li.classList.add('article-loaded', 'open');
        } catch (e) {
          if (it.summary && it.summary.trim()) {
            summary.innerHTML = it.summary +
              `<p><em>Vollartikel konnte nicht geladen werden — bitte „Auf Originalseite öffnen" nutzen.</em></p>`;
            injectReadToolbar();
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
        // Ignore clicks on actionable controls or while a selection is active
        if (e.target.closest('a, button, input, textarea, select, label')) return;
        if (window.getSelection && String(window.getSelection()).length > 0) return;
        if (li.classList.contains('article-loaded') && li.classList.contains('open')) {
          closeAndMarkRead();
        } else {
          openArticle();
        }
      });

      attachSwipe(li, it, { closeAndMarkRead, setReadLabel });

      els.items.appendChild(li);
      state.visibleEntries.push({ li, it, openArticle, closeAndMarkRead });
    }
  }

  function attachSwipe(li, it, ctx) {
    let startX = 0, startY = 0, dx = 0, dy = 0, active = false, decided = false;
    const TH = 60; // px to commit
    const setOffset = (x) => { li.style.transform = x ? `translateX(${x}px)` : ''; };
    const setHint = (cls) => {
      li.classList.remove('swipe-read', 'swipe-star');
      if (cls) li.classList.add(cls);
    };

    li.addEventListener('touchstart', (e) => {
      if (li.classList.contains('open')) return;
      if (e.touches.length !== 1) return;
      startX = e.touches[0].clientX;
      startY = e.touches[0].clientY;
      dx = 0; dy = 0; active = true; decided = false;
    }, { passive: true });

    li.addEventListener('touchmove', (e) => {
      if (!active) return;
      dx = e.touches[0].clientX - startX;
      dy = e.touches[0].clientY - startY;
      if (!decided) {
        if (Math.abs(dy) > Math.abs(dx) && Math.abs(dy) > 8) { active = false; return; }
        if (Math.abs(dx) > 8) decided = true;
      }
      if (decided) {
        e.preventDefault();
        setOffset(dx);
        setHint(dx < -TH ? 'swipe-read' : dx > TH ? 'swipe-star' : '');
      }
    }, { passive: false });

    li.addEventListener('touchend', () => {
      if (!active) return;
      active = false;
      const committed = Math.abs(dx) > TH;
      setOffset(0);
      setHint('');
      if (!committed) return;
      if (dx < 0) {
        // left swipe → mark read & close
        ctx.closeAndMarkRead();
      } else {
        // right swipe → toggle star
        toggleStar(it.guid);
        li.classList.toggle('starred', state.starred.has(it.guid));
      }
    });
    li.addEventListener('touchcancel', () => { active = false; setOffset(0); setHint(''); });
  }

  // ---------- actions ----------
  async function loadFeeds() {
    const data = await api('list');
    state.feeds   = data.feeds   || [];
    state.folders = data.folders || [];
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

  /**
   * Background poll: fetches latest items + state without disturbing the
   * scroll position. New items go to a pending list and a pill appears at
   * the top inviting the user to merge them.
   */
  async function backgroundSync() {
    try {
      const [itemsRes, stateRes] = await Promise.all([
        api('items', { query: { id: 'all' } }),
        api('state'),
      ]);
      // 1) State (read/starred) — apply to live state. Updates badges
      //    and dim/star the visible cards in place.
      const newRead    = new Set(stateRes.read    || []);
      const newStarred = new Set(stateRes.starred || []);
      const stateChanged = !setsEqual(newRead, state.read) || !setsEqual(newStarred, state.starred);
      if (stateChanged) {
        state.read    = newRead;
        state.starred = newStarred;
        applyStateToVisibleEntries();
        renderFeeds();
      }

      // 2) Items — only show pill if there are *new* guids
      const fresh = itemsRes.items || [];
      const knownGuids = new Set(state.items.map(i => i.guid));
      const newOnes = fresh.filter(i => !knownGuids.has(i.guid));
      if (newOnes.length > 0) {
        state.pendingItems = fresh;
        showNewPill(newOnes.length);
      } else if (fresh.length !== state.items.length) {
        // Items disappeared (rotated out of feed). Quietly resync.
        state.items = fresh;
        renderFeeds(); // counts may have changed
      }
    } catch (e) {
      // silent on background poll
      console.warn('background sync', e);
    }
  }

  function setsEqual(a, b) {
    if (a.size !== b.size) return false;
    for (const x of a) if (!b.has(x)) return false;
    return true;
  }

  function applyStateToVisibleEntries() {
    for (const entry of state.visibleEntries) {
      const isRead = state.read.has(entry.it.guid);
      const isStar = state.starred.has(entry.it.guid);
      entry.li.classList.toggle('read', isRead);
      entry.li.classList.toggle('starred', isStar);
      const readBtn = entry.li.querySelector('[data-act="read"]');
      if (readBtn) readBtn.textContent = isRead ? 'als ungelesen' : 'als gelesen';
    }
  }

  function showNewPill(count) {
    els.newPill.textContent = `${count} neue${count === 1 ? 'r' : ''} Artikel — anzeigen`;
    els.newPill.classList.remove('hidden');
  }
  function hideNewPill() { els.newPill.classList.add('hidden'); }

  function applyPendingItems() {
    if (!state.pendingItems) return;
    state.items = state.pendingItems;
    state.pendingItems = null;
    hideNewPill();
    renderFeeds();
    renderItems();
    window.scrollTo({ top: 0, behavior: 'smooth' });
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
    if (ms > 0) state.refreshTimer = setInterval(backgroundSync, ms);
  }

  // Sync state back from the server when the tab regains focus — picks up
  // anything the iPhone widget or another browser already marked.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') backgroundSync();
  });

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
  els.btnNewFolder.addEventListener('click', createFolder);
  els.newPill.addEventListener('click', applyPendingItems);
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
