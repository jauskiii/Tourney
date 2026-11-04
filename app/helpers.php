<?php
declare(strict_types=1);

/** Settings from app/config.php (webhotel). Empty array if the file doesn't exist. */
function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = APP_DIR . '/config.php';
        $config = is_file($file) ? (array) require $file : [];
    }
    return $config;
}

/** True once the database details are known (app/config.php, or environment variables). */
function has_config(): bool
{
    return getenv('DB_HOST') !== false || env('DB_NAME') !== '';
}

/** A setting: environment variable first, then app/config.php. */
function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }
    $config = config();
    if (isset($config[$key]) && (string) $config[$key] !== '') {
        return (string) $config[$key];
    }
    return $default;
}

/**
 * Web path of the site root, e.g. "" for https://site.fi/ or "/turnaus"
 * for https://site.fi/turnaus/. Detected automatically; BASE_URL overrides it.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = env('BASE_URL', '__auto__');
    if ($configured !== '__auto__') {
        return $base = rtrim($configured, '/');
    }
    $script = str_replace('\\', '/', (string) (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ''));
    $root = str_replace('\\', '/', (string) (realpath(PUBLIC_ROOT) ?: PUBLIC_ROOT));
    $name = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $base = '';
    if ($script !== '' && str_starts_with($script, $root)) {
        $relative = substr($script, strlen($root)); // e.g. "/admin/index.php"
        if ($relative !== '' && str_ends_with($name, $relative)) {
            $base = rtrim(substr($name, 0, -strlen($relative)), '/');
        }
    }
    return $base;
}

/** Link to a page of this app: url('/admin/') */
function url(string $path): string
{
    return base_url() . $path;
}

/** Full address including https://host, for links sent by email. */
function full_url(string $path): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . url($path);
}

/** Escaped base path for use in HTML attributes. */
function b(): string
{
    return e(base_url());
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** HTML-escape. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function not_found(string $message = 'Page not found'): never
{
    http_response_code(404);
    page_header('Not found');
    echo '<div class="card"><h2>' . e($message) . '</h2><p><a href="' . b() . '/">Back to tournaments</a></p></div>';
    page_footer();
    exit;
}

/* ---------- Flash messages ---------- */

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $message, 'type' => $type];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* ---------- CSRF protection ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Form expired. Go back, refresh the page and try again.');
    }
}

/* ---------- AJAX ---------- */

/** True when the page's JavaScript sent the request (live score autosave). */
function is_ajax(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_response(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

/* ---------- Input helpers ---------- */

function post_str(string $key, int $maxLen = 150): string
{
    $value = $_POST[$key] ?? '';
    $value = is_string($value) ? trim($value) : '';
    return str_cut($value, $maxLen);
}

function post_int(string $key, ?int $default = null): ?int
{
    $value = $_POST[$key] ?? '';
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int) $value;
    }
    return $default;
}

/** Parses an <input type="datetime-local"> value into MySQL DATETIME, or null. */
function post_datetime(string $key): ?string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || $value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value)
        ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
    return $dt ? $dt->format('Y-m-d H:i:00') : null;
}

/* ---------- Formatting ---------- */

function fmt_time(?string $datetime): string
{
    return $datetime ? date('H:i', strtotime($datetime)) : '–';
}

function fmt_date(?string $datetime): string
{
    return $datetime ? date('D j.n.Y', strtotime($datetime)) : '';
}

function fmt_datetime_input(?string $datetime): string
{
    return $datetime ? date('Y-m-d\TH:i', strtotime($datetime)) : '';
}

/** Cut a UTF-8 string to $maxLen characters (works without the mbstring extension). */
function str_cut(string $value, int $maxLen): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen);
    }
    preg_match('/^.{0,' . $maxLen . '}/us', $value, $m);
    return $m[0] ?? '';
}
