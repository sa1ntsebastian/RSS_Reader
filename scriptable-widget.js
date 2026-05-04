// RSS-Reader Scriptable Widget
// =============================
// 1. Scriptable-App (kostenlos) auf dem iPhone installieren
// 2. Neues Skript "RSS" anlegen, Inhalt einfügen
// 3. CONFIG ausfüllen (URL + Token aus den Reader-Einstellungen)
// 4. Auf Home-Screen: Scriptable-Widget hinzufügen, Skript "RSS" wählen
//
// Wichtig: iOS aktualisiert Widgets selbst (≈ alle 15 Min). Wir bitten das
// System unten höflich um häufigeres Refreshen, garantiert ist's nicht.
// Für ganz frische Daten: Cronjob am Webspace einrichten — siehe README.
//
// Tipp: das Widget selbst kann nicht scrollen (iOS-Limitation). Aber
// ein Tipp aufs *Header-Feld* öffnet das Skript in Scriptable: dort
// wird eine scrollbare, aktualisierbare Liste angezeigt.

const CONFIG = {
  base:   "https://deinedomain.tld/rss",
  token:  "TOKEN_HIER_EINFUEGEN",
  feed:   "all",
  unread: true,
  // Wie oft das Widget refreshen DARF (Minuten). iOS entscheidet endgültig.
  refreshMinutes: 5,
};

const TINT = {
  green:   "#175d3b",
  white:   "#f5f5f7",
  accent:  "#f4b886",
  muted:   "#beb0a7",
  coral:   "#f4a6a6",
  cream:   "#f5efe6",
};

if (config.runsInWidget) {
  const w = await buildWidget();
  Script.setWidget(w);
} else {
  // In-App: zeige scrollbare Liste, jeder Eintrag öffnet Safari
  await showInteractiveList();
}
Script.complete();

// ---------- Widget ----------

async function buildWidget() {
  const w = new ListWidget();
  w.backgroundColor = new Color(TINT.green);
  w.setPadding(14, 14, 14, 14);
  // Hinweis ans System, früher zu aktualisieren (iOS kann es ignorieren)
  w.refreshAfterDate = new Date(Date.now() + CONFIG.refreshMinutes * 60 * 1000);
  // Wenn auf den leeren Bereich getippt wird, Skript in Scriptable öffnen
  w.url = URLScheme.forRunningScript();

  let items = [], error = null;
  try { items = await fetchUnreadItems(); }
  catch (e) { error = String(e.message || e); }

  // Header
  const header = w.addStack();
  header.layoutHorizontally();
  header.centerAlignContent();
  const t = header.addText("RSS");
  t.font = Font.systemFont(15);
  t.textColor = new Color(TINT.white);
  header.addSpacer();
  const time = header.addText(formatTime(new Date()));
  time.font = Font.systemFont(11);
  time.textColor = new Color(TINT.muted);
  w.addSpacer(8);

  if (error) {
    const e = w.addText(error);
    e.font = Font.systemFont(11);
    e.textColor = new Color(TINT.coral);
    return w;
  }
  if (!items.length) {
    const e = w.addText("Keine ungelesenen Artikel");
    e.font = Font.systemFont(12);
    e.textColor = new Color(TINT.muted);
    return w;
  }

  const max = widgetSize();
  for (const it of items.slice(0, max)) {
    const row = w.addStack();
    row.layoutVertically();
    row.url = it.link; // tap aufs einzelne Item öffnet Artikel in Safari
    row.spacing = 2;

    const meta = row.addStack();
    meta.layoutHorizontally();
    const feed = meta.addText((it.feedTitle || "").toLowerCase());
    feed.font = Font.semiboldSystemFont(9);
    feed.textColor = new Color(TINT.accent);
    feed.lineLimit = 1;
    meta.addSpacer();
    const ts = meta.addText(formatTime(new Date(it.date * 1000)));
    ts.font = Font.systemFont(9);
    ts.textColor = new Color(TINT.muted);

    const head = row.addText(it.title || "(ohne Titel)");
    head.font = Font.semiboldSystemFont(13);
    head.textColor = new Color(TINT.white);
    head.lineLimit = 2;

    w.addSpacer(7);
  }
  if (items.length > max) {
    const more = w.addText(`+ ${items.length - max} weitere`);
    more.font = Font.systemFont(10);
    more.textColor = new Color(TINT.muted);
  }
  return w;
}

