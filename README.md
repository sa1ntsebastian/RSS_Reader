# RSS Reader

Ein leichtgewichtiger Web-RSS-Reader in PHP — keine Datenbank, keine Build-Tools.
Einfach auf einen Webspace mit PHP 7.4+ hochladen und im Browser öffnen.

## Features

- Feeds (RSS 2.0 & Atom) hinzufügen und entfernen
- Übersicht „Alle Artikel" oder gefiltert pro Feed, sortiert nach Datum
- Vorschau pro Eintrag, Direktlink im neuen Tab
- Gelesen/Ungelesen wird im Browser (LocalStorage) gespeichert
- Filter „nur ungelesene"
- Server-seitiger Cache (15 Minuten) – schont fremde Server und ist schnell
- Hell/Dunkel-Modus folgt der OS-Einstellung
- Single-User: keine Anmeldung; bei Bedarf via `.htaccess` Basic-Auth schützen

## Installation

1. Ordnerinhalt per FTP/SFTP auf den Webspace laden (z. B. nach `/rss/`).
2. Sicherstellen, dass der Ordner `data/` vom Webserver beschreibbar ist
   (bei vielen Hostern automatisch der Fall; sonst `chmod 775 data`).
3. Im Browser `https://deinedomain.tld/rss/` öffnen.

## Voraussetzungen

- PHP 7.4 oder neuer mit den Modulen `simplexml`, `mbstring` (Standard)
- `allow_url_fopen = On` in der PHP-Konfiguration (für `file_get_contents` mit URL).
  Falls deaktiviert: in `api.php` die Funktion `http_get()` auf cURL umstellen.

## Schutz mit Passwort (optional)

Empfohlen, da der Reader keinen eigenen Login hat. Beispiel `.htaccess` im Hauptordner:

```
AuthType Basic
AuthName "RSS"
AuthUserFile /absoluter/pfad/zu/.htpasswd
Require valid-user
```

`.htpasswd` per `htpasswd -c .htpasswd benutzername` erzeugen.

## Dateistruktur

```
index.php          – Oberfläche
api.php            – JSON-API (Liste, Items, Hinzufügen, Entfernen, Refresh)
assets/style.css   – Styling
assets/app.js      – Frontend-Logik
data/feeds.json    – gespeicherte Feed-Liste (wird automatisch angelegt)
data/cache/*.json  – Cache pro Feed (15 Min TTL)
data/.htaccess     – sperrt direkten Zugriff auf data/
```

## Anpassen

- Cache-Dauer: in `api.php` Konstante `$CACHE_TTL` (Sekunden)
- HTTP-Timeout: in `http_get()` der Wert `'timeout' => 10`
- Design: Farben in `assets/style.css` ganz oben (`:root`)
