<?php
declare(strict_types=1);

/**
 * Tiny JSON API for the RSS reader.
 *
 * Endpoints:
 *   GET  api.php?action=list                  -> list of feeds
 *   GET  api.php?action=items&id=<feedId>     -> items of one feed (or all if id=all)
 *   POST api.php?action=add     {url}         -> add feed
 *   POST api.php?action=remove  {id}          -> remove feed
 *   POST api.php?action=refresh [{id}]        -> refresh one or all feeds
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$DATA_DIR   = __DIR__ . '/data';
$FEEDS_FILE = $DATA_DIR . '/feeds.json';
$CACHE_DIR  = $DATA_DIR . '/cache';
$CACHE_TTL  = 15 * 60; // seconds

if (!is_dir($DATA_DIR))  @mkdir($DATA_DIR, 0775, true);
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function load_feeds(string $file): array {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_feeds(string $file, array $feeds): void {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($feeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $file);
}

function http_get(string $url, ?string &$err = null): ?string {
    $ua     = 'SimpleRSSReader/1.0';
    $accept = 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, */*;q=0.5';

    // Prefer cURL if available – works even when allow_url_fopen is off.
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => ['Accept: ' . $accept],
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($body === false) { $err = 'cURL: ' . $cerr; return null; }
        if ($code >= 400)    { $err = 'HTTP ' . $code; return null; }
        return (string)$body;
    }

    if (!ini_get('allow_url_fopen')) {
        $err = 'cURL nicht installiert und allow_url_fopen=Off';
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'         => 'GET',
            'timeout'        => 15,
            'follow_location'=> 1,
            'max_redirects'  => 5,
            'header'         => "User-Agent: $ua\r\nAccept: $accept\r\n",
            'ignore_errors'  => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $e = error_get_last();
        $err = 'fopen: ' . ($e['message'] ?? 'unbekannter Fehler');
        return null;
    }
    return $body;
}

function parse_feed(string $xml): array {
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$sx) return ['title' => null, 'items' => []];

    $root = strtolower($sx->getName()); // "rss" | "feed" | "rdf"
    $items = [];
    $title = null;

    if ($root === 'rss' && isset($sx->channel)) {
        // RSS 2.0
        $title = (string)$sx->channel->title;
        foreach ($sx->channel->item as $it) {
            $items[] = rss_item_to_array($it);
        }
    } elseif ($root === 'rdf' || (isset($sx->channel) && isset($sx->item))) {
        // RSS 1.0 (RDF) — items are siblings of <channel>, not children
        $title = (string)$sx->channel->title;
        foreach ($sx->item as $it) {
            $items[] = rss_item_to_array($it);
        }
    } elseif ($root === 'feed') {
        // Atom
        $title = (string)$sx->title;
        foreach ($sx->entry as $it) {
            $link = '';
            foreach ($it->link as $l) {
                $rel = (string)$l['rel'];
                if ($rel === '' || $rel === 'alternate') { $link = (string)$l['href']; break; }
            }
            $date = (string)($it->updated ?? $it->published ?? '');
            $items[] = [
                'title'   => trim((string)$it->title),
                'link'    => $link,
                'date'    => $date ? (strtotime($date) ?: time()) : time(),
                'summary' => clean_html((string)($it->summary ?? $it->content ?? '')),
                'guid'    => (string)($it->id ?? $link ?? $it->title),
            ];
        }
    }

    usort($items, fn($a, $b) => $b['date'] <=> $a['date']);
    return ['title' => $title ?: null, 'items' => $items];
}

function rss_item_to_array(SimpleXMLElement $it): array {
    // Date can come from pubDate (RSS 2.0) or dc:date (RSS 1.0 / extensions)
    $dc      = $it->children('http://purl.org/dc/elements/1.1/');
    $content = $it->children('http://purl.org/rss/1.0/modules/content/');
    $dateStr = (string)($it->pubDate ?? '');
    if ($dateStr === '' && isset($dc->date)) $dateStr = (string)$dc->date;

    $summary = (string)($it->description ?? '');
    if ($summary === '' && isset($content->encoded)) $summary = (string)$content->encoded;

    $link = trim((string)$it->link);
    if ($link === '') {
        // RSS 1.0 sometimes uses rdf:about as the canonical URL
        $rdf = $it->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        if (isset($rdf->about)) $link = (string)$rdf->about;
    }

    return [
        'title'   => trim((string)$it->title),
        'link'    => $link,
        'date'    => $dateStr ? (strtotime($dateStr) ?: time()) : time(),
        'summary' => clean_html($summary),
        'guid'    => (string)($it->guid ?? $link ?? $it->title),
    ];
}

function clean_html(string $html): string {
    // Strip scripts/styles/iframes/onclick attributes; keep simple formatting + images.
    $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html) ?? $html;
    $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html) ?? $html;
    $html = preg_replace('#javascript:#i', '', $html) ?? $html;
    return $html;
}

function feed_id(string $url): string {
    return substr(hash('sha256', $url), 0, 16);
}

function cache_path(string $cacheDir, string $id): string {
    return $cacheDir . '/' . $id . '.json';
}

