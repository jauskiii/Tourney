<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('This app needs PHP 8.1 or newer. You have PHP ' . PHP_VERSION . '. Change the PHP version in your hosting control panel.');
}

define('APP_DIR', __DIR__);
if (!defined('PUBLIC_ROOT')) {
    define('PUBLIC_ROOT', dirname(__DIR__) . '/public');
}

require __DIR__ . '/helpers.php';

date_default_timezone_set(env('TZ', 'Europe/Helsinki'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Containers (e.g. Pterodactyl) may have a session folder PHP can't write to:
// then fall back to the system temp folder, or app/sessions (never served to the web).
$sessionPath = (string) ini_get('session.save_path');
$sessionPath = $sessionPath !== '' ? preg_replace('/^\d+;/', '', $sessionPath) : '';
if ($sessionPath === '' || !is_dir($sessionPath) || !is_writable($sessionPath)) {
    foreach ([sys_get_temp_dir() . '/tourney-sessions', __DIR__ . '/sessions'] as $dir) {
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            session_save_path($dir);
            break;
        }
    }
}
unset($sessionPath, $dir);

session_name('tourney');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => is_https(),
    'cookie_path'     => base_url() . '/',
    'use_strict_mode' => true,
]);

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/theme.php';
require __DIR__ . '/tournament.php';
require __DIR__ . '/discord.php';
require __DIR__ . '/views.php';

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof DatabaseUnavailable) {
        render_db_error();
        return;
    }
    error_log('Uncaught: ' . $e);
    http_response_code(500);
    echo '<h1>Something went wrong</h1><p>The error was written to the server log.</p>';
});

// Not set up yet? Everything goes to the installer (like Dev Board).
if (!defined('IS_INSTALLER') && (!has_config() || !has_any_user())) {
    redirect(url('/install.php'));
}
