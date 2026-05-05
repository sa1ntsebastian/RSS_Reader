<?php
declare(strict_types=1);

/**
 * Cron entrypoint: refreshes all feeds in the background.
 * Call from your webspace cron (e.g. every 15 minutes):
 *
 *   curl -s "https://deinedomain.tld/rss/cron.php?key=<api_token>"
 *
 * Authorisation: the same API token used by the iOS widget.
 * No session, no UI; outputs a tiny status line.
 */

require __DIR__ . '/auth.php';

$token = $_GET['key'] ?? $_SERVER['HTTP_X_RSS_TOKEN'] ?? '';
if (!is_string($token) || $token === '' || !auth_token_valid($token)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo "unauthorized\n";
    exit;
}

// Reuse helpers and routing primitives from api.php — but we don't need its
// auth dance, so inline what we need.
$DATA_DIR   = __DIR__ . '/data';
$FEEDS_FILE = $DATA_DIR . '/feeds.json';
$CACHE_DIR  = $DATA_DIR . '/cache';
if (!is_dir($CACHE_DIR)) @mkdir($CACHE_DIR, 0775, true);

// Pull in the api.php helpers without executing routing. We can't easily
// include api.php (it auto-routes), so duplicate the small bits we need.
require_once __DIR__ . '/cron-lib.php';
require_once __DIR__ . '/push.php';

@set_time_limit(120);
ignore_user_abort(true);

$feeds = load_json_safe($FEEDS_FILE);

// Snapshot of guids before refresh — used to detect what's new
$before = [];
foreach ($feeds as $f) {
    $cache = load_json_safe($CACHE_DIR . '/' . $f['id'] . '.json');
    foreach (($cache['items'] ?? []) as $it) if (!empty($it['guid'])) $before[$it['guid']] = true;
}

$started = microtime(true);
$updated = refresh_all_cron($feeds, $CACHE_DIR);
save_json_safe($FEEDS_FILE, $updated);
$ms = (int)round((microtime(true) - $started) * 1000);

// Compare after-refresh guids to detect newcomers
$newCount = 0;
$newest = null;
foreach ($updated as $f) {
    $cache = load_json_safe($CACHE_DIR . '/' . $f['id'] . '.json');
    foreach (($cache['items'] ?? []) as $it) {
        if (empty($it['guid'])) continue;
        if (!isset($before[$it['guid']])) {
            $newCount++;
            if (!$newest || ($it['date'] ?? 0) > ($newest['date'] ?? 0)) $newest = $it;
        }
    }
}

$pushStats = null;
if ($newCount > 0) {
    $pushStats = push_send_all(push_default_subject(), [
        'title' => $newCount === 1 ? 'Neuer Artikel' : "$newCount neue Artikel",
        'body'  => $newest ? mb_substr((string)$newest['title'], 0, 120) : '',
        'count' => $newCount,
        'url'   => './',
    ]);
}

header('Content-Type: text/plain; charset=utf-8');
echo "ok – " . count($updated) . " feed(s) refreshed in {$ms}ms";
if ($newCount > 0) echo " · {$newCount} new";
if ($pushStats)    echo " · pushed: " . json_encode($pushStats);
echo "\n";
