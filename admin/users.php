<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';

$me = require_permission('users_manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'create':
                $password = (string) ($_POST['password'] ?? '');
                $noPassword = $password === '';
                if ($noPassword) {
                    // They'll choose their own with the link shown below.
                    $password = bin2hex(random_bytes(16));
                }
                $id = create_user((string) ($_POST['email'] ?? ''), post_str('name', 120), $password, (string) ($_POST['role'] ?? ''));
                flash('Account created for ' . post_str('name', 120) . '.');
                if ($noPassword) {
                    $_SESSION['show_link'] = ['for' => post_str('name', 120), 'link' => reset_link($id)];
                }
                break;

            case 'update':
                $target = find_user((int) post_int('user_id', 0)) ?? throw new InvalidArgumentException('No such user.');
                $role = (string) ($_POST['role'] ?? $target['role']);
                $role = isset(ROLES[$role]) ? $role : $target['role'];
                $active = isset($_POST['is_active']) ? 1 : 0;
                if ((int) $target['id'] === (int) $me['id'] && (!$active || $role !== 'admin')) {
                    throw new InvalidArgumentException('You cannot demote or deactivate your own account.');
                }
                // Don't let the last admin be demoted or disabled into a locked site.
                if ($target['role'] === 'admin' && ($role !== 'admin' || !$active) && admin_count() <= 1) {
                    throw new InvalidArgumentException('This is the only admin left. Make someone else admin first.');
                }
                $email = (string) ($_POST['email'] ?? '');
                if (strtolower(trim($email)) !== $target['email']) {
                    set_user_email((int) $target['id'], $email);
                }
                $name = post_str('name', 120);
                q('UPDATE users SET name = ?, role = ?, is_active = ? WHERE id = ?',
                    [$name !== '' ? $name : $target['name'], $role, $active, $target['id']]);
                flash('Saved ' . ($name ?: $target['name']) . '.');
                break;

            case 'set_password':
                $target = find_user((int) post_int('user_id', 0)) ?? throw new InvalidArgumentException('No such user.');
                set_user_password((int) $target['id'], (string) ($_POST['password'] ?? ''));
                flash('New password set for ' . $target['name'] . '. Tell them in person.');
                break;

            case 'reset_link':
                $target = find_user((int) post_int('user_id', 0)) ?? throw new InvalidArgumentException('No such user.');
                $_SESSION['show_link'] = ['for' => $target['name'], 'link' => reset_link((int) $target['id'])];
                break;

            case 'delete':
                $target = find_user((int) post_int('user_id', 0)) ?? throw new InvalidArgumentException('No such user.');
                if ((int) $target['id'] === (int) $me['id']) {
                    throw new InvalidArgumentException('You cannot delete your own account.');
                }
                if ($target['role'] === 'admin' && admin_count() <= 1) {
                    throw new InvalidArgumentException('That is the only admin. Make someone else admin first.');
                }
                q('DELETE FROM users WHERE id = ?', [$target['id']]);
                flash('Deleted ' . $target['name'] . '.');
                break;

        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('/admin/users.php'));
}

$users = all_users();
$showLink = $_SESSION['show_link'] ?? null;
unset($_SESSION['show_link']);

function role_select(string $selected): string
{
    $html = '<select name="role">';
    foreach (ROLES as $key => $label) {
        $html .= '<option value="' . e($key) . '"' . ($key === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
}

page_header('Users', ['nav' => 'users']);
?>
<div class="page-title">
  <div>
    <h1>Users</h1>
    <p class="muted">Who can sign in, and what each person is allowed to do. Everyone else only sees the public pages.</p>
  </div>
</div>

<div class="roles-key">
  <?php foreach (ROLES as $key => $label): ?>
    <span><b><?= e($label) ?></b> <?= e(ROLE_INFO[$key]) ?></span>
  <?php endforeach; ?>
</div>

<?php if ($showLink): ?>
  <div class="card link-box">
    <h2>Password link for <?= e($showLink['for']) ?></h2>
    <p class="muted small">Send this to them. It works once and expires in an hour. They choose their own password with it.</p>
    <input readonly value="<?= e($showLink['link']) ?>" onclick="this.select()" class="link-input">
  </div>
<?php endif; ?>

<div class="card">
  <h2>Add a person</h2>
  <form method="post" class="row-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <label class="grow">Name <input name="name" maxlength="120" required></label>
    <label class="grow">Email <input type="email" name="email" maxlength="190" required></label>
    <label>Role <?= role_select('scorekeeper') ?></label>
    <label class="grow">Password <input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Leave empty to get a link"></label>
    <button class="btn primary">Add</button>
  </form>
  <p class="muted small">Leave the password empty and you'll get a link to send them, so they can choose their own.</p>
</div>

<div class="user-list">
<?php foreach ($users as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
  <div class="card user-card <?= $u['is_active'] ? '' : 'inactive' ?>">
    <form method="post" class="user-edit">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
      <label>Name <input name="name" maxlength="120" value="<?= e($u['name']) ?>"></label>
      <label>Email <input type="email" name="email" maxlength="190" value="<?= e($u['email']) ?>"></label>
      <label>Role <?= role_select($u['role']) ?></label>
      <label class="check"><input type="checkbox" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> Can sign in</label>
      <?php if ($self): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
      <button class="btn small">Save</button>
    </form>
    <div class="user-meta muted small">
      <?= $self ? '<strong>This is you.</strong> · ' : '' ?>
      Last sign-in: <?= $u['last_login_at'] ? e(fmt_date($u['last_login_at']) . ' ' . fmt_time($u['last_login_at'])) : 'never' ?>
      <?= $u['is_active'] ? '' : ' · <span class="badge">Deactivated</span>' ?>
    </div>
    <div class="user-actions">
      <form method="post" class="row-form tight">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_password">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <input type="password" name="password" minlength="8" required placeholder="New password" autocomplete="new-password" aria-label="New password for <?= e($u['name']) ?>">
        <button class="btn small">Set password</button>
      </form>
      <form method="post" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_link">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <button class="btn small ghost">Make reset link</button>
      </form>
      <?php if (!$self): ?>
      <form method="post" class="inline" onsubmit="return confirm('Delete this account? Tournaments and results stay.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <button class="linkbtn danger-text small">Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php
page_footer();
