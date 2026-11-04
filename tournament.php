<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$tid = (int) ($_GET['id'] ?? 0);
$t = get_tournament($tid);
if (!$t || (!$t['is_public'] && !is_logged_in())) {
    not_found('Tournament not found');
}

$matches = get_matches($tid);
$final = get_final($tid);
$teams = get_teams($tid);
$players = get_players_by_team($tid);

page_header($t['name']);
?>
<div class="page-title">
  <div>
    <h1><?= e($t['name']) ?></h1>
    <p class="muted"><?= e(fmt_date($t['start_time'])) ?> · starts <?= e(fmt_time($t['start_time'])) ?></p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= b() ?>/dashboard.php?id=<?= $tid ?>">📺 Live dashboard</a>
    <?php if (is_logged_in()): ?><a class="btn ghost" href="<?= b() ?>/admin/tournament.php?id=<?= $tid ?>">Manage</a><?php endif; ?>
  </div>
</div>

<?php render_final_banner($final); ?>

<section class="card">
  <h2>Standings</h2>
  <?php render_all_standings($t); ?>
</section>

<section class="card">
  <h2>Schedule &amp; results</h2>
  <?php if (!$matches): ?>
    <p class="muted">The schedule has not been published yet.</p>
  <?php endif; ?>
  <?php
  $lastTime = null;
  foreach ($matches as $m) {
      if ($m['start_time'] !== $lastTime) {
          $lastTime = $m['start_time'];
          echo '<div class="slot-head">' . e(fmt_time($m['start_time'])) . '</div>';
      }
      render_match_row($m);
  }
  ?>
</section>

<section class="card">
  <h2>Teams</h2>
  <div class="team-grid">
  <?php foreach ($teams as $team): ?>
    <div class="team-card-public">
      <h3><?= e($team['name']) ?> <?php if ($team['group_name']): ?><span class="muted small"><?= e($team['group_name']) ?></span><?php endif; ?></h3>
      <ul class="players">
        <?php foreach ($players[(int) $team['id']] ?? [] as $p): ?>
          <li><?= $p['number'] !== null ? '<span class="num">#' . e($p['number']) . '</span> ' : '' ?><?= e($p['name']) ?></li>
        <?php endforeach; ?>
        <?php if (empty($players[(int) $team['id']])): ?><li class="muted small">No players listed</li><?php endif; ?>
      </ul>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php
page_footer();
