<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$tid = (int) ($_GET['id'] ?? 0);
$t = get_tournament($tid);
if (!$t || (!$t['is_public'] && !is_logged_in())) {
    not_found('Tournament not found');
}

function render_dashboard(array $t): void
{
    $tid = (int) $t['id'];
    $matches = get_matches($tid);
    $courts = get_courts($tid);
    $final = get_final($tid);
    $now = date('Y-m-d H:i:s');

    $playing = [];
    $upcoming = [];
    $results = [];
    foreach ($matches as $m) {
        if ($m['status'] === 'live') {
            $playing[] = $m;
        } elseif ($m['status'] === 'finished') {
            $results[] = $m;
        } else {
            $upcoming[] = $m;
        }
    }
    // Nothing marked live? Show matches whose time slot is running right now.
    if (!$playing) {
        foreach ($upcoming as $i => $m) {
            if ($m['start_time'] && $m['start_time'] <= $now && match_end_time($t, $m) > $now) {
                $playing[] = $m;
                unset($upcoming[$i]);
            }
        }
    }
    $upcoming = array_slice(array_values($upcoming), 0, max(4, count($courts) * 2));
    usort($results, fn($a, $b) => [$b['start_time'], $b['id']] <=> [$a['start_time'], $a['id']]);
    $results = array_slice($results, 0, 6);

    $total = count($matches);
    $done = count(array_filter($matches, fn($m) => $m['status'] === 'finished'));
    ?>
  <header class="dash-head">
    <div class="dash-title">
      <?php if ($logo = theme()['logo']): ?><img src="<?= e($logo) ?>" alt="" class="dash-logo"><?php endif; ?>
      <h1><?= e($t['name']) ?></h1>
    </div>
    <div class="dash-progress">
      <?php if ($total): ?>
        <span><?= $done ?> / <?= $total ?> matches played</span>
        <div class="bar"><div style="width: <?= round(100 * $done / $total) ?>%"></div></div>
      <?php endif; ?>
    </div>
    <div class="clock" id="clock"><?= e(date('H:i')) ?></div>
  </header>

  <?php render_final_banner($final); ?>

  <div class="dash-grid">
    <section class="dash-panel">
      <h2>Standings</h2>
      <?php render_all_standings($t); ?>
    </section>

    <div class="dash-side">
      <section class="dash-panel">
        <h2><span class="dot"></span> Now playing</h2>
        <?php foreach ($playing as $m): render_match_row($m); endforeach; ?>
        <?php if (!$playing): ?><p class="muted">No matches right now.</p><?php endif; ?>
      </section>

      <section class="dash-panel">
        <h2>Up next</h2>
        <?php foreach ($upcoming as $m): render_match_row($m); endforeach; ?>
        <?php if (!$upcoming): ?><p class="muted">No more scheduled matches.</p><?php endif; ?>
      </section>

      <section class="dash-panel">
        <h2>Latest results</h2>
        <?php foreach ($results as $m): render_match_row($m); endforeach; ?>
        <?php if (!$results): ?><p class="muted">No results yet.</p><?php endif; ?>
      </section>
    </div>
  </div>
<?php
}

// The page's JavaScript asks for just the inner part every few seconds.
if (isset($_GET['partial'])) {
    header('Cache-Control: no-store');
    render_dashboard($t);
    exit;
}

page_header($t['name'] . ' – Live', ['body_class' => 'dashboard', 'bare' => true]);
?>
<div id="dash" data-refresh="<?= e(url('/dashboard.php?id=' . $tid . '&partial=1')) ?>">
  <?php render_dashboard($t); ?>
</div>
<div class="dash-foot">
  <span id="updated">Live · updates every 5 s</span>
  <a href="<?= b() ?>/tournament.php?id=<?= $tid ?>">Exit dashboard</a>
  <button id="fullscreen" class="linkbtn">Full screen</button>
</div>
<?php
page_footer(['bare' => true, 'scripts' => [url('/assets/dashboard.js?v=3')]]);
