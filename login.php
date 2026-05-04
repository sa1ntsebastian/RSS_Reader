<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

if (auth_logged_in()) { header('Location: index.php'); exit; }

$cfg     = auth_config();
$mode    = $cfg ? 'login' : 'setup';
$error   = null;
$success = null;
$csrfKey = '_csrf';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[$csrfKey] ?? '';
    if (!hash_equals($_SESSION[$csrfKey] ?? '', $token)) {
        $error = 'Sitzung abgelaufen, bitte erneut versuchen.';
    } elseif ($mode === 'setup') {
        $u  = (string)($_POST['user'] ?? '');
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (trim($u) === '')             $error = 'Bitte einen Benutzernamen wählen.';
        elseif (strlen($p1) < 8)         $error = 'Passwort muss mindestens 8 Zeichen haben.';
        elseif ($p1 !== $p2)             $error = 'Passwörter stimmen nicht überein.';
        elseif (!auth_setup($u, $p1))    $error = 'Konnte config.php nicht schreiben (Schreibrechte?).';
        else { $success = 'Konto angelegt. Bitte einloggen.'; $mode = 'login'; }
    } else {
        $u = (string)($_POST['user'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        if (auth_login($u, $p)) { header('Location: index.php'); exit; }
        $error = 'Benutzername oder Passwort falsch.';
        usleep(400000); // mild brute-force friction
    }
}

if (empty($_SESSION[$csrfKey])) $_SESSION[$csrfKey] = bin2hex(random_bytes(16));
$csrf = $_SESSION[$csrfKey];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $mode === 'setup' ? 'Erstkonfiguration' : 'Login' ?> · RSS</title>
  <link rel="stylesheet" href="assets/style.css?v=2">
  <style>
    body { display: flex; align-items: center; justify-content: center; grid-template-columns: none; }
    .login-card {
      background: rgba(0, 0, 0, 0.18);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 36px 32px;
      width: min(380px, 92vw);
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    }
    .login-card h1 {
      font-family: "etoile", sans-serif;
      text-transform: lowercase;
      letter-spacing: 0.08em;
      font-size: 2rem; color: var(--white);
      margin: 0 0 4px;
    }
    .login-card .subtitle {
      font-family: "etoile", sans-serif;
      text-transform: lowercase;
      letter-spacing: 0.04em;
      color: var(--accent);
      margin-bottom: 24px;
      font-size: 0.95rem;
    }
    .login-card label {
      display: block;
      font-family: "etoile", sans-serif;
      text-transform: lowercase;
      letter-spacing: 0.04em;
      color: var(--muted);
      font-size: 0.85rem;
      margin: 12px 0 4px;
    }
    .login-card input {
      width: 100%;
      padding: 10px 14px;
      background: rgba(245, 245, 247, 0.08);
      border: 1px solid var(--border);
      border-radius: 999px;
      color: var(--text);
      font-size: 0.95rem;
      outline: none;
    }
    .login-card input:focus { border-color: var(--accent); }
    .login-card button[type="submit"] {
      width: 100%;
      margin-top: 20px;
      padding: 12px 18px;
      background: var(--accent);
      color: var(--green);
      font-weight: 600;
      font-family: "etoile", sans-serif;
      text-transform: lowercase;
      letter-spacing: 0.06em;
      border: 0;
      border-radius: 999px;
      cursor: pointer;
    }
    .login-card button[type="submit"]:hover { filter: brightness(1.05); }
    .msg {
      margin-top: 14px;
      padding: 10px 14px;
      border-radius: 12px;
      font-size: 0.9rem;
      letter-spacing: 0.02em;
    }
    .msg.error { background: rgba(244, 166, 166, 0.14); color: var(--pastel-coral); border: 1px solid rgba(244, 166, 166, 0.3); }
    .msg.ok    { background: rgba(93, 193, 163, 0.14); color: var(--stronger-turquoise); border: 1px solid rgba(93, 193, 163, 0.3); }
    .hint {
      font-size: 0.8rem; color: var(--muted);
      margin-top: 14px; text-align: center;
    }
  </style>
</head>
<body>
  <form class="login-card" method="post" autocomplete="on">
    <h1>rss</h1>
    <div class="subtitle"><?= $mode === 'setup' ? 'erstkonfiguration' : 'willkommen zurück' ?></div>

    <input type="hidden" name="<?= $csrfKey ?>" value="<?= htmlspecialchars($csrf) ?>">

    <label for="user">benutzername</label>
    <input id="user" name="user" type="text" autocomplete="username" autofocus required>

    <label for="password">passwort</label>
    <input id="password" name="password" type="password" autocomplete="<?= $mode === 'setup' ? 'new-password' : 'current-password' ?>" minlength="<?= $mode === 'setup' ? 8 : 1 ?>" required>

    <?php if ($mode === 'setup'): ?>
      <label for="password2">passwort wiederholen</label>
      <input id="password2" name="password2" type="password" autocomplete="new-password" minlength="8" required>
    <?php endif; ?>

    <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg ok"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <button type="submit"><?= $mode === 'setup' ? 'konto anlegen' : 'einloggen' ?></button>

    <?php if ($mode === 'setup'): ?>
      <div class="hint">mindestens 8 zeichen · wird nur als hash gespeichert</div>
    <?php endif; ?>
  </form>
</body>
</html>
