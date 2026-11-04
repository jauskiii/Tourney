<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$tournaments = all(
    is_logged_in()
        ? 'SELECT * FROM tournaments ORDER BY start_time DESC'
        : 'SELECT * FROM tournaments WHERE is_public = 1 ORDER BY start_time DESC'
);

page_header('Tournaments');
?>
<h1>Tournaments</h1>
<?php if (!$tournaments): ?>
  <div class="card">
    <p class="muted">No tournaments yet.</p>
    <?php if (can('tournaments_manage')): ?><p><a class="btn primary" href="<?= b() ?>/admin/">Create one</a></p><?php endif; ?>
  </div>
<?php endif; ?>
<div class="tournament-list">
<?php foreach ($tournaments as $t): ?>
  <a class="card tournament-card" href="<?= b() ?>/tournament.php?id=<?= (int) $t['id'] ?>">
    <h2><?= e($t['name']) ?></h2>
    <p class="muted"><?= e(fmt_date($t['start_time'])) ?> · starts <?= e(fmt_time($t['start_time'])) ?></p>
    <?php if ($champ = champion_name(get_final((int) $t['id']))): ?>
      <p>🏆 <?= e($champ) ?></p>
    <?php endif; ?>
    <?php if (!$t['is_public']): ?><span class="badge">Hidden</span><?php endif; ?>
  </a>
<?php endforeach; ?>
</div>
<?php
page_footer();