function widgetSize() {
  switch (config.widgetFamily) {
    case "small": return 3;
    case "large": return 9;
    default:      return 5;
  }
}

// ---------- In-App scrollable list ----------

async function showInteractiveList() {
  const items = await fetchUnreadItems();

  const t = new UITable();
  t.showSeparators = true;

  const head = new UITableRow();
  head.isHeader = true;
  head.height   = 50;
  head.backgroundColor = new Color(TINT.green);
  const hc = head.addText("RSS", `${items.length} ungelesen · tap zum aktualisieren`);
  hc.titleColor    = new Color(TINT.white);
  hc.subtitleColor = new Color(TINT.accent);
  hc.titleFont     = Font.boldSystemFont(20);
  hc.subtitleFont  = Font.systemFont(12);
  head.dismissOnSelect = false;
  head.onSelect = async () => {
    // Re-run the script to refresh the list
    const url = URLScheme.forRunningScript();
    Safari.open(url); // opens Scriptable scheme
  };
  t.addRow(head);

  if (!items.length) {
    const r = new UITableRow();
    r.addText("Keine ungelesenen Artikel", "Zieh-zum-aktualisieren in Scriptable möglich");
    t.addRow(r);
  }

  for (const it of items) {
    const r = new UITableRow();
    r.height = 70;
    const cell = r.addText(
      (it.title || "(ohne Titel)").trim(),
      `${(it.feedTitle || "")} · ${formatTime(new Date(it.date * 1000))}`
    );
    cell.titleFont    = Font.semiboldSystemFont(15);
    cell.subtitleFont = Font.systemFont(11);
    cell.titleColor    = new Color(TINT.white);
    cell.subtitleColor = new Color(TINT.muted);
    r.backgroundColor  = new Color(TINT.green);
    r.dismissOnSelect  = false;
    r.onSelect = async () => {
      if (it.link) Safari.openInApp(it.link, false);
      await markRead(it.guid);
    };
    t.addRow(r);
  }
  await t.present(true);
}

// ---------- Data ----------

async function fetchUnreadItems() {
  const itemsUrl = `${CONFIG.base}/api.php?action=items&id=${encodeURIComponent(CONFIG.feed)}&token=${encodeURIComponent(CONFIG.token)}`;
  const stateUrl = `${CONFIG.base}/api.php?action=state&token=${encodeURIComponent(CONFIG.token)}`;

  const [data, st] = await Promise.all([
    new Request(itemsUrl).loadJSON(),
    new Request(stateUrl).loadJSON(),
  ]);
  if (data && data.error) throw new Error(data.error);
  const read = new Set(st.read || []);
  let items = (data.items || []);
  if (CONFIG.unread) items = items.filter(i => !read.has(i.guid));
  return items;
}

async function markRead(guid) {
  if (!guid) return;
  // GET fallback so we don't need a session — uses the same token
  const url = `${CONFIG.base}/api.php?action=state&token=${encodeURIComponent(CONFIG.token)}`;
  const r = new Request(url);
  r.method = 'POST';
  r.headers = { 'Content-Type': 'application/json' };
  r.body = JSON.stringify({ op: 'mark-read', guids: [guid] });
  try { await r.loadJSON(); } catch (e) { /* fail silently */ }
}

function formatTime(d) {
  const today = new Date(); today.setHours(0,0,0,0);
  const day   = new Date(d); day.setHours(0,0,0,0);
  const diff  = Math.round((today - day) / 86400000);
  if (diff <= 0) return d.toLocaleTimeString("de", { hour: "2-digit", minute: "2-digit" });
  if (diff === 1) return "gestern";
  if (diff === 2) return "vorgestern";
  return d.toLocaleDateString("de", { day: "2-digit", month: "2-digit" });
}
