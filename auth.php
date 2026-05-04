<?php
declare(strict_types=1);

/**
 * Session bootstrap + auth helpers.
 * Include from every entry point (index.php, api.php).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('rss_reader');
    session_start();
}

function auth_config_path(): string {
    return __DIR__ . '/config.php';
}

function auth_config(): ?array {
    $path = auth_config_path();
    if (!is_file($path)) return null;
    $cfg = require $path;
    if (!is_array($cfg) || empty($cfg['user']) || empty($cfg['hash'])) return null;
    return $cfg;
}

function auth_logged_in(): bool {
    $cfg = auth_config();
    return $cfg && !empty($_SESSION['user']) && $_SESSION['user'] === $cfg['user'];
}

function auth_login(string $user, string $password): bool {
    $cfg = auth_config();
    if (!$cfg) return false;
    if ($user !== $cfg['user']) return false;
    if (!password_verify($password, $cfg['hash'])) return false;
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    return true;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_setup(string $user, string $password): bool {
    $user = trim($user);
    if ($user === '' || strlen($password) < 8) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $php  = "<?php\nreturn [\n    'user' => " . var_export($user, true) . ",\n    'hash' => " . var_export($hash, true) . ",\n];\n";
    return file_put_contents(auth_config_path(), $php, LOCK_EX) !== false;
}

function require_login_html(): void {
    if (!auth_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_login_api(): void {
    if (!auth_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'auth required']);
        exit;
    }
}
