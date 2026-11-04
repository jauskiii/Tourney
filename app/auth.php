<?php
declare(strict_types=1);

/*
 * User accounts, roles, login, password resets.
 * Same setup as Dev Board: email + hashed password in MySQL, a login
 * throttle, one-time reset links, and the last admin can't be removed.
 */

const ROLES = [
    'admin'       => 'Admin',
    'organizer'   => 'Organizer',
    'scorekeeper' => 'Scorekeeper',
];

const ROLE_INFO = [
    'admin'       => 'Everything, including managing users',
    'organizer'   => 'Create and run tournaments: teams, schedule, results, final',
    'scorekeeper' => 'Only enter scores and set matches live',
];

/** Which roles have which permission. */
const ROLE_PERMISSIONS = [
    'tournaments_manage' => ['admin', 'organizer'],
    'scores_enter'       => ['admin', 'organizer', 'scorekeeper'],
    'users_manage'       => ['admin'],
    'settings_manage'    => ['admin'],
];

/* ---------- users ---------- */

function has_any_user(): bool
{
    return (bool) one('SELECT id FROM users LIMIT 1');
}

function find_user_by_email(string $email): ?array
{
    return one('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower(trim($email))]);
}

function find_user(int $id): ?array
{
    return one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
}

function all_users(): array
{
    return all("SELECT * FROM users ORDER BY (role = 'admin') DESC, name");
}

function validate_email(string $email): string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new InvalidArgumentException('That email address is not valid.');
    }
    return $email;
}

function validate_password(string $password): void
{
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }
}

function create_user(string $email, string $name, string $password, string $role): int
{
    $email = validate_email($email);
    validate_password($password);
    if (find_user_by_email($email)) {
        throw new InvalidArgumentException('Someone already uses that email.');
    }
    $role = isset(ROLES[$role]) ? $role : 'scorekeeper';
    $name = str_cut(trim($name), 120);

    q('INSERT INTO users (email, name, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())',
        [$email, $name !== '' ? $name : $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    return (int) db()->lastInsertId();
}

function set_user_email(int $userId, string $email): void
{
    $email = validate_email($email);
    $existing = find_user_by_email($email);
    if ($existing && (int) $existing['id'] !== $userId) {
        throw new InvalidArgumentException('Someone else already uses that email.');
    }
    q('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
}

function set_user_password(int $userId, string $password): void
{
    validate_password($password);
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
}

function admin_count(): int
{
    return (int) one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND is_active = 1")['n'];
}

/* ---------- login ---------- */

function attempt_login(string $email, string $password): bool
{
    $u = find_user_by_email($email);
    if (!$u || !$u['is_active'] || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        set_user_password((int) $u['id'], $password);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** The signed-in user, or null. Deactivated accounts count as signed out. */
function current_user(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    if (empty($_SESSION['uid'])) {
        return $cached = null;
    }
    $u = find_user((int) $_SESSION['uid']);
    return $cached = ($u && $u['is_active']) ? $u : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && $u['role'] === 'admin';
}

function can(string $permission, ?array $u = null): bool
{
    $u ??= current_user();
    return $u !== null && in_array($u['role'], ROLE_PERMISSIONS[$permission] ?? [], true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect(url('/login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? url('/admin/'))));
    }
    return $u;
}

function require_permission(string $permission): array
{
    $u = require_login();
    if (!can($permission, $u)) {
        http_response_code(403);
        page_header('Not allowed');
        echo '<div class="card"><h2>Not allowed</h2><p>Your account (' . e(ROLES[$u['role']] ?? $u['role'])
           . ') can\'t do this. Ask an admin if you need more access.</p><p><a href="' . b() . '/admin/">Back</a></p></div>';
        page_footer();
        exit;
    }
    return $u;
}

/* ---------- login throttle (5 wrong tries = wait a minute) ---------- */

function login_throttled(): bool
{
    $fails = $_SESSION['login_fails'] ?? 0;
    $last = $_SESSION['login_last'] ?? 0;
    return $fails >= 5 && (time() - $last) < 60;
}

function login_failed(): void
{
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
    $_SESSION['login_last'] = time();
}

function login_succeeded(): void
{
    unset($_SESSION['login_fails'], $_SESSION['login_last']);
}

/* ---------- password resets ---------- */

/** Creates a one-time reset token and returns the raw value (only shown once). */
function create_reset_token(int $userId, int $ttlMinutes = 60): string
{
    $raw = bin2hex(random_bytes(32));
    q('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$userId, hash('sha256', $raw), $ttlMinutes]);
    return $raw;
}

function reset_link(int $userId): string
{
    return full_url('/reset.php?token=' . create_reset_token($userId));
}

function consume_reset_token(string $raw): ?int
{
    $row = one('SELECT id, user_id FROM password_resets
                 WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1',
        [hash('sha256', $raw)]);
    if (!$row) {
        return null;
    }
    q('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$row['id']]);
    return (int) $row['user_id'];
}

/** Sends the reset email. Returns false if this server can't send mail. */
function send_reset_email(string $to, string $name, string $link): bool
{
    $from = env('MAIL_FROM');
    if ($from === '' || !function_exists('mail')) {
        return false;
    }
    $site = site_name();
    $body = "Hi {$name},\n\n"
          . "Someone asked to reset your {$site} password.\n\n"
          . $link . "\n\n"
          . "The link works once and expires in an hour.\n"
          . "If this wasn't you, ignore this email – nothing has changed.\n";
    $headers = implode("\r\n", [
        'From: ' . preg_replace('/[\r\n<>"]/', '', $site) . ' <' . $from . '>',
        'Content-Type: text/plain; charset=utf-8',
    ]);
    return @mail($to, "Reset your {$site} password", $body, $headers);
}

/* ---------- site settings ---------- */

function setting_get(string $key): ?string
{
    try {
        $row = one('SELECT value FROM settings WHERE `key` = ?', [$key]);
        return $row ? (string) $row['value'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

function setting_set(string $key, string $value): void
{
    q('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$key, $value]);
}

/** Name shown in the top bar. Set during install, changeable on the Users page. */
function site_name(): string
{
    static $name = null;
    if ($name === null) {
        $v = has_config() ? setting_get('site_name') : null;
        $name = ($v !== null && trim($v) !== '') ? trim($v) : env('APP_NAME', 'Tourney');
    }
    return $name;
}
