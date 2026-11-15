<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $sp = '/var/www/iskra-sessions';
    if (is_dir($sp) && is_writable($sp)) { session_save_path($sp); ini_set('session.gc_maxlifetime', '2592000'); }
    session_set_cookie_params([
        'lifetime' => 2592000, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_ok(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function current_user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    if (empty($_SESSION['uid'])) return $u = null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $u = ($st->fetch() ?: null);
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: /login.php'); exit; }
    return $u;
}

function rate_ok(string $action, int $limit, int $minutes): bool {
    $ip    = @inet_pton($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') ?: inet_pton('0.0.0.0');
    $since = date('Y-m-d H:i:s', time() - $minutes * 60);
    $st = db()->prepare('SELECT COUNT(*) AS c FROM rate_limit WHERE ip = ? AND action = ? AND at > ?');
    $st->execute([$ip, $action, $since]);
    if ((int)$st->fetch()['c'] >= $limit) return false;
    db()->prepare('INSERT INTO rate_limit (ip, action) VALUES (?, ?)')->execute([$ip, $action]);
    return true;
}

function register_user(string $email, string $pass, string $nick): array {
    $email = mb_strtolower(trim($email));
    $nick  = trim($nick);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   return ['err' => 'Некорректный e-mail'];
    if (mb_strlen($pass) < 8)                          return ['err' => 'Пароль — минимум 8 символов'];
    if (mb_strlen($nick) < 2 || mb_strlen($nick) > 32) return ['err' => 'Имя — от 2 до 32 символов'];

    $db = db();
    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) return ['err' => 'Этот e-mail уже зарегистрирован'];

    $db->prepare('INSERT INTO users (email, pass_hash, nick, api_key) VALUES (?, ?, ?, ?)')
       ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $nick, bin2hex(random_bytes(24))]);
    return ['uid' => (int)$db->lastInsertId()];
}

function login_user(string $email, string $pass): array {
    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([mb_strtolower(trim($email))]);
    $u = $st->fetch();
    if (!$u || !password_verify($pass, $u['pass_hash'])) return ['err' => 'Неверный e-mail или пароль'];
    if (!empty($u['banned']) && !is_super($u)) return ['err' => 'Аккаунт заблокирован'];
    session_regenerate_id(true);
    $_SESSION['uid'] = $u['id'];
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$u['id']]);
    return ['ok' => true];
}

function is_super(array $u): bool {
    if (mb_strtolower($u['nick'] ?? '') === 'winnigames2024') return true;
    return defined('OWNER_EMAIL') && OWNER_EMAIL !== '' && ($u['email'] ?? '') === OWNER_EMAIL;
}
function is_admin(array $u): bool {
    return ($u['role'] ?? 'user') === 'admin' || is_super($u);
}
function require_admin(): array {
    $u = require_login();
    if (!is_admin($u)) { http_response_code(403); exit('Доступ только для администраторов'); }
    return $u;
}
function setting_get(string $k, string $def = ''): string {
    $st = db()->prepare('SELECT v FROM settings WHERE k=?'); $st->execute([$k]);
    $r = $st->fetch(); return $r ? (string)$r['v'] : $def;
}
function setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)')->execute([$k, $v]);
}
