<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

$DATA_DIR     = __DIR__ . '/data';
$FEEDS_FILE   = $DATA_DIR . '/feeds.json';
$FOLDERS_FILE = $DATA_DIR . '/folders.json';
$STATE_FILE   = $DATA_DIR . '/state.json';
$SETTINGS_FILE= $DATA_DIR . '/settings.json';
$CACHE_DIR    = $DATA_DIR . '/cache';

if (!is_dir($DATA_DIR))  @mkdir($DATA_DIR, 0775, true);
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);

// Per-action auth: GET endpoints accept either session login or ?token=
// (used by the iOS widget). The widget also needs to flip read/star state,
// so 'state' POSTs are allowed via token too. Everything else (feed CRUD,
// settings, OPML, regenerate-token) requires a real browser session.
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$READ_ONLY_TOKEN_OK = ['list','items','article','state','settings'];
$WRITE_TOKEN_OK     = ['state'];
$tokenOk = (
    ($method === 'GET'  && in_array($action, $READ_ONLY_TOKEN_OK, true)) ||
    ($method === 'POST' && in_array($action, $WRITE_TOKEN_OK, true))
);
if ($tokenOk) {
    if (!auth_logged_in_or_token()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'auth required']);
        exit;
    }
} else {
    require_login_api();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ---------- helpers ----------

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function load_json(string $file, $default = []): array {
    if (!is_file($file)) return is_array($default) ? $default : [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : (is_array($default) ? $default : []);
}

function save_json(string $file, array $data): void {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $file);
}

function default_settings(): array {
    return [
        'cache_ttl'         => 15 * 60,
        'refresh_interval'  => 5 * 60,   // browser auto-refresh, seconds (0=off)
        'hide_read_default' => false,
    ];
}

function load_settings(string $file): array {
    return array_merge(default_settings(), load_json($file, []));
}

function default_state(): array {
    return ['read' => [], 'starred' => []];
}

function load_state(string $file): array {
    $s = load_json($file, default_state());
    if (!isset($s['read']))    $s['read']    = [];
    if (!isset($s['starred'])) $s['starred'] = [];
    return $s;
}

function http_get(string $url, ?string &$err = null, ?string $ua = null): ?string {
    $ua     = $ua ?? 'SimpleRSSReader/1.0';
    $accept = 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9, text/html;q=0.8, */*;q=0.5';

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
            'method' => 'GET', 'timeout' => 15,
            'follow_location' => 1, 'max_redirects' => 5,
            'header' => "User-Agent: $ua\r\nAccept: $accept\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $e = error_get_last();
        $err = 'fopen: ' . ($e['message'] ?? 'unbekannt');
        return null;
    }
    return $body;
}

/**
 * Fetch many URLs in parallel with curl_multi.
 * Returns [url => body|null]. Falls back to sequential when curl is missing.
 */
function http_get_many(array $urls): array {
    $out = [];
    if (!function_exists('curl_multi_init') || empty($urls)) {
        foreach ($urls as $u) $out[$u] = http_get($u);
        return $out;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'SimpleRSSReader/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5'],
            CURLOPT_ENCODING       => '',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$u] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.5); } while ($running > 0);
    foreach ($handles as $u => $ch) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        $out[$u] = ($body !== false && $code < 400) ? $body : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function parse_feed(string $xml): array {
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$sx) return ['title' => null, 'items' => []];

    $root = strtolower($sx->getName());
    $items = []; $title = null;

    if ($root === 'rss' && isset($sx->channel)) {
        $title = (string)$sx->channel->title;
        foreach ($sx->channel->item as $it) $items[] = rss_item_to_array($it);
    } elseif ($root === 'rdf' || (isset($sx->channel) && isset($sx->item))) {
        $title = (string)$sx->channel->title;
        foreach ($sx->item as $it) $items[] = rss_item_to_array($it);
    } elseif ($root === 'feed') {
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
    $dc      = $it->children('http://purl.org/dc/elements/1.1/');
    $content = $it->children('http://purl.org/rss/1.0/modules/content/');
    $dateStr = (string)($it->pubDate ?? '');
    if ($dateStr === '' && isset($dc->date)) $dateStr = (string)$dc->date;
    $summary = (string)($it->description ?? '');
    if ($summary === '' && isset($content->encoded)) $summary = (string)$content->encoded;
    $link = trim((string)$it->link);
    if ($link === '') {
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
    $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $html) ?? $html;
    $html = preg_replace("#\son\w+\s*=\s*'[^']*'#i", '', $html) ?? $html;
    $html = preg_replace('#javascript:#i', '', $html) ?? $html;
    return $html;
}

