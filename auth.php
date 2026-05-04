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
    $hash  = password_hash($password, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(24));
    return auth_save_config(['user' => $user, 'hash' => $hash, 'token' => $token]);
}

function auth_save_config(array $cfg): bool {
    $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    return file_put_contents(auth_config_path(), $php, LOCK_EX) !== false;
}

function auth_regenerate_token(): ?string {
    $cfg = auth_config();
    if (!$cfg) return null;
    $cfg['token'] = bin2hex(random_bytes(24));
    return auth_save_config($cfg) ? $cfg['token'] : null;
}

function auth_token_valid(string $token): bool {
    $cfg = auth_config();
    return $cfg && !empty($cfg['token']) && hash_equals($cfg['token'], $token);
}

function auth_logged_in_or_token(): bool {
    if (auth_logged_in()) return true;
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_RSS_TOKEN'] ?? '';
    return is_string($token) && $token !== '' && auth_token_valid($token);
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
