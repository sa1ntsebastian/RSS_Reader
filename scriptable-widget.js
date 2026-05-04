// RSS-Reader Scriptable Widget
// =============================
// 1. Scriptable App (kostenlos) auf dem iPhone installieren
// 2. Diese Datei in Scriptable importieren (oder Inhalt einfügen + Skript "RSS" nennen)
// 3. CONFIG unten ausfüllen (URL + Token aus den Reader-Einstellungen kopieren)
// 4. Auf den Home-Screen: Scriptable-Widget hinzufügen, Skript "RSS" wählen
//    und Größe (Klein / Mittel / Groß) festlegen
//
// Tipp: In den Widget-Einstellungen "When Interacting" → "Open URL" lassen,
// dann öffnet ein Tipp den jeweiligen Artikel.

const CONFIG = {
  base:   "https://deinedomain.tld/rss",   // Pfad zum Reader (ohne Schrägstrich am Ende)
  token:  "TOKEN_HIER_EINFUEGEN",          // aus den Einstellungen → Widget-Token
  feed:   "all",                            // "all" oder feedId
  unread: true,                             // nur ungelesene
};

const TINT = {
  green:   "#175d3b",
  white:   "#f5f5f7",
  cream:   "#f5efe6",
  accent:  "#f4b886",
  muted:   "#beb0a7",
  coral:   "#f4a6a6",
};

const widget = await buildWidget();
if (config.runsInWidget) Script.setWidget(widget);
else                     widget.presentMedium();
Script.complete();

async function buildWidget() {
  const w = new ListWidget();
  w.backgroundColor = new Color(TINT.green);
  w.setPadding(14, 14, 14, 14);

  let items = [];
  let error = null;
  try { items = await fetchItems(); } catch (e) { error = String(e); }

  // Header
  const header = w.addStack();
  header.layoutHorizontally();
  header.centerAlignContent();
  const title = header.addText("RSS");
  title.font = Font.systemFont(15);
  title.textColor = new Color(TINT.white);
  header.addSpacer();
  const time = header.addText(formatTime(new Date()));
  time.font = Font.systemFont(11);
  time.textColor = new Color(TINT.muted);
  w.addSpacer(8);

  if (error) {
    const t = w.addText(error);
    t.font = Font.systemFont(11);
    t.textColor = new Color(TINT.coral);
    return w;
  }
  if (!items.length) {
    const t = w.addText("Keine ungelesenen Artikel");
    t.font = Font.systemFont(12);
    t.textColor = new Color(TINT.muted);
    return w;
  }

  const max = widgetSize();
  for (const it of items.slice(0, max)) {
    const row = w.addStack();
    row.layoutVertically();
    row.url = it.link;
    row.spacing = 2;

    const meta = row.addStack();
    meta.layoutHorizontally();
    const feed = meta.addText((it.feedTitle || "").toLowerCase());
    feed.font = Font.semiboldSystemFont(9);
    feed.textColor = new Color(TINT.accent);
    feed.lineLimit = 1;
    meta.addSpacer();
    const t = meta.addText(formatTime(new Date(it.date * 1000)));
    t.font = Font.systemFont(9);
    t.textColor = new Color(TINT.muted);

    const head = row.addText(it.title || "(ohne Titel)");
    head.font = Font.semiboldSystemFont(13);
    head.textColor = new Color(TINT.white);
    head.lineLimit = 2;

    w.addSpacer(7);
  }
  return w;
}

function widgetSize() {
  switch (config.widgetFamily) {
    case "small":  return 3;
    case "large":  return 9;
    case "medium":
    default:       return 5;
  }
}

async function fetchItems() {
  const url = `${CONFIG.base}/api.php?action=items&id=${encodeURIComponent(CONFIG.feed)}&token=${encodeURIComponent(CONFIG.token)}`;
  const stateUrl = `${CONFIG.base}/api.php?action=state&token=${encodeURIComponent(CONFIG.token)}`;
  const [data, state] = await Promise.all([
    new Request(url).loadJSON(),
    new Request(stateUrl).loadJSON(),
  ]);
  if (data.error) throw new Error(data.error);
  const read = new Set(state.read || []);
  let items = data.items || [];
  if (CONFIG.unread) items = items.filter(i => !read.has(i.guid));
  return items;
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
