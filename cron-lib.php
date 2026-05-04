<?php
declare(strict_types=1);
/**
 * Minimal helpers shared between api.php and cron.php.
 * Keeps cron lightweight (no session, no routing).
 */

function load_json_safe(string $file, $default = []): array {
    if (!is_file($file)) return is_array($default) ? $default : [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : (is_array($default) ? $default : []);
}
function save_json_safe(string $file, array $data): void {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $file);
}

function http_get_many_cron(array $urls): array {
    $out = [];
    if (!function_exists('curl_multi_init') || empty($urls)) {
        foreach ($urls as $u) {
            $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 1, 'max_redirects' => 5,
                'header' => "User-Agent: SimpleRSSReader/1.0\r\n"]]);
            $out[$u] = @file_get_contents($u, false, $ctx) ?: null;
        }
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

function parse_feed_cron(string $xml): array {
    libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if (!$sx) return ['title' => null, 'items' => []];
    $root = strtolower($sx->getName());
    $items = []; $title = null;

    $rssItem = function ($it) {
        $dc = $it->children('http://purl.org/dc/elements/1.1/');
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
            'summary' => (string)$summary,
            'guid'    => (string)($it->guid ?? $link ?? $it->title),
        ];
    };

    if ($root === 'rss' && isset($sx->channel)) {
        $title = (string)$sx->channel->title;
        foreach ($sx->channel->item as $it) $items[] = $rssItem($it);
    } elseif ($root === 'rdf' || (isset($sx->channel) && isset($sx->item))) {
        $title = (string)$sx->channel->title;
        foreach ($sx->item as $it) $items[] = $rssItem($it);
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
                'summary' => (string)($it->summary ?? $it->content ?? ''),
                'guid'    => (string)($it->id ?? $link ?? $it->title),
            ];
        }
    }

    usort($items, fn($a, $b) => $b['date'] <=> $a['date']);
    return ['title' => $title ?: null, 'items' => $items];
}

function refresh_all_cron(array $feeds, string $cacheDir): array {
    $urls = array_map(fn($f) => $f['url'], $feeds);
    $bodies = http_get_many_cron($urls);
    foreach ($feeds as &$f) {
        $body = $bodies[$f['url']] ?? null;
        if ($body === null) continue;
        $parsed = parse_feed_cron($body);
        $parsed['fetched'] = time();
        @file_put_contents($cacheDir . '/' . $f['id'] . '.json',
            json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!empty($parsed['title'])) $f['title'] = $parsed['title'];
    }
    unset($f);
    return $feeds;
}