/**
 * Smart wrapper: tries the default UA first; if the result looks gated
 * (privacywall, very short body) we retry as Googlebot, which most German
 * news sites serve the full article to for SEO purposes.
 */
function extract_article(string $url): ?array {
    $first = extract_article_with_ua($url, null);

    $looksGated = static function (?string $html, ?array $art): bool {
        if ($html !== null && preg_match('#privacywall|consent[-_]?wall|paywall|tcf-banner|cmp-overlay#i', $html)) return true;
        if ($art && ($art['words'] ?? 0) < 250) return true;
        if (!$art) return true;
        return false;
    };

    if ($looksGated($first['_raw'] ?? null, $first)) {
        $bot = extract_article_with_ua($url, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        if ($bot && (!$first || ($bot['words'] ?? 0) > ($first['words'] ?? 0) * 1.4)) {
            $bot['extracted_via'] = 'googlebot';
            unset($bot['_raw']);
            return $bot;
        }
    }
    if ($first) { unset($first['_raw']); $first['extracted_via'] = 'default'; }
    return $first;
}

function extract_article_with_ua(string $url, ?string $ua): ?array {
    $err = null;
    $html = http_get($url, $err, $ua);
    if ($html === null || strlen($html) < 200) return null;

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $title = '';
    foreach ($xp->query('//meta[@property="og:title"]/@content') as $n) { $title = trim($n->nodeValue); break; }
    if ($title === '') foreach ($xp->query('//title') as $n) { $title = trim($n->textContent); break; }

    $candidates = [
        '//article','//*[@itemprop="articleBody"]','//main',
        '//*[contains(@class,"article-body")]','//*[contains(@class,"story-content")]',
        '//*[contains(@class,"entry-content")]','//*[contains(@class,"post-content")]',
        '//*[contains(@class,"content-body")]',
        '//*[contains(@id,"article")]','//*[contains(@id,"content")]',
    ];
    $best = null; $bestScore = 0;
    foreach ($candidates as $q) foreach ($xp->query($q) as $node) {
        $score = paragraph_score($xp, $node);
        if ($score > $bestScore) { $best = $node; $bestScore = $score; }
    }
    $longParas = $xp->query('//p[string-length(normalize-space(.)) > 60]');
    if ($longParas->length >= 3) {
        $lca = lowest_common_ancestor($longParas);
        if ($lca) {
            $score = paragraph_score($xp, $lca);
            if ($score > $bestScore) { $best = $lca; $bestScore = $score; }
        }
    }
    if (!$best || $bestScore < 400) {
        foreach ($xp->query('//div | //section') as $node) {
            $pCount = $xp->evaluate('count(.//p)', $node);
            if ($pCount < 3) continue;
            $score = paragraph_score($xp, $node);
            if ($score > $bestScore) { $best = $node; $bestScore = $score; }
        }
    }
    if (!$best || $bestScore < 200) return null;

    // Parent promotion: if going one level up substantially raises the
    // paragraph score (siblings of the chosen container also hold prose),
    // prefer the parent. Catches sites like derStandard where the article
    // body is split across multiple sibling containers under a wrapper.
    $cur = $best;
    for ($i = 0; $i < 4; $i++) {
        $parent = $cur->parentNode;
        if (!$parent instanceof DOMElement) break;
        $tag = strtolower($parent->nodeName);
        if (in_array($tag, ['body','html'], true)) break;
        $parentScore = paragraph_score($xp, $parent);
        // Only promote if parent gains us notably more text and isn't a
        // navigation-like wrapper (rough check: link density should stay low).
        if ($parentScore > $bestScore * 1.25 && link_density($xp, $parent) < 0.4) {
            $best = $parent; $bestScore = $parentScore; $cur = $parent;
        } else break;
    }

    $strip = ['script','style','noscript','iframe','form','aside','nav','header','footer','svg','button'];
    foreach ($strip as $tag) {
        $kill = [];
        foreach ($best->getElementsByTagName($tag) as $n) $kill[] = $n;
        foreach ($kill as $n) $n->parentNode?->removeChild($n);
    }
    $junk = $xp->query(
        ".//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'share') " .
        "or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'related') " .
        "or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'comment') " .
        "or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'newsletter') " .
        "or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'advert') " .
        "or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'promo')]",
        $best);
    $kill = []; foreach ($junk as $n) $kill[] = $n;
    foreach ($kill as $n) $n->parentNode?->removeChild($n);

    foreach ($best->getElementsByTagName('img') as $img) {
        $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
        if ($src) $img->setAttribute('src', absolutize_url($url, $src));
        $img->removeAttribute('srcset');
        $img->removeAttribute('loading');
    }
    foreach ($best->getElementsByTagName('a') as $a) {
        $href = $a->getAttribute('href');
        if ($href) $a->setAttribute('href', absolutize_url($url, $href));
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
    }

    $inner = '';
    foreach ($best->childNodes as $c) $inner .= $doc->saveHTML($c);
    $inner = clean_html($inner);

    $textOnly = trim(preg_replace('/\s+/u', ' ', strip_tags($inner)));
    $words    = $textOnly === '' ? 0 : str_word_count($textOnly, 0, 'äöüÄÖÜßéèêàçñA-Za-z0-9');
    $minutes  = max(1, (int)round($words / 220));

    return [
        'title'        => $title ?: null,
        'html'         => trim($inner),
        'words'        => $words,
        'reading_min'  => $minutes,
        '_raw'         => $html, // private; consumed by extract_article()
    ];
}

function extract_article_debug(string $url): ?array {
    $err = null;
    $html = http_get($url, $err);
    if ($html === null) return ['fetch_error' => $err];

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    $candidates = [
        '//article','//*[@itemprop="articleBody"]','//main',
        '//*[contains(@class,"article-body")]','//*[contains(@class,"story-content")]',
        '//*[contains(@class,"entry-content")]','//*[contains(@class,"post-content")]',
        '//*[contains(@class,"content-body")]','//*[contains(@class,"article-paragraphs")]',
        '//*[contains(@id,"article")]','//*[contains(@id,"content")]',
    ];
    $rows = [];
    foreach ($candidates as $q) {
        foreach ($xp->query($q) as $node) {
            $rows[] = [
                'selector'   => $q,
                'tag'        => $node->nodeName,
                'class'      => $node instanceof DOMElement ? $node->getAttribute('class') : '',
                'id'         => $node instanceof DOMElement ? $node->getAttribute('id') : '',
                'p_score'    => paragraph_score($xp, $node),
                'text_chars' => mb_strlen(trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''))),
                'p_count'    => $xp->evaluate('count(.//p)', $node),
                'link_dens'  => round(link_density($xp, $node), 2),
            ];
        }
    }
    $longParas = $xp->query('//p[string-length(normalize-space(.)) > 60]');
    $lcaInfo = null;
    if ($longParas->length >= 2) {
        $lca = lowest_common_ancestor($longParas);
        if ($lca instanceof DOMElement) {
            $lcaInfo = [
                'tag'        => $lca->nodeName,
                'class'      => $lca->getAttribute('class'),
                'id'         => $lca->getAttribute('id'),
                'p_score'    => paragraph_score($xp, $lca),
                'long_paras' => $longParas->length,
                'link_dens'  => round(link_density($xp, $lca), 2),
            ];
        }
    }
    $art = extract_article($url);
    return [
        'url'             => $url,
        'fetch_bytes'     => strlen($html),
        'tagged_candidates' => $rows,
        'lca_of_long_paras' => $lcaInfo,
        'final_chars'     => $art ? mb_strlen(strip_tags($art['html'])) : 0,
        'final_words'     => $art['words'] ?? 0,
        'final_html_preview' => $art ? mb_substr($art['html'], 0, 600) : null,
    ];
}

