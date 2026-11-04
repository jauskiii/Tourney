<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';
require APP_DIR . '/admin_actions.php';
$me = require_login();
$canManage = can('tournaments_manage', $me);

$tid = (int) ($_GET['id'] ?? 0);
$t = get_tournament($tid);
if (!$t) {
    not_found('Tournament not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_admin_action($t);
}

$tabs = [
    'overview' => 'Settings',
    'teams'    => 'Teams & players',
    'groups'   => 'Groups & courts',
    'schedule' => 'Schedule & results',
    'final'    => 'Final',
];
if (!$canManage) {
    // Scorekeepers only see the matches and the final.
    $tabs = ['schedule' => 'Scores', 'final' => 'Final'];
}
$tab = $_GET['tab'] ?? array_key_first($tabs);
if (!isset($tabs[$tab])) {
    $tab = array_key_first($tabs);
}

$groups = get_groups($tid);
$courts = get_courts($tid);
$teams  = get_teams($tid);

/** Hidden fields every form on this page needs. */
function form_base(string $action, string $tab): string
{
    return csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="tab" value="' . e($tab) . '">';
}

function group_select(array $groups, ?int $selected, string $name = 'group_id'): string
{
    $html = '<select name="' . e($name) . '"><option value="">— no group —</option>';
    foreach ($groups as $g) {
        $sel = (int) $g['id'] === $selected ? ' selected' : '';
        $html .= '<option value="' . (int) $g['id'] . '"' . $sel . '>' . e($g['name']) . '</option>';
    }
    return $html . '</select>';
}

function court_select(array $courts, ?int $selected): string
{
    $html = '<select name="court_id"><option value="">— no court —</option>';
    foreach ($courts as $c) {
        $sel = (int) $c['id'] === $selected ? ' selected' : '';
        $html .= '<option value="' . (int) $c['id'] . '"' . $sel . '>' . e($c['name']) . '</option>';
    }
    return $html . '</select>';
}

function team_select(array $teams, ?int $selected, string $name): string
{
    $html = '<select name="' . e($name) . '" required><option value="">— pick team —</option>';
    foreach ($teams as $team) {
        $sel = (int) $team['id'] === $selected ? ' selected' : '';
        $label = $team['name'] . ($team['group_name'] ? ' (' . $team['group_name'] . ')' : '');
        $html .= '<option value="' . (int) $team['id'] . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
}

/** Admin editor row for one match. */
function render_match_editor(array $m, array $courts, string $tab, bool $mayMove = true): void
{
    $isFinal = $m['stage'] === 'final';
    $tied = $m['score1'] !== null && $m['score1'] === $m['score2'];
    ?>
<form method="post" class="match-edit status-<?= e($m['status']) ?>" id="m<?= (int) $m['id'] ?>">
  <?= form_base('save_match', $tab) ?>
  <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
  <div class="me-when">
    <?php if ($mayMove): ?>
      <input type="datetime-local" name="start_time" value="<?= e(fmt_datetime_input($m['start_time'])) ?>">
      <?= court_select($courts, $m['court_id'] !== null ? (int) $m['court_id'] : null) ?>
    <?php else: ?>
      <strong><?= e(fmt_time($m['start_time'])) ?></strong> <span><?= e($m['court_name'] ?? '') ?></span>
    <?php endif; ?>
    <span class="muted small"><?= $isFinal ? '<strong>FINAL</strong>' : e(($m['group_name'] ?? '') . ' · Round ' . $m['round']) ?></span>
  </div>
  <div class="me-score">
    <span class="team right t1"><?= e($m['team1_name']) ?></span>
    <span class="score-box s1">
      <button type="button" class="step" data-target="score1" data-delta="-1" aria-label="Minus one for <?= e($m['team1_name']) ?>">−</button>
      <input type="number" name="score1" min="0" class="score-in" value="<?= e($m['score1'] ?? '') ?>" aria-label="Score <?= e($m['team1_name']) ?>">
      <button type="button" class="step" data-target="score1" data-delta="1" aria-label="Plus one for <?= e($m['team1_name']) ?>">+</button>
    </span>
    <span class="dash">–</span>
    <span class="score-box s2">
      <button type="button" class="step" data-target="score2" data-delta="-1" aria-label="Minus one for <?= e($m['team2_name']) ?>">−</button>
      <input type="number" name="score2" min="0" class="score-in" value="<?= e($m['score2'] ?? '') ?>" aria-label="Score <?= e($m['team2_name']) ?>">
      <button type="button" class="step" data-target="score2" data-delta="1" aria-label="Plus one for <?= e($m['team2_name']) ?>">+</button>
    </span>
    <span class="team t2"><?= e($m['team2_name']) ?></span>
  </div>
  <?php if ($isFinal): ?>
  <div class="me-tiebreak">
    <label class="small">If tied, winner:
      <select name="winner_team_id">
        <option value="">—</option>
        <option value="<?= (int) $m['team1_id'] ?>" <?= $tied && (int) $m['winner_team_id'] === (int) $m['team1_id'] ? 'selected' : '' ?>><?= e($m['team1_name']) ?></option>
        <option value="<?= (int) $m['team2_id'] ?>" <?= $tied && (int) $m['winner_team_id'] === (int) $m['team2_id'] ? 'selected' : '' ?>><?= e($m['team2_name']) ?></option>
      </select>
    </label>
  </div>
  <?php endif; ?>
  <div class="me-actions">
    <span class="save-state" aria-live="polite"></span>
    <?= status_badge($m) ?>
    <button name="status" value="live" class="btn small live">▶ Live</button>
    <button name="status" value="finished" class="btn small primary">✓ Finished</button>
    <button name="status" value="keep" class="btn small ghost" title="Save time/court/score without changing status">Save</button>
  </div>
</form>
<?php
}

/** "Message both teams on Discord" (e.g. the map ban link). Shown when the bot is connected. */
function render_discord_notify(array $m, string $tab): void
{
    if (!discord_enabled() || $m['status'] === 'finished') {
        return;
    }
    ?>
<form method="post" class="discord-notify row-form tight">
  <?= form_base('discord_notify', $tab) ?>
  <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
  <input name="message" class="grow" maxlength="1500" placeholder="Message to <?= e($m['team1_name']) ?> &amp; <?= e($m['team2_name']) ?> on Discord, e.g. map ban link" aria-label="Discord message to both teams">
  <button class="btn small ghost" title="Sends to both teams' Discord channels and pings their roles">📣 Send</button>
</form>
<?php
}

page_header($t['name'] . ' – Admin', ['nav' => 'admin']);
?>
<div class="page-title">
  <div>
    <a href="<?= b() ?>/admin/" class="muted small">← All tournaments</a>
    <h1><?= e($t['name']) ?></h1>
    <p class="muted"><?= e(fmt_date($t['start_time'])) ?> at <?= e(fmt_time($t['start_time'])) ?> · <?= count($teams) ?> teams · <?= $t['is_public'] ? 'Public' : 'Hidden' ?></p>
  </div>
  <div class="actions">
    <a class="btn ghost" href="<?= b() ?>/tournament.php?id=<?= $tid ?>" target="_blank">Public page ↗</a>
    <a class="btn ghost" href="<?= b() ?>/dashboard.php?id=<?= $tid ?>" target="_blank">Live dashboard ↗</a>
  </div>
</div>

<nav class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="?id=<?= $tid ?>&tab=<?= $key ?>" class="<?= $key === $tab ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
<!-- ===================== SETTINGS ===================== -->
<div class="card">
  <h2>Settings</h2>
  <form method="post" class="grid-form">
    <?= form_base('update_settings', $tab) ?>
    <label class="span2">Name <input name="name" value="<?= e($t['name']) ?>" required maxlength="150"></label>
    <label>Start time <input type="datetime-local" name="start_time" value="<?= e(fmt_datetime_input($t['start_time'])) ?>" required></label>
    <label>Match length (minutes) <input type="number" name="match_minutes" value="<?= (int) $t['match_minutes'] ?>" min="1"></label>
    <label>Break between matches (minutes) <input type="number" name="break_minutes" value="<?= (int) $t['break_minutes'] ?>" min="0"></label>
    <label>Points for win <input type="number" name="points_win" value="<?= (int) $t['points_win'] ?>"></label>
    <label>Points for draw <input type="number" name="points_draw" value="<?= (int) $t['points_draw'] ?>"></label>
    <label>Points for loss <input type="number" name="points_loss" value="<?= (int) $t['points_loss'] ?>"></label>
    <label class="check span2"><input type="checkbox" name="is_public" <?= $t['is_public'] ? 'checked' : '' ?>> Show on the public site</label>
    <div class="span2"><button class="btn primary">Save settings</button></div>
  </form>
</div>

<div class="card">
  <h2>How to run the tournament</h2>
  <ol class="steps">
    <li><a href="?id=<?= $tid ?>&tab=teams">Add teams</a> and their players.</li>
    <li><a href="?id=<?= $tid ?>&tab=groups">Set up groups and courts</a>. One group = everyone plays everyone, top 2 play the final.</li>
    <li><a href="?id=<?= $tid ?>&tab=schedule">Generate the schedule</a>, then enter results as matches finish.</li>
    <li><a href="?id=<?= $tid ?>&tab=final">Create the final</a> from the top teams and enter its result.</li>
    <li>Put the <a href="<?= b() ?>/dashboard.php?id=<?= $tid ?>" target="_blank">live dashboard</a> on a TV. It updates by itself.</li>
  </ol>
</div>

<div class="card danger-zone">
  <h2>Delete tournament</h2>
  <form method="post" class="row-form">
    <?= form_base('delete_tournament', $tab) ?>
    <label class="grow">Type <strong><?= e($t['name']) ?></strong> to confirm <input name="confirm_name" autocomplete="off"></label>
    <button class="btn danger">Delete forever</button>
  </form>
</div>

<?php elseif ($tab === 'teams'): ?>
<!-- ===================== TEAMS & PLAYERS ===================== -->
<?php $players = get_players_by_team($tid); ?>
<?php if (discord_enabled()):
    $withDiscord = 0; $allPlayers = 0;
    foreach ($players as $list) { foreach ($list as $p) { $allPlayers++; if (trim((string) ($p['discord'] ?? '')) !== '') { $withDiscord++; } } } ?>
<div class="card discord-card">
  <h2>Discord</h2>
  <p class="muted small">
    Sync creates a Discord role<?= discord_on('discord_create_channels') ? ' and a private text + voice channel' : '' ?> for every team
    and gives it to the players who have a Discord name. Syncing again updates the roles to match this page.
    <strong><?= $withDiscord ?> / <?= $allPlayers ?></strong> players have a Discord name.
  </p>
  <div class="row-form tight">
    <form method="post" class="inline">
      <?= form_base('discord_sync', $tab) ?>
      <button class="btn primary">⇅ Sync teams to Discord</button>
    </form>
    <form method="post" class="inline" onsubmit="return confirm('Remove the Discord roles and channels of all <?= count($teams) ?> teams in this tournament?');">
      <?= form_base('discord_remove', $tab) ?>
      <button class="btn ghost">Remove teams from Discord</button>
    </form>
  </div>
</div>
<?php elseif (can('settings_manage', $me)): ?>
<p class="muted small">Tip: connect the <a href="<?= b() ?>/admin/discord.php">Discord bot</a> to give team roles and channels automatically.</p>
<?php endif; ?>
<div class="card">
  <h2>Add teams</h2>
  <form method="post" class="stack">
    <?= form_base('add_teams', $tab) ?>
    <label>Team names – one per line
      <textarea name="names" rows="4" required placeholder="Team Red&#10;Team Blue&#10;Team Green"></textarea>
    </label>
    <label>Put them in group <?= group_select($groups, isset($groups[0]) ? (int) $groups[0]['id'] : null) ?></label>
    <div><button class="btn primary">Add teams</button></div>
  </form>
</div>

<?php if (!$teams): ?>
  <p class="muted">No teams yet.</p>
<?php endif; ?>

<div class="team-grid">
<?php foreach ($teams as $team): $teamId = (int) $team['id']; ?>
  <div class="card team-card">
    <form method="post" class="row-form tight">
      <?= form_base('update_team', $tab) ?>
      <input type="hidden" name="team_id" value="<?= $teamId ?>">
      <input name="name" value="<?= e($team['name']) ?>" class="grow team-name" maxlength="100" aria-label="Team name">
      <?= group_select($groups, $team['group_id'] !== null ? (int) $team['group_id'] : null) ?>
      <button class="btn small">Save</button>
    </form>

    <ul class="players">
      <?php foreach ($players[$teamId] ?? [] as $p): ?>
        <li>
          <span><?= $p['number'] !== null ? '<span class="num">#' . e($p['number']) . '</span> ' : '' ?><?= e($p['name']) ?></span>
          <form method="post" class="inline discord-edit" title="Discord username or ID">
            <?= form_base('set_player_discord', $tab) ?>
            <input type="hidden" name="player_id" value="<?= (int) $p['id'] ?>">
            <input name="discord" value="<?= e($p['discord'] ?? '') ?>" placeholder="Discord" class="discord-in" maxlength="100" aria-label="Discord name of <?= e($p['name']) ?>">
            <button class="linkbtn small" title="Save Discord name">✓</button>
          </form>
          <form method="post" class="inline">
            <?= form_base('delete_player', $tab) ?>
            <input type="hidden" name="player_id" value="<?= (int) $p['id'] ?>">
            <button class="linkbtn danger-text" title="Remove player">✕</button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if (empty($players[$teamId])): ?><li class="muted small">No players yet</li><?php endif; ?>
    </ul>

    <form method="post" class="row-form tight">
      <?= form_base('add_player', $tab) ?>
      <input type="hidden" name="team_id" value="<?= $teamId ?>">
      <input name="number" placeholder="#" class="tiny" maxlength="10" aria-label="Shirt number">
      <input name="name" placeholder="Player name" class="grow" required maxlength="100" aria-label="Player name">
      <input name="discord" placeholder="Discord" class="discord-in" maxlength="100" aria-label="Discord username (optional)">
      <button class="btn small">Add</button>
    </form>

    <form method="post" class="team-delete" onsubmit="return confirm('Delete <?= e(addslashes($team['name'])) ?> with all its players and matches?');">
      <?= form_base('delete_team', $tab) ?>
      <input type="hidden" name="team_id" value="<?= $teamId ?>">
      <button class="linkbtn danger-text small">Delete team</button>
    </form>
  </div>
<?php endforeach; ?>
</div>

<?php elseif ($tab === 'groups'): ?>
<!-- ===================== GROUPS & COURTS ===================== -->
<div class="two-col">
  <div class="card">
    <h2>Groups</h2>
    <p class="muted small">Each group plays its own round robin. With <strong>one group</strong> the top 2 play the final; with <strong>two groups</strong> the group winners do.</p>
    <?php foreach ($groups as $g):
        $count = count(array_filter($teams, fn($x) => (int) $x['group_id'] === (int) $g['id'])); ?>
      <div class="list-row">
        <form method="post" class="row-form tight grow">
          <?= form_base('rename_group', $tab) ?>
          <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
          <input name="name" value="<?= e($g['name']) ?>" maxlength="50" class="grow" aria-label="Group name">
          <span class="muted small"><?= $count ?> teams</span>
          <button class="btn small">Rename</button>
        </form>
        <form method="post" class="inline" onsubmit="return confirm('Delete this group? Its matches are deleted too.');">
          <?= form_base('delete_group', $tab) ?>
          <input type="hidden" name="group_id" value="<?= (int) $g['id'] ?>">
          <button class="linkbtn danger-text">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
    <form method="post" class="row-form tight">
      <?= form_base('add_group', $tab) ?>
      <input name="name" placeholder="New group name (optional)" class="grow" maxlength="50">
      <button class="btn small primary">Add group</button>
    </form>
    <hr>
    <form method="post" onsubmit="return confirm('Shuffle all teams randomly into the groups?');">
      <?= form_base('distribute_teams', $tab) ?>
      <button class="btn">🎲 Shuffle teams evenly into groups</button>
    </form>
  </div>

  <div class="card">
    <h2>Courts / fields</h2>
    <p class="muted small">Matches run at the same time on different courts. More courts = shorter tournament.</p>
    <?php foreach ($courts as $c): ?>
      <div class="list-row">
        <form method="post" class="row-form tight grow">
          <?= form_base('rename_court', $tab) ?>
          <input type="hidden" name="court_id" value="<?= (int) $c['id'] ?>">
          <input name="name" value="<?= e($c['name']) ?>" maxlength="50" class="grow" aria-label="Court name">
          <button class="btn small">Rename</button>
        </form>
        <form method="post" class="inline" onsubmit="return confirm('Delete this court?');">
          <?= form_base('delete_court', $tab) ?>
          <input type="hidden" name="court_id" value="<?= (int) $c['id'] ?>">
          <button class="linkbtn danger-text">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
    <form method="post" class="row-form tight">
      <?= form_base('add_court', $tab) ?>
      <input name="name" placeholder="New court name (optional)" class="grow" maxlength="50">
      <button class="btn small primary">Add court</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'schedule'): ?>
<!-- ===================== SCHEDULE & RESULTS ===================== -->
<?php
$matches = get_matches($tid);
$finishedGroup = count(array_filter($matches, fn($m) => $m['stage'] === 'group' && $m['status'] === 'finished'));
?>
<?php if ($canManage): ?>
<div class="card">
  <h2>Round robin schedule</h2>
  <p class="muted small">
    Creates every match inside each group, spread over <?= count($courts) ?> court(s),
    starting <?= e(fmt_time($t['start_time'])) ?>, <?= (int) $t['match_minutes'] ?> min matches + <?= (int) $t['break_minutes'] ?> min breaks.
    Regenerate after changing teams, groups or courts.
  </p>
  <form method="post" onsubmit="return confirm(<?= $finishedGroup ? "'This DELETES all " . $finishedGroup . " entered group results and makes a new schedule. Continue?'" : "'Create the schedule now?'" ?>);">
    <?= form_base('generate_schedule', $tab) ?>
    <button class="btn primary"><?= $matches ? '↻ Regenerate schedule' : 'Generate schedule' ?></button>
  </form>
</div>
<?php elseif (!$matches): ?>
  <div class="card"><p class="muted">No schedule yet. An organizer needs to create it first.</p></div>
<?php endif; ?>

<?php if ($matches): ?>
  <div class="card">
    <h2>Matches <span class="muted small">(<?= count(array_filter($matches, fn($m) => $m['status'] === 'finished')) ?> / <?= count($matches) ?> finished)</span></h2>
    <p class="muted small">Press <strong>▶ Live</strong> when a match starts. While it's live, every score change (typing or the <strong>+ / −</strong> buttons) saves by itself and shows on the dashboard within a few seconds. Press <strong>✓ Finished</strong> at the end.</p>
    <?php
    $lastTime = null;
    foreach ($matches as $m):
        if ($m['start_time'] !== $lastTime): $lastTime = $m['start_time']; ?>
          <div class="slot-head"><?= e(fmt_time($m['start_time'])) ?></div>
        <?php endif;
        render_match_editor($m, $courts, $tab, $canManage);
        render_discord_notify($m, $tab);
        if ($m['status'] !== 'scheduled'): ?>
          <form method="post" class="reset-form" onsubmit="return confirm('Clear this score?');">
            <?= form_base('reset_match', $tab) ?>
            <input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>">
            <button class="linkbtn small muted">↺ reset score</button>
          </form>
        <?php endif;
    endforeach; ?>
  </div>
<?php endif; ?>

<?php elseif ($tab === 'final'): ?>
<!-- ===================== FINAL ===================== -->
<?php
$final = get_final($tid);
[$sug1, $sug2] = suggested_finalists($t);
$complete = group_stage_complete($tid);
?>
<div class="two-col">
  <div class="card">
    <h2>Standings</h2>
    <?php render_all_standings($t); ?>
  </div>
  <div class="card">
    <h2><?= $final || !$canManage ? 'Final' : 'Create the final' ?></h2>
    <?php if (!$canManage && !$final): ?>
      <p class="muted">The final hasn't been created yet. An organizer does that after the group stage.</p>
    <?php endif; ?>
    <?php if ($canManage): ?>
    <?php if (!$complete): ?>
      <div class="flash flash-warn">Not all group matches are finished yet. The suggested teams may still change.</div>
    <?php endif; ?>
    <?php if (count($groups) > 2): ?>
      <p class="muted small">With more than two groups, pick the finalists yourself.</p>
    <?php endif; ?>
    <form method="post" class="stack">
      <?= form_base('save_final', $tab) ?>
      <label>Team 1 <?= team_select($teams, $final ? (int) $final['team1_id'] : $sug1, 'team1_id') ?></label>
      <label>Team 2 <?= team_select($teams, $final ? (int) $final['team2_id'] : $sug2, 'team2_id') ?></label>
      <label>Court <?= court_select($courts, $final ? ($final['court_id'] !== null ? (int) $final['court_id'] : null) : (isset($courts[0]) ? (int) $courts[0]['id'] : null)) ?></label>
      <label>Start time <input type="datetime-local" name="start_time" value="<?= e(fmt_datetime_input($final['start_time'] ?? suggested_final_time($t))) ?>"></label>
      <div><button class="btn primary"><?= $final ? 'Update final' : 'Create final' ?></button></div>
      <?php if (!$final && $sug1): ?><p class="muted small">Teams are pre-filled from the current standings.</p><?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($final): ?>
      <h3>Final result</h3>
      <?php render_match_editor($final, $courts, $tab, $canManage); ?>
      <?php render_discord_notify($final, $tab); ?>
      <?php if ($champion = champion_name($final)): ?>
        <div class="champion-box">🏆 Champion: <strong><?= e($champion) ?></strong></div>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post" onsubmit="return confirm('Remove the final?');" class="mt">
        <?= form_base('delete_final', $tab) ?>
        <button class="linkbtn danger-text small">Remove final</button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
page_footer(['scripts' => [url('/assets/admin.js?v=1')]]);
