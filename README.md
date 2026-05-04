# RSS Reader

Ein leichtgewichtiger Web-RSS-Reader in PHP — keine Datenbank, keine Build-Tools.
Auf einen PHP-Webspace hochladen, im Browser aufrufen, Konto anlegen, fertig.

## Features

- **Login + Session** mit Erstkonfiguration via Setup-Seite
- Feeds (RSS 2.0 / RSS 1.0 RDF / Atom) hinzufügen, entfernen, **Drag-&-Drop sortieren**, **Ordner**
- **Artikel inline lesen** (Volltext-Extraktion mit Lese-Modus, cremefarbener Hintergrund)
- Artikel **als gelesen / mit Stern** markieren — **serverseitig synchronisiert** (geräteübergreifend)
- **Tagesgruppierung** („Heute / Gestern / Vorgestern / Wochentag, Datum"), sticky Header beim Scrollen
- **Ungelesen-Badges** pro Feed in der Sidebar
- **Suche** über alle Artikel (Titel, Feed, Vorschau)
- **Tastenkürzel** (j/k/o/m/s/r/Enter/Esc/`/`)
- „Alle als gelesen" Knopf, Filter „nur ungelesene" / „nur markierte"
- **OPML-Import/-Export** (kompatibel mit anderen Readern)
- **Auto-Refresh** im Browser (konfigurierbares Intervall)
- **Parallele Feed-Aktualisierung** via curl_multi
- **PWA-Manifest** (Add to Home Screen wirkt wie eine App)
- **iOS-Widget** über Scriptable (siehe unten)
- Hell/Dunkel-Look in deinem grünen/etoile-Theme, Pastell-Akzente
- Server-seitiger Cache (konfigurierbare TTL) — schont fremde Server

## Voraussetzungen

- PHP **7.4+** (empfohlen 8.x)
- Module: `simplexml`, `curl` (oder `allow_url_fopen=On`), `mbstring`, `dom`
- Schreibrechte für den Hauptordner (für `config.php`) und `data/`

## Installation

1. Inhalte per FTP/SFTP auf den Webspace laden, z. B. nach `/rss/`.
2. `data/` und ggf. `data/cache/` mit CHMOD `775` (oder `777`) versehen, damit PHP schreiben kann.
3. Hauptordner schreibbar machen, damit beim Setup `config.php` angelegt werden kann.
4. `https://deinedomain.tld/rss/` im Browser öffnen.
5. **Erstkonfiguration**: Benutzername + Passwort (min. 8 Zeichen) festlegen.
6. Loslegen: Feed-URL eintragen (z. B. `https://rss.orf.at/news.xml`), Enter.

### Etoile-Schrift

Die Schrift ist nicht im Repo. Lade die Dateien optional nach:
```
assets/etoile_font/Etoile-Regular.woff
assets/etoile_font/Etoile-Regular.woff2
```
Ohne die Dateien fällt das Theme auf System-Sans-Serif zurück (alles funktioniert weiterhin).

## Tastenkürzel

| Taste | Aktion |
| --- | --- |
| `j` / `k` | Nächster / voriger Artikel |
| `o` oder `Enter` | Öffnen / schließen |
| `m` | Als gelesen / ungelesen markieren |
| `s` | Mit Stern markieren / entfernen |
| `r` | Alle Feeds aktualisieren |
| `/` | Suche fokussieren |
| `Esc` | Aktiven Artikel schließen / Suche verlassen |

## iOS-Widget (Scriptable)

1. **Scriptable** (kostenlos) aus dem App Store installieren.
2. Im Reader: Einstellungen → **Widget-Token** kopieren.
3. In Scriptable: neues Skript anlegen, Inhalt von [`scriptable-widget.js`](scriptable-widget.js) einfügen.
4. Im Skript oben `CONFIG.base` (URL deines Readers, ohne abschließenden `/`) und `CONFIG.token` ausfüllen.
5. Skript einmal ausführen — du solltest die Vorschau sehen.
6. Auf dem Home-Screen: Widget hinzufügen → **Scriptable** → Größe wählen → Skript „RSS" auswählen.
7. Tipp aufs Widget öffnet entweder Scriptable oder direkt den Artikel
   (Widget-Konfiguration: When Interacting → **Open URL**).

Das Widget unterstützt Klein (3 Headlines), Mittel (5) und Groß (9). Aktualisierung
wird vom System gesteuert (≈ alle 15–60 Min).

## Datei-Übersicht

```
index.php                    – Haupt-UI
api.php                      – JSON-API
auth.php                     – Session/Login-Helfer
login.php / logout.php       – Login + Setup
manifest.webmanifest         – PWA-Manifest
assets/style.css             – Theme
assets/app.js                – Frontend-Logik
assets/icon.svg              – App-Icon (für PWA + favicon)
data/feeds.json              – Feed-Liste
data/state.json              – gelesen / mit Stern (serverseitig)
data/settings.json           – Cache-TTL, Auto-Refresh, Default-Filter
data/cache/<id>.json         – Pro-Feed-Cache
config.php                   – Benutzer-Hash + Widget-Token (NICHT in Git!)
scriptable-widget.js         – iOS-Widget (in Scriptable importieren)
```

## Sicherheits-Hinweise

- Die Cookies sind `HttpOnly` + `SameSite=Lax`, mit `Secure` automatisch wenn HTTPS.
- Login-Formular ist CSRF-geschützt.
- Passwörter werden ausschließlich als bcrypt-Hash gespeichert.
- `config.php` und `auth.php` sind via `.htaccess` zusätzlich gegen direkten Web-Zugriff gesperrt.
- Der Widget-Token ist nur für **lese**-Endpoints (items, state, list, settings) gültig.
  Schreibende Operationen (add, remove, mark-read…) erfordern eine Browser-Session.
- HTTPS auf dem Webspace einrichten (Let's Encrypt o. Ä.) — Cookies/Token gehören nicht über HTTP.

## Cron-Job (empfohlen)

Damit das iOS-Widget immer frische Headlines zeigt — auch ohne dass der Browser
offen ist — richte am Webspace einen Cron-Job ein, der alle 15 Min den Refresh anstößt:

```
*/15 * * * * curl -s "https://deinedomain.tld/rss/cron.php?key=<API_TOKEN>" >/dev/null
```

Der `<API_TOKEN>` ist derselbe Widget-Token aus den Einstellungen. Auf den meisten
Webhostern legst du den Cron im Kundencenter unter „Cronjobs" an.

## Diagnose

Wenn etwas nicht klappt: `https://deinedomain.tld/rss/api.php?action=diag` (eingeloggt)
liefert JSON mit Status zu PHP-Modulen, Schreibrechten, Test-Fetch und Parser.
