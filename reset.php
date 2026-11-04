<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$raw = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = $raw === '' ? 'That link is missing its token.' : '';
$done = false;

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $password = (string) ($_POST['password'] ?? '');
    if ($password !== (string) ($_POST['confirm'] ?? '')) {
        $error = 'The two passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $userId = consume_reset_token($raw);
        if ($userId === null) {
            $error = 'That link has expired or has already been used. Ask for a new one.';
        } else {
            set_user_password($userId, $password);
            $done = true;
        }
    }
}

page_header('New password');
?>
<div class="card auth-card">
  <p class="eyebrow"><?= e(site_name()) ?></p>
  <?php if ($done): ?>
    <h1>Password changed</h1>
    <p>You can sign in with the new password now.</p>
    <p><a class="btn primary" href="<?= b() ?>/login.php">Go to sign in</a></p>
  <?php else: ?>
    <h1>Choose a new password</h1>
    <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($raw !== ''): ?>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($raw) ?>">
        <label>New password (at least 8 characters) <input type="password" name="password" required minlength="8" autofocus autocomplete="new-password"></label>
        <label>Confirm <input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
        <button class="btn primary">Set password</button>
      </form>
    <?php endif; ?>
    <p class="muted small"><a href="<?= b() ?>/login.php">Back to sign in</a></p>
  <?php endif; ?>
</div>
<?php
page_footer();
