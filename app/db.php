<?php
declare(strict_types=1);

/**
 * Returns the shared PDO connection. Waits for MySQL to come up and
 * creates the tables automatically the first time.
 */
final class DatabaseUnavailable extends RuntimeException
{
}

/** Opens a MySQL connection with the given details (used by db() and the installer). */
function db_connect(string $host, string $port, string $name, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $name);
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * Returns the shared PDO connection and creates the tables the first time.
 * Throws DatabaseUnavailable if MySQL can't be reached.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $lastError = null;
    // If DB_HOST comes from the environment, retry a few times while MySQL starts. Otherwise fail fast.
    $attempts = getenv('DB_HOST') !== false ? 10 : 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        try {
            $pdo = db_connect(
                env('DB_HOST', 'localhost'), env('DB_PORT', '3306'), env('DB_NAME'),
                env('DB_USER'), env('DB_PASS')
            );
            break;
        } catch (PDOException $e) {
            $lastError = $e;
            $pdo = null;
            if ($attempt + 1 < $attempts) {
                sleep(1);
            }
        }
    }

    if (!$pdo) {
        error_log('DB connection failed: ' . ($lastError ? $lastError->getMessage() : 'unknown'));
        throw new DatabaseUnavailable($lastError ? $lastError->getMessage() : 'unknown error');
    }

    migrate($pdo);
    return $pdo;
}

/** Friendly page shown when the database can't be reached. */
function render_db_error(): void
{
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:3rem auto;padding:0 16px">'
       . '<h1>Cannot connect to the database</h1>'
       . '<p>Check DB_HOST, DB_NAME, DB_USER and DB_PASS in <code>app/config.php</code>. '
       . 'To enter them again, delete <code>app/config.php</code> and open <code>install.php</code>.</p>'
       . '<p>On a Debian server, check the database is running: <code>sudo systemctl status mariadb</code></p></body>';
}

/** Creates the tables if they are missing. Safe to run any number of times. */
function migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // `settings` is created last, so if it exists the schema is complete.
    // Older installs without the account tables get them added here.
    if (!$pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn()) {
        $sql = (string) file_get_contents(__DIR__ . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
    migrate_columns($pdo);
}

/**
 * Columns added after the first release. Each check is cheap and safe to run on
 * every request; works on both MySQL 8 and MariaDB (no "ADD COLUMN IF NOT EXISTS").
 */
function migrate_columns(PDO $pdo): void
{
    $add = [
        // Discord username or ID of a player, used to give team roles in Discord.
        ['players', 'discord', 'VARCHAR(100) NULL AFTER number'],
        // Map ban links: each team gets its own (never shown on public pages).
        ['matches', 'mapban1', 'VARCHAR(500) NULL AFTER status'],
        ['matches', 'mapban2', 'VARCHAR(500) NULL AFTER mapban1'],
        // Self-registration (register.php): off by default, set per tournament.
        ['tournaments', 'reg_open', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public'],
        ['tournaments', 'reg_code', 'VARCHAR(50) NULL AFTER reg_open'],
        ['tournaments', 'reg_new_teams', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER reg_code'],
        ['tournaments', 'reg_max_players', 'INT NOT NULL DEFAULT 5 AFTER reg_new_teams'],
        ['tournaments', 'reg_discord', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER reg_max_players'],
        ['tournaments', 'reg_channel', 'VARCHAR(30) NULL AFTER reg_discord'],
        ['players', 'self_registered', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER discord'],
    ];
    foreach ($add as [$table, $column, $definition]) {
        $exists = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column))->fetchColumn();
        if (!$exists) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

/** Run a prepared statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
