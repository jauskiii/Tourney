<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = (string) ($_POST['form'] ?? '');
    try {
        if ($form === 'email') {
            // The password check stops someone at an unlocked screen taking the account over.
            if (!password_verify((string) ($_POST['email_current'] ?? ''), $me['password_hash'])) {
                throw new InvalidArgumentException('Your password is not right.');
            }
            set_user_email((int) $me['id'], (string) ($_POST['email'] ?? ''));
            flash('Email changed.');
        } elseif ($form === 'name') {
            $name = post_str('name', 120);
            if ($name === '') {
                throw new InvalidArgumentException('Name cannot be empty.');
            }
            q('UPDATE users SET name = ? WHERE id = ?', [$name, $me['id']]);
            flash('Name changed.');
        } elseif ($form === 'password') {
            if (!password_verify((string) ($_POST['current'] ?? ''), $me['password_hash'])) {
                throw new InvalidArgumentException('Your current password is not right.');
            }
            if ((string) ($_POST['password'] ?? '') !== (string) ($_POST['confirm'] ?? '')) {
                throw new InvalidArgumentException('The two new passwords do not match.');
            }
            set_user_password((int) $me['id'], (string) $_POST['password']);
            flash('Password changed.');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('/account.php'));
}

page_header('Your account', ['nav' => 'account']);
?>
<h1>Your account</h1>
<p class="muted"><?= e($me['email']) ?> · <strong><?= e(ROLES[$me['role']] ?? $me['role']) ?></strong>: <?= e(ROLE_INFO[$me['role']] ?? '') ?></p>

<div class="two-col">
  <div class="card">
    <h2>Name</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="name">
      <label>Your name <input name="name" maxlength="120" required value="<?= e($me['name']) ?>"></label>
      <div><button class="btn primary">Save name</button></div>
    </form>

    <hr>
    <h2>Email address</h2>
    <p class="muted small">You sign in with this, and password reset links go here.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="email">
      <label>Email <input type="email" name="email" maxlength="190" required value="<?= e($me['email']) ?>"></label>
      <label>Your password, to confirm it's you <input type="password" name="email_current" required autocomplete="current-password"></label>
      <div><button class="btn primary">Change email</button></div>
    </form>
  </div>

  <div class="card">
    <h2>Change password</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="password">
      <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password (at least 8 characters) <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
      <label>Confirm new password <input type="password" name="confirm" required minlength="8" autocomplete="new-password"></label>
      <div><button class="btn primary">Change password</button></div>
    </form>
  </div>
</div>
<?php
page_footer();
