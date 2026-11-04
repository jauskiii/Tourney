<?php
declare(strict_types=1);

// Setup in two steps, like Dev Board:
//   1. database details -> writes app/config.php and creates the tables
//   2. the first admin account
// Once an account exists this page only says "already set up".

define('IS_INSTALLER', true);
require __DIR__ . '/_init.php';

$error = '';
$step = 1;
$configFile = APP_DIR . '/config.php';

if (has_config()) {
    try {
        $step = has_any_user() ? 3 : 2;
    } catch (DatabaseUnavailable $e) {
        $step = 1;
        $error = is_file($configFile)
            ? 'app/config.php exists, but the database will not connect: ' . $e->getMessage()
              . '. Fix the details below to write a new config.php.'
            : 'The database will not connect: ' . $e->getMessage();
    }
}

$posted = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['step'] ?? '') : '';

/* ---------- step 1: database ---------- */
if ($posted === '1' && $step === 1 && getenv('DB_HOST') === false) {
    csrf_check();
    $cfg = [
        'DB_HOST'   => post_str('db_host', 190) ?: 'localhost',
        'DB_PORT'   => (string) (post_int('db_port', 3306)),
        'DB_NAME'   => post_str('db_name', 190),
        'DB_USER'   => post_str('db_user', 190),
        'DB_PASS'   => (string) ($_POST['db_pass'] ?? ''),
        'MAIL_FROM' => post_str('mail_from', 190),
        'TZ'        => 'Europe/Helsinki',
        'BASE_URL'  => '',
    ];
    try {
        $pdo = db_connect($cfg['DB_HOST'], $cfg['DB_PORT'], $cfg['DB_NAME'], $cfg['DB_USER'], $cfg['DB_PASS']);
        migrate($pdo);

        $php = "<?php\n// Written by install.php. Keep this file private.\n// Edit by hand if your database details change.\nreturn "
             . var_export($cfg, true) . ";\n";
        if (@file_put_contents($configFile, $php) === false) {
            $error = 'Tables created, but PHP could not write app/config.php. Create that file yourself '
                   . 'with the contents below (File Manager → new file), then reload this page.';
            $manualConfig = $php;
        } else {
            redirect(url('/install.php'));
        }
    } catch (PDOException $e) {
        $error = 'Could not connect: ' . $e->getMessage();
    }
}

/* ---------- step 2: first admin account ---------- */
if ($posted === '2' && $step === 2) {
    csrf_check();
    if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['confirm'] ?? '')) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            create_user(
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                'admin'
            );
            $siteName = post_str('site_name', 60);
            setting_set('site_name', $siteName !== '' ? $siteName : 'Tourney');
            attempt_login((string) $_POST['email'], (string) $_POST['password']);
            flash('Welcome! Your admin account is ready. Create your first tournament below.');
            redirect(url('/admin/'));
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}

page_header('Set up', ['installer' => true]);
?>
<div class="card auth-card wide">
  <p class="eyebrow">Tourney setup · step <?= min($step, 2) ?> of 2</p>

  <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($manualConfig)): ?><pre class="code"><?= e($manualConfig) ?></pre><?php endif; ?>

  <?php if ($step === 3): ?>
    <h1>Already set up</h1>
    <p>This site already has an account. You can delete <code>install.php</code> from the server if you like.
       It does nothing now that setup is finished.</p>
    <p><a class="btn primary" href="<?= b() ?>/login.php">Go to sign in</a></p>

  <?php elseif ($step === 2): ?>
    <h1>Create the admin account</h1>
    <p class="muted">This first account is the administrator. It can add everyone else on the Users page.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="2">
      <label>Your name <input name="name" required maxlength="120" autofocus value="<?= e($_POST['name'] ?? '') ?>"></label>
      <label>Email (you sign in with this) <input type="email" name="email" required maxlength="190" autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
      <label>Password (at least 8 characters) <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label>Confirm password <input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
      <hr>
      <label>Site name <input name="site_name" maxlength="60" required value="<?= e($_POST['site_name'] ?? 'Tourney') ?>"></label>
      <p class="muted small">Shown in the top bar. You can change it later on the Appearance page, where you can also add a logo and colors.</p>
      <div><button class="btn primary">Create account</button></div>
    </form>

  <?php elseif (getenv('DB_HOST') !== false): ?>
    <h1>Waiting for the database</h1>
    <p>The database details come from the server's environment settings. The database may still be starting.
       Wait 20–30 seconds and <a href="<?= b() ?>/install.php">reload this page</a>.</p>

  <?php else: ?>
    <h1>Database</h1>
    <p class="muted">Create a MySQL database and user in your hosting control panel first, then fill in the details here.
       cPanel usually adds your account name in front of both, e.g. <code>myacct_tourney</code>.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="1">
      <div class="row-form">
        <label class="grow">Host <input name="db_host" value="<?= e($_POST['db_host'] ?? 'localhost') ?>" required></label>
        <label>Port <input name="db_port" value="<?= e($_POST['db_port'] ?? '3306') ?>" class="short" required></label>
      </div>
      <label>Database name <input name="db_name" required autofocus value="<?= e($_POST['db_name'] ?? '') ?>"></label>
      <label>Database user <input name="db_user" required value="<?= e($_POST['db_user'] ?? '') ?>"></label>
      <label>Database password <input type="password" name="db_pass"></label>
      <label>From address for password reset emails (optional)
        <input type="email" name="mail_from" placeholder="noreply@yoursite.fi" value="<?= e($_POST['mail_from'] ?? '') ?>">
      </label>
      <div><button class="btn primary">Connect and create tables</button></div>
    </form>
  <?php endif; ?>
</div>
<?php
page_footer();
