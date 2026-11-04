<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';
$me = require_login();
$canManage = can('tournaments_manage', $me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('tournaments_manage');
    csrf_check();
    $name = post_str('name', 150);
    $start = post_datetime('start_time') ?? date('Y-m-d H:00:00', strtotime('+1 hour'));
    if ($name === '') {
        flash('Give the tournament a name.', 'error');
        redirect(url('/admin/'));
    }
    q('INSERT INTO tournaments (name, start_time, match_minutes, break_minutes) VALUES (?, ?, ?, ?)', [
        $name,
        $start,
        max(1, post_int('match_minutes', 20)),
        max(0, post_int('break_minutes', 5)),
    ]);
    $tid = (int) db()->lastInsertId();
    // Sensible defaults: one group and one court.
    q('INSERT INTO tournament_groups (tournament_id, name, position) VALUES (?, ?, 1)', [$tid, 'Group A']);
    q('INSERT INTO courts (tournament_id, name, position) VALUES (?, ?, 1)', [$tid, 'Court 1']);
    flash('Tournament created. Next: add teams.');
    redirect(url("/admin/tournament.php?id={$tid}&tab=teams"));
}

$tournaments = all(
    'SELECT t.*,
            (SELECT COUNT(*) FROM teams WHERE tournament_id = t.id) AS team_count,
            (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id) AS match_count,
            (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id AND status = \'finished\') AS finished_count
       FROM tournaments t ORDER BY t.start_time DESC'
);

page_header('Tournaments', ['nav' => 'admin']);
?>
<h1>Tournaments</h1>

<?php if ($canManage): ?>
<div class="card">
  <h2>New tournament</h2>
  <form method="post" class="row-form">
    <?= csrf_field() ?>
    <label class="grow">Name <input name="name" required maxlength="150" placeholder="e.g. Autumn Cup 2026"></label>
    <label>Starts <input type="datetime-local" name="start_time" value="<?= e(date('Y-m-d\T10:00', strtotime('+1 day'))) ?>" required></label>
    <label>Match length (min) <input type="number" name="match_minutes" value="20" min="1" class="short"></label>
    <label>Break (min) <input type="number" name="break_minutes" value="5" min="0" class="short"></label>
    <button class="btn primary">Create</button>
  </form>
</div>
<?php else: ?>
<p class="muted">You are signed in as a <strong><?= e(ROLES[$me['role']]) ?></strong>. Pick a tournament to enter scores.</p>
<?php endif; ?>

<div class="card">
  <?php if (!$tournaments): ?>
    <p class="muted">No tournaments yet. Create your first one above.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th class="left">Name</th><th>Starts</th><th>Teams</th><th>Matches played</th><th>Visible</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tournaments as $t): ?>
      <tr>
        <td class="left"><a href="<?= b() ?>/admin/tournament.php?id=<?= (int) $t['id'] ?><?= $canManage ? '' : '&tab=schedule' ?>"><strong><?= e($t['name']) ?></strong></a></td>
        <td><?= e(fmt_date($t['start_time'])) ?> <?= e(fmt_time($t['start_time'])) ?></td>
        <td><?= (int) $t['team_count'] ?></td>
        <td><?= (int) $t['finished_count'] ?> / <?= (int) $t['match_count'] ?></td>
        <td><?= $t['is_public'] ? 'Public' : 'Hidden' ?></td>
        <td class="right">
          <a class="btn small" href="<?= b() ?>/admin/tournament.php?id=<?= (int) $t['id'] ?><?= $canManage ? '' : '&tab=schedule' ?>"><?= $canManage ? 'Manage' : 'Enter scores' ?></a>
          <a class="btn small ghost" href="<?= b() ?>/tournament.php?id=<?= (int) $t['id'] ?>">Public page</a>
          <a class="btn small ghost" href="<?= b() ?>/dashboard.php?id=<?= (int) $t['id'] ?>" target="_blank">Dashboard</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php
page_footer();