function paragraph_score(DOMXPath $xp, DOMNode $node): int {
    $sum = 0;
    // Treat <p>, <li> and <blockquote> as content-bearing
    foreach ($xp->query('.//p | .//li | .//blockquote', $node) as $p) {
        $t = trim(preg_replace('/\s+/u', ' ', $p->textContent ?? ''));
        $len = mb_strlen($t);
        if ($len >= 40) $sum += $len;
    }
    return $sum;
}

/** Ratio of link text to overall text inside a node (0..1). High = nav/menu. */
function link_density(DOMXPath $xp, DOMNode $node): float {
    $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
    $textLen = mb_strlen($text);
    if ($textLen === 0) return 1.0;
    $linkLen = 0;
    foreach ($xp->query('.//a', $node) as $a) {
        $linkLen += mb_strlen(trim(preg_replace('/\s+/u', ' ', $a->textContent ?? '')));
    }
    return $linkLen / max(1, $textLen);
}

function lowest_common_ancestor(DOMNodeList $nodes): ?DOMNode {
    if ($nodes->length === 0) return null;
    if ($nodes->length === 1) return $nodes->item(0)->parentNode;
    $chain = [];
    for ($n = $nodes->item(0); $n; $n = $n->parentNode) $chain[] = $n;
    for ($i = 1; $i < $nodes->length; $i++) {
        $set = [];
        for ($n = $nodes->item($i); $n; $n = $n->parentNode) $set[spl_object_id($n)] = true;
        while ($chain && !isset($set[spl_object_id($chain[0])])) array_shift($chain);
        if (!$chain) return null;
    }
    return $chain[0] ?? null;
}