function fetch_and_cache(string $url, string $id, string $cacheDir): array {
    $err = null;
    $body = http_get($url, $err);
    if ($body === null) return ['title' => null, 'items' => [], 'error' => $err ?: 'fetch failed'];
    $parsed = parse_feed($body);
    $parsed['fetched'] = time();
    @file_put_contents(cache_path($cacheDir, $id), json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $parsed;
}

function get_cached(string $id, string $cacheDir, int $ttl, string $url): array {
    $path = cache_path($cacheDir, $id);
    if (is_file($path) && (time() - filemtime($path)) < $ttl) {
        $data = json_decode((string)file_get_contents($path), true);
        if (is_array($data)) return $data;
    }
    return fetch_and_cache($url, $id, $cacheDir);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input') ?: 'null', true);

try {
    if ($method === 'GET' && $action === 'diag') {
        $writable = is_writable($DATA_DIR);
        $cacheWritable = is_writable($CACHE_DIR);
        $testUrl = $_GET['url'] ?? 'https://rss.orf.at/news.xml';
        $err = null;
        $body = http_get($testUrl, $err);
        $parsed = $body !== null ? parse_feed($body) : ['title' => null, 'items' => []];
        $sample = !empty($parsed['items']) ? $parsed['items'][0] : null;
        echo json_encode([
            'php_version'      => PHP_VERSION,
            'simplexml'        => extension_loaded('simplexml'),
            'curl'             => function_exists('curl_init'),
            'allow_url_fopen'  => (bool)ini_get('allow_url_fopen'),
            'data_writable'    => $writable,
            'cache_writable'   => $cacheWritable,
            'cache_files'      => is_dir($CACHE_DIR) ? array_values(array_diff(scandir($CACHE_DIR), ['.', '..'])) : [],
            'test_url'         => $testUrl,
            'fetch_ok'         => $body !== null,
            'fetch_bytes'      => $body !== null ? strlen($body) : 0,
            'fetch_error'      => $err,
            'parsed_title'     => $parsed['title'] ?? null,
            'parsed_item_count'=> count($parsed['items'] ?? []),
            'parsed_first_item'=> $sample,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        echo json_encode(['feeds' => load_feeds($FEEDS_FILE)]);
        exit;
    }

    if ($method === 'GET' && $action === 'items') {
        $id = $_GET['id'] ?? 'all';
        $feeds = load_feeds($FEEDS_FILE);
        $out = [];
        foreach ($feeds as $f) {
            if ($id !== 'all' && $f['id'] !== $id) continue;
            $data = get_cached($f['id'], $CACHE_DIR, $CACHE_TTL, $f['url']);
            foreach ($data['items'] ?? [] as $it) {
                $it['feedId']    = $f['id'];
                $it['feedTitle'] = $f['title'] ?? $f['url'];
                $out[] = $it;
            }
        }
        usort($out, fn($a, $b) => $b['date'] <=> $a['date']);
        echo json_encode(['items' => $out]);
        exit;
    }

    if ($method === 'POST' && $action === 'add') {
        $url = trim((string)($body['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            fail(400, 'Ungültige URL');
        }
        $feeds = load_feeds($FEEDS_FILE);
        $id = feed_id($url);
        foreach ($feeds as $f) if ($f['id'] === $id) fail(409, 'Feed existiert bereits');

        $parsed = fetch_and_cache($url, $id, $CACHE_DIR);
        if (!empty($parsed['error'])) {
            fail(502, 'Feed konnte nicht geladen werden (' . $parsed['error'] . ')');
        }
        if (empty($parsed['items']) && empty($parsed['title'])) {
            fail(422, 'Konnte Feed nicht parsen (kein gültiges RSS/Atom?)');
        }
        $feeds[] = [
            'id'    => $id,
            'url'   => $url,
            'title' => $parsed['title'] ?: $url,
            'added' => time(),
        ];
        save_feeds($FEEDS_FILE, $feeds);
        echo json_encode(['ok' => true, 'feed' => end($feeds)]);
        exit;
    }

    if ($method === 'POST' && $action === 'remove') {
        $id = (string)($body['id'] ?? '');
        $feeds = load_feeds($FEEDS_FILE);
        $feeds = array_values(array_filter($feeds, fn($f) => $f['id'] !== $id));
        save_feeds($FEEDS_FILE, $feeds);
        @unlink(cache_path($CACHE_DIR, $id));
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'refresh') {
        $id = (string)($body['id'] ?? 'all');
        $feeds = load_feeds($FEEDS_FILE);
        foreach ($feeds as &$f) {
            if ($id !== 'all' && $f['id'] !== $id) continue;
            $parsed = fetch_and_cache($f['url'], $f['id'], $CACHE_DIR);
            if (!empty($parsed['title'])) $f['title'] = $parsed['title'];
        }
        unset($f);
        save_feeds($FEEDS_FILE, $feeds);
        echo json_encode(['ok' => true]);
        exit;
    }

    fail(404, 'Unbekannte Aktion');
} catch (Throwable $e) {
    fail(500, 'Serverfehler: ' . $e->getMessage());
}
