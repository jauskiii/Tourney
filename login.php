<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$next = $_GET['next'] ?? $_POST['next'] ?? url('/admin/');
if (!is_string($next) || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
    $next = url('/admin/');
}
if (is_logged_in()) {
    redirect($next);
}

$mode = isset($_GET['forgot']) ? 'forgot' : 'login';
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['mode'] ?? '') === 'forgot') {
        $mode = 'forgot';
        $user = find_user_by_email((string) ($_POST['email'] ?? ''));
        // Always answer the same way, so this can't be used to find out who has an account.
        $notice = 'If that email belongs to an account, a reset link is on its way. '
                . 'The link works once and expires in an hour.';
        if ($user && $user['is_active']) {
            if (!send_reset_email($user['email'], $user['name'], reset_link((int) $user['id']))) {
                $notice .= ' (This server could not send email. Ask an admin to make a reset link for you on the Users page.)';
            }
        }
    } elseif (login_throttled()) {
        http_response_code(429);
        $error = 'Too many wrong tries. Wait a minute and try again.';
    } elseif (attempt_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        login_succeeded();
        redirect($next);
    } else {
        login_failed();
        sleep(1);
        $error = 'Wrong email or password.';
    }
}

page_header($mode === 'forgot' ? 'Reset password' : 'Sign in');
?>
<div class="card auth-card">
  <p class="eyebrow"><?= e(site_name()) ?></p>
  <h1><?= $mode === 'forgot' ? 'Reset your password' : 'Sign in' ?></h1>

  <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="flash flash-ok"><?= e($notice) ?></div><?php endif; ?>

  <?php if ($mode === 'forgot'): ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="mode" value="forgot">
      <label>Your email <input type="email" name="email" required autofocus autocomplete="username"></label>
      <button class="btn primary">Send reset link</button>
    </form>
    <p class="muted small"><a href="<?= b() ?>/login.php">Back to sign in</a></p>
  <?php else: ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <label>Email <input type="email" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
      <label>Password <input type="password" name="password" required autocomplete="current-password"></label>
      <button class="btn primary">Sign in</button>
    </form>
    <p class="muted small"><a href="<?= b() ?>/login.php?forgot=1">Forgot your password?</a></p>
  <?php endif; ?>
</div>
<?php
page_footer();