function absolutize_url(string $base, string $href): string {
    if ($href === '' || preg_match('#^(https?:|data:|mailto:|tel:)#i', $href)) return $href;
    $parts = parse_url($base);
    if (!$parts || empty($parts['host'])) return $href;
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'];
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    if (str_starts_with($href, '//')) return $scheme . ':' . $href;
    if (str_starts_with($href, '/'))  return $scheme . '://' . $host . $port . $href;
    $path = $parts['path'] ?? '/';
    $dir  = preg_replace('#/[^/]*$#', '/', $path);
    return $scheme . '://' . $host . $port . $dir . $href;
}

function feed_id(string $url): string { return substr(hash('sha256', $url), 0, 16); }
function cache_path(string $cacheDir, string $id): string { return $cacheDir . '/' . $id . '.json'; }

function favicon_for(string $url): string {
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    return $host ? 'https://www.google.com/s2/favicons?sz=64&domain=' . urlencode($host) : '';
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

function refresh_all(array $feeds, string $cacheDir): array {
    $urls = array_map(fn($f) => $f['url'], $feeds);
    $bodies = http_get_many($urls);
    foreach ($feeds as &$f) {
        $body = $bodies[$f['url']] ?? null;
        if ($body === null) continue;
        $parsed = parse_feed($body);
        $parsed['fetched'] = time();
        @file_put_contents(cache_path($cacheDir, $f['id']),
            json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!empty($parsed['title'])) $f['title'] = $parsed['title'];
    }
    unset($f);
    return $feeds;
}

function decorate_feed(array $f): array {
    $f['favicon'] = favicon_for($f['url']);
    $f['folder']  = (string)($f['folder'] ?? '');
    return $f;
}

function load_folders(string $file): array {
    $list = load_json($file, []);
    return array_values(array_filter(array_map('strval', $list), fn($s) => $s !== ''));
}
function save_folders(string $file, array $folders): void {
    $folders = array_values(array_unique(array_map(fn($s) => trim((string)$s), $folders)));
    $folders = array_values(array_filter($folders, fn($s) => $s !== ''));
    save_json($file, $folders);
}
function ensure_folder(string $file, string $name): void {
    $name = trim($name);
    if ($name === '') return;
    $list = load_folders($file);
    if (!in_array($name, $list, true)) {
        $list[] = $name;
        save_folders($file, $list);
    }
}

// ---------- routing ----------

$body = json_decode(file_get_contents('php://input') ?: 'null', true);
$settings = load_settings($SETTINGS_FILE);

try {
    if ($method === 'GET' && $action === 'diag') {
        $writable = is_writable($DATA_DIR);
        $cacheWritable = is_writable($CACHE_DIR);
        $testUrl = $_GET['url'] ?? 'https://rss.orf.at/news.xml';
        $err = null;
        $body2 = http_get($testUrl, $err);
        $parsed = $body2 !== null ? parse_feed($body2) : ['title' => null, 'items' => []];
        $sample = !empty($parsed['items']) ? $parsed['items'][0] : null;
        echo json_encode([
            'php_version'      => PHP_VERSION,
            'simplexml'        => extension_loaded('simplexml'),
            'curl'             => function_exists('curl_init'),
            'curl_multi'       => function_exists('curl_multi_init'),
            'allow_url_fopen'  => (bool)ini_get('allow_url_fopen'),
            'data_writable'    => $writable,
            'cache_writable'   => $cacheWritable,
            'cache_files'      => is_dir($CACHE_DIR) ? array_values(array_diff(scandir($CACHE_DIR), ['.', '..'])) : [],
            'test_url'         => $testUrl,
            'fetch_ok'         => $body2 !== null,
            'fetch_bytes'      => $body2 !== null ? strlen($body2) : 0,
            'fetch_error'      => $err,
            'parsed_title'     => $parsed['title'] ?? null,
            'parsed_item_count'=> count($parsed['items'] ?? []),
            'parsed_first_item'=> $sample,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'article') {
        $url   = trim((string)($_GET['url'] ?? ''));
        $debug = !empty($_GET['debug']);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) fail(400, 'Ungültige URL');
        if ($debug) {
            $info = extract_article_debug($url);
            if (!$info) fail(422, 'Konnte den Artikel nicht extrahieren');
            echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $art = extract_article($url);
        if (!$art) fail(422, 'Konnte den Artikel nicht extrahieren');
        echo json_encode($art, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        $feeds = array_map('decorate_feed', load_json($FEEDS_FILE));
        $folders = load_folders($FOLDERS_FILE);
        // Auto-include any folder referenced by a feed but missing from the explicit list
        foreach ($feeds as $f) {
            $fld = (string)($f['folder'] ?? '');
            if ($fld !== '' && !in_array($fld, $folders, true)) $folders[] = $fld;
        }
        save_folders($FOLDERS_FILE, $folders);
        echo json_encode(['feeds' => $feeds, 'folders' => $folders]);
        exit;
    }

    if ($method === 'POST' && $action === 'folder-create') {
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') fail(400, 'Ordnername fehlt');
        ensure_folder($FOLDERS_FILE, $name);
        echo json_encode(['ok' => true, 'folders' => load_folders($FOLDERS_FILE)]);
        exit;
    }

    if ($method === 'POST' && $action === 'folder-delete') {
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') fail(400, 'Ordnername fehlt');
        $folders = array_values(array_filter(load_folders($FOLDERS_FILE), fn($f) => $f !== $name));
        save_folders($FOLDERS_FILE, $folders);
        // Detach feeds from the deleted folder (keep the feeds themselves)
        $feeds = load_json($FEEDS_FILE);
        foreach ($feeds as &$f) if (($f['folder'] ?? '') === $name) $f['folder'] = '';
        unset($f);
        save_json($FEEDS_FILE, $feeds);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'folder-rename') {
        $from = trim((string)($body['from'] ?? ''));
        $to   = trim((string)($body['to']   ?? ''));
        if ($from === '' || $to === '') fail(400, 'Namen fehlen');
        $folders = load_folders($FOLDERS_FILE);
        $idx = array_search($from, $folders, true);
        if ($idx === false) fail(404, 'Ordner nicht gefunden');
        // If $to already exists, just merge: drop $from and rename feeds
        if (in_array($to, $folders, true)) {
            array_splice($folders, $idx, 1);
        } else {
            $folders[$idx] = $to;
        }
        save_folders($FOLDERS_FILE, $folders);
        $feeds = load_json($FEEDS_FILE);
        foreach ($feeds as &$f) if (($f['folder'] ?? '') === $from) $f['folder'] = $to;
        unset($f);
        save_json($FEEDS_FILE, $feeds);
        echo json_encode(['ok' => true, 'folders' => $folders]);
        exit;
    }

    if ($method === 'POST' && $action === 'folders-reorder') {
        $names = (array)($body['names'] ?? []);
        $known = load_folders($FOLDERS_FILE);
        $sorted = [];
        foreach ($names as $n) {
            $n = trim((string)$n);
            if ($n !== '' && in_array($n, $known, true) && !in_array($n, $sorted, true)) $sorted[] = $n;
        }
        // Append any folder we still know about that wasn't included
        foreach ($known as $n) if (!in_array($n, $sorted, true)) $sorted[] = $n;
        save_folders($FOLDERS_FILE, $sorted);
        echo json_encode(['ok' => true, 'folders' => $sorted]);
        exit;
    }

    if ($method === 'GET' && $action === 'items') {
        $id = $_GET['id'] ?? 'all';
        $feeds = load_json($FEEDS_FILE);
        $out = [];
        foreach ($feeds as $f) {
            if ($id !== 'all' && $f['id'] !== $id) continue;
            $data = get_cached($f['id'], $CACHE_DIR, $settings['cache_ttl'], $f['url']);
            foreach ($data['items'] ?? [] as $it) {
                $it['feedId']    = $f['id'];
                $it['feedTitle'] = $f['title'] ?? $f['url'];
                $it['folder']    = $f['folder'] ?? '';
                $out[] = $it;
            }
        }
        usort($out, fn($a, $b) => $b['date'] <=> $a['date']);
        echo json_encode(['items' => $out]);
        exit;
    }

    // -- read/star state --
    if ($method === 'GET' && $action === 'state') {
        echo json_encode(load_state($STATE_FILE));
        exit;
    }
    if ($method === 'POST' && $action === 'state') {
        $state = load_state($STATE_FILE);
        $op    = (string)($body['op'] ?? '');
        $guids = array_values(array_filter((array)($body['guids'] ?? []), 'is_string'));

        $apply = function (array &$set, array $guids, bool $add): void {
            $set = array_values(array_unique(
                $add ? array_merge($set, $guids) : array_diff($set, $guids)
            ));
        };
        if ($op === 'mark-read')     $apply($state['read'], $guids, true);
        elseif ($op === 'mark-unread') $apply($state['read'], $guids, false);
        elseif ($op === 'star')        $apply($state['starred'], $guids, true);
        elseif ($op === 'unstar')      $apply($state['starred'], $guids, false);
        elseif ($op === 'mark-all-read') {
            $feedId = (string)($body['feedId'] ?? 'all');
            $folder = null;
            if (str_starts_with($feedId, 'folder:')) { $folder = substr($feedId, 7); $feedId = 'all'; }
            $feeds  = load_json($FEEDS_FILE);
            foreach ($feeds as $f) {
                if ($feedId !== 'all' && $f['id'] !== $feedId) continue;
                if ($folder !== null && (string)($f['folder'] ?? '') !== $folder) continue;
                $cache = json_decode((string)@file_get_contents(cache_path($CACHE_DIR, $f['id'])), true);
                foreach (($cache['items'] ?? []) as $it) {
                    if (!empty($it['guid'])) $state['read'][] = (string)$it['guid'];
                }
            }
            $state['read'] = array_values(array_unique($state['read']));
        } elseif ($op === 'replace') {
            $state['read']    = array_values(array_unique((array)($body['read']    ?? $state['read'])));
            $state['starred'] = array_values(array_unique((array)($body['starred'] ?? $state['starred'])));
        } else {
            fail(400, 'Unbekannte op');
        }
        save_json($STATE_FILE, $state);
        echo json_encode($state);
        exit;
    }

    // -- settings --
    if ($method === 'GET' && $action === 'settings') {
        $cfg = auth_config();
        $out = $settings;
        if (auth_logged_in()) $out['api_token'] = $cfg['token'] ?? null;
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST' && $action === 'settings') {
        $new = $settings;
        if (isset($body['cache_ttl']))         $new['cache_ttl']         = max(60, (int)$body['cache_ttl']);
        if (isset($body['refresh_interval']))  $new['refresh_interval']  = max(0,  (int)$body['refresh_interval']);
        if (isset($body['hide_read_default'])) $new['hide_read_default'] = (bool)$body['hide_read_default'];
        save_json($SETTINGS_FILE, $new);
        echo json_encode($new);
        exit;
    }
    if ($method === 'POST' && $action === 'regenerate-token') {
        $tok = auth_regenerate_token();
        if (!$tok) fail(500, 'Konnte Token nicht erzeugen');
        echo json_encode(['token' => $tok]);
        exit;
    }

    // -- feed CRUD --
    if ($method === 'POST' && $action === 'add') {
        $url = trim((string)($body['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) fail(400, 'Ungültige URL');
        $feeds = load_json($FEEDS_FILE);
        $id = feed_id($url);
        foreach ($feeds as $f) if ($f['id'] === $id) fail(409, 'Feed existiert bereits');

        $parsed = fetch_and_cache($url, $id, $CACHE_DIR);
        if (!empty($parsed['error'])) fail(502, 'Feed konnte nicht geladen werden (' . $parsed['error'] . ')');
        if (empty($parsed['items']) && empty($parsed['title'])) fail(422, 'Konnte Feed nicht parsen');

        $folder = trim((string)($body['folder'] ?? ''));
        $feeds[] = [
            'id'     => $id,
            'url'    => $url,
            'title'  => $parsed['title'] ?: $url,
            'folder' => $folder,
            'added'  => time(),
        ];
        save_json($FEEDS_FILE, $feeds);
        if ($folder !== '') ensure_folder($FOLDERS_FILE, $folder);
        echo json_encode(['ok' => true, 'feed' => decorate_feed(end($feeds))]);
        exit;
    }

    if ($method === 'POST' && $action === 'remove') {
        $id = (string)($body['id'] ?? '');
        $feeds = array_values(array_filter(load_json($FEEDS_FILE), fn($f) => $f['id'] !== $id));
        save_json($FEEDS_FILE, $feeds);
        @unlink(cache_path($CACHE_DIR, $id));
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'feed-update') {
        $id = (string)($body['id'] ?? '');
        $feeds = load_json($FEEDS_FILE);
        $found = false;
        $newFolder = null;
        foreach ($feeds as &$f) {
            if ($f['id'] !== $id) continue;
            if (isset($body['title']))  $f['title']  = trim((string)$body['title']) ?: $f['title'];
            if (isset($body['folder'])) { $f['folder'] = trim((string)$body['folder']); $newFolder = $f['folder']; }
            $found = true; break;
        }
        unset($f);
        if (!$found) fail(404, 'Feed nicht gefunden');
        save_json($FEEDS_FILE, $feeds);
        if ($newFolder !== null && $newFolder !== '') ensure_folder($FOLDERS_FILE, $newFolder);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'reorder') {
        $order = (array)($body['ids'] ?? []);
        $feeds = load_json($FEEDS_FILE);
        $byId  = [];
        foreach ($feeds as $f) $byId[$f['id']] = $f;
        $sorted = [];
        foreach ($order as $id) if (isset($byId[$id])) { $sorted[] = $byId[$id]; unset($byId[$id]); }
        foreach ($byId as $rest) $sorted[] = $rest; // append unknowns
        save_json($FEEDS_FILE, $sorted);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'refresh') {
        $id = (string)($body['id'] ?? 'all');
        $feeds = load_json($FEEDS_FILE);
        if ($id === 'all') {
            $feeds = refresh_all($feeds, $CACHE_DIR);
        } else {
            foreach ($feeds as &$f) {
                if ($f['id'] !== $id) continue;
                $parsed = fetch_and_cache($f['url'], $f['id'], $CACHE_DIR);
                if (!empty($parsed['title'])) $f['title'] = $parsed['title'];
            }
            unset($f);
        }
        save_json($FEEDS_FILE, $feeds);
        echo json_encode(['ok' => true]);
        exit;
    }

    // -- OPML --
    if ($method === 'GET' && $action === 'opml-export') {
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="feeds.opml"');
        $feeds = load_json($FEEDS_FILE);
        $byFolder = [];
        foreach ($feeds as $f) $byFolder[(string)($f['folder'] ?? '')][] = $f;
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<opml version=\"2.0\">\n";
        echo "  <head><title>RSS Reader Feeds</title></head>\n  <body>\n";
        foreach ($byFolder as $folder => $list) {
            $indent = '    ';
            if ($folder !== '') {
                echo "    <outline text=\"{$esc($folder)}\" title=\"{$esc($folder)}\">\n";
                $indent = '      ';
            }
            foreach ($list as $f) {
                printf("%s<outline type=\"rss\" text=\"%s\" title=\"%s\" xmlUrl=\"%s\" htmlUrl=\"%s\"/>\n",
                    $indent, $esc($f['title']), $esc($f['title']), $esc($f['url']), $esc($f['url']));
            }
            if ($folder !== '') echo "    </outline>\n";
        }
        echo "  </body>\n</opml>\n";
        exit;
    }

    if ($method === 'POST' && $action === 'opml-import') {
        $xml = (string)($body['opml'] ?? '');
        if ($xml === '' && !empty($_FILES['opml']['tmp_name'])) {
            $xml = (string)file_get_contents($_FILES['opml']['tmp_name']);
        }
        if ($xml === '') fail(400, 'OPML leer');
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        if (!$sx) fail(422, 'OPML konnte nicht geparst werden');

        $feeds = load_json($FEEDS_FILE);
        $existing = [];
        foreach ($feeds as $f) $existing[$f['id']] = true;
        $added = 0;

        $walk = function ($node, $folder = '') use (&$walk, &$feeds, &$existing, &$added) {
            foreach ($node->outline as $o) {
                $type    = (string)$o['type'];
                $xmlUrl  = (string)$o['xmlUrl'];
                $title   = (string)($o['title'] ?? $o['text']);
                if ($xmlUrl !== '' && (in_array(strtolower($type), ['rss','atom'], true) || $type === '')) {
                    $id = feed_id($xmlUrl);
                    if (!isset($existing[$id])) {
                        $feeds[] = ['id' => $id, 'url' => $xmlUrl, 'title' => $title ?: $xmlUrl, 'folder' => $folder, 'added' => time()];
                        $existing[$id] = true; $added++;
                    }
                } elseif (count($o->outline) > 0) {
                    $childFolder = $folder ?: ((string)($o['title'] ?? $o['text']));
                    $walk($o, $childFolder);
                }
            }
        };
        $walk($sx->body);
        save_json($FEEDS_FILE, $feeds);
        echo json_encode(['ok' => true, 'added' => $added, 'total' => count($feeds)]);
        exit;
    }

    fail(404, 'Unbekannte Aktion');
} catch (Throwable $e) {
    fail(500, 'Serverfehler: ' . $e->getMessage());
}
