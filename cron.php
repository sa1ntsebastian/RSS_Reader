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

@set_time_limit(120);
ignore_user_abort(true);

$feeds = load_json_safe($FEEDS_FILE);
$started = microtime(true);
$updated = refresh_all_cron($feeds, $CACHE_DIR);
save_json_safe($FEEDS_FILE, $updated);
$ms = (int)round((microtime(true) - $started) * 1000);

header('Content-Type: text/plain; charset=utf-8');
echo "ok – " . count($updated) . " feed(s) refreshed in {$ms}ms\n";
