<?php
declare(strict_types=1);

/* =====================================================================
 * Page layout
 * ===================================================================== */

function page_header(string $title, array $opts = []): void
{
    $bodyClass = $opts['body_class'] ?? '';
    // During install the database may not exist yet.
    $safe = !empty($opts['installer']);
    $appName = $safe ? 'Tourney' : site_name();
    $user = $safe ? null : current_user();
    $active = $opts['nav'] ?? '';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($appName) ?></title>
<link rel="stylesheet" href="<?= b() ?>/assets/style.css?v=9">
<?php $theme = $safe ? theme_defaults() : theme(); ?>
<?php if ($theme['logo']): ?><link rel="icon" href="<?= e($theme['logo']) ?>"><?php endif; ?>
<?= $safe ? '' : theme_css() ?>
</head>
<body class="<?= e($bodyClass) ?>">
<?php if (empty($opts['bare'])): ?>
<header class="topbar">
  <a class="brand" href="<?= b() ?>/">
    <?php if ($theme['logo']): ?><img src="<?= e($theme['logo']) ?>" alt="<?= e($appName) ?>" class="brand-logo"><?php else: ?>🏆<?php endif; ?>
    <?php if (!$theme['logo'] || $theme['show_name']): ?><span><?= e($appName) ?></span><?php endif; ?>
  </a>
  <nav>
    <?php if ($user): ?>
      <a href="<?= b() ?>/admin/" class="<?= $active === 'admin' ? 'active' : '' ?>">Tournaments</a>
      <?php if (can('users_manage', $user)): ?>
        <a href="<?= b() ?>/admin/users.php" class="<?= $active === 'users' ? 'active' : '' ?>">Users</a>
      <?php endif; ?>
      <?php if (can('settings_manage', $user)): ?>
        <a href="<?= b() ?>/admin/appearance.php" class="<?= $active === 'appearance' ? 'active' : '' ?>">Appearance</a>
        <a href="<?= b() ?>/admin/discord.php" class="<?= $active === 'discord' ? 'active' : '' ?>">Discord</a>
      <?php endif; ?>
      <a href="<?= b() ?>/account.php" class="<?= $active === 'account' ? 'active' : '' ?>" title="<?= e($user['email']) ?>"><?= e($user['name']) ?></a>
      <form method="post" action="<?= b() ?>/logout.php" class="inline"><?= csrf_field() ?><button class="linkbtn">Sign out</button></form>
    <?php elseif (!$safe): ?>
      <a href="<?= b() ?>/login.php">Sign in</a>
    <?php endif; ?>
  </nav>
</header>
<main class="container">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php endif;
}

function page_footer(array $opts = []): void
{
    if (empty($opts['bare'])) {
        echo "</main>\n";
    }
    foreach ($opts['scripts'] ?? [] as $src) {
        echo '<script src="' . e($src) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/* =====================================================================
 * Shared components (used by public page, dashboard and admin)
 * ===================================================================== */

function render_standings(array $tournament, array $group, array $rows, int $qualifiers): void
{
    ?>
<div class="standings">
  <h3><?= e($group['name']) ?></h3>
  <table class="table">
    <thead>
      <tr><th>#</th><th class="left">Team</th><th title="Played">P</th><th title="Won">W</th><th title="Drawn">D</th><th title="Lost">L</th><th title="Score for : against" class="hide-sm">+/−</th><th title="Difference">Diff</th><th title="Points">Pts</th></tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="muted">No teams in this group yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r): ?>
      <tr class="<?= $i < $qualifiers ? 'qualify' : '' ?>">
        <td><?= $i + 1 ?></td>
        <td class="left team"><?= e($r['name']) ?></td>
        <td><?= $r['played'] ?></td>
        <td><?= $r['won'] ?></td>
        <td><?= $r['drawn'] ?></td>
        <td><?= $r['lost'] ?></td>
        <td class="hide-sm"><?= $r['for'] ?>:<?= $r['against'] ?></td>
        <td><?= $r['diff'] > 0 ? '+' . $r['diff'] : $r['diff'] ?></td>
        <td class="pts"><?= $r['points'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
}

function render_all_standings(array $tournament): void
{
    $groups = get_groups((int) $tournament['id']);
    $qualifiers = qualifiers_per_group(count($groups));
    echo '<div class="standings-grid">';
    foreach ($groups as $group) {
        render_standings($tournament, $group, group_standings($tournament, (int) $group['id']), $qualifiers);
    }
    echo '</div>';
    if ($qualifiers > 0) {
        $text = $qualifiers === 2 ? 'Top 2 play the final' : 'Group winners play the final';
        echo '<p class="legend"><span class="swatch"></span> ' . e($text) . '</p>';
    }
}

function status_badge(array $m): string
{
    return match ($m['status']) {
        'live' => '<span class="badge badge-live">LIVE</span>',
        'finished' => '<span class="badge badge-done">Final score</span>',
        default => '<span class="badge">Scheduled</span>',
    };
}

function score_text(array $m): string
{
    if ($m['score1'] === null || $m['score2'] === null) {
        return $m['status'] === 'live' ? '0 – 0' : 'vs';
    }
    return (int) $m['score1'] . ' – ' . (int) $m['score2'];
}

/** A match row for public lists. */
function render_match_row(array $m): void
{
    $winner = match_winner($m);
    ?>
<div class="match status-<?= e($m['status']) ?>">
  <div class="match-meta">
    <span class="time"><?= e(fmt_time($m['start_time'])) ?></span>
    <span class="court"><?= e($m['court_name'] ?? '') ?></span>
    <span class="group"><?= $m['stage'] === 'final' ? '<strong>FINAL</strong>' : e($m['group_name'] ?? '') ?></span>
  </div>
  <div class="match-teams">
    <span class="t1 <?= $winner === (int) $m['team1_id'] ? 'winner' : '' ?>"><?= e($m['team1_name']) ?></span>
    <span class="score"><?= e(score_text($m)) ?></span>
    <span class="t2 <?= $winner === (int) $m['team2_id'] ? 'winner' : '' ?>"><?= e($m['team2_name']) ?></span>
  </div>
  <div class="match-status"><?= status_badge($m) ?></div>
</div>
<?php
}

/** Big banner with the final / champion. */
function render_final_banner(?array $final): void
{
    if (!$final) {
        return;
    }
    $champion = champion_name($final);
    ?>
<section class="final-banner <?= $champion ? 'has-champion' : '' ?>">
  <?php if ($champion): ?>
    <div class="final-label">🏆 Champion</div>
    <div class="champion"><?= e($champion) ?></div>
    <div class="final-sub"><?= e($final['team1_name']) ?> <?= e(score_text($final)) ?> <?= e($final['team2_name']) ?></div>
  <?php else: ?>
    <div class="final-label">Final <?= $final['status'] === 'live' ? '<span class="badge badge-live">LIVE</span>' : '' ?></div>
    <div class="final-teams">
      <span><?= e($final['team1_name']) ?></span>
      <span class="score"><?= e(score_text($final)) ?></span>
      <span><?= e($final['team2_name']) ?></span>
    </div>
    <div class="final-sub"><?= e(fmt_time($final['start_time'])) ?><?= $final['court_name'] ? ' · ' . e($final['court_name']) : '' ?></div>
  <?php endif; ?>
</section>
<?php
}
