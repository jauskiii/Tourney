<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/*
 * Self-registration: a player picks their team and enters their name and Discord name.
 * Optional and per tournament (Teams & players → Self-registration). Off by default:
 * organizers can always add players by hand instead (e.g. from a Forms sheet).
 */

$tid = (int) ($_GET['id'] ?? 0);
$t = get_tournament($tid);
if (!$t || (!$t['is_public'] && !is_logged_in())) {
    not_found('Tournament not found');
}

$open = (int) ($t['reg_open'] ?? 0) === 1;
$max = (int) ($t['reg_max_players'] ?? 0);
$needCode = trim((string) ($t['reg_code'] ?? '')) !== '';
$hasMatches = (bool) one('SELECT id FROM matches WHERE tournament_id = ? LIMIT 1', [$tid]);
// New teams only before the schedule exists: a team added later would get no matches.
$allowNew = (int) ($t['reg_new_teams'] ?? 0) === 1 && !$hasMatches;
$useDiscord = (int) ($t['reg_discord'] ?? 1) === 1 && discord_enabled();

$error = '';
$form = ['name' => '', 'discord' => '', 'team' => '', 'new_team' => ''];

/** Players per team id. */
function reg_counts(int $tid): array
{
    $counts = [];
    foreach (all('SELECT p.team_id, COUNT(*) AS n FROM players p JOIN teams t ON t.id = p.team_id
                   WHERE t.tournament_id = ? GROUP BY p.team_id', [$tid]) as $row) {
        $counts[(int) $row['team_id']] = (int) $row['n'];
    }
    return $counts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $open) {
    csrf_check();
    $form = [
        'name'     => post_str('name', 100),
        'discord'  => ltrim(post_str('discord', 100), '@'),
        'team'     => (string) ($_POST['team'] ?? ''),
        'new_team' => post_str('new_team', 100),
    ];

    // Simple flood protection: a hidden field bots fill in, and at most 6 sign-ups per 10 minutes per browser.
    $recent = array_filter($_SESSION['reg_times'] ?? [], fn($ts) => $ts > time() - 600);
    if (post_str('website', 100) !== '') {
        redirect(url("/register.php?id={$tid}&done=1"));
    }

    if (count($recent) >= 6) {
        $error = 'Too many sign-ups from this browser. Wait a few minutes or ask an organizer.';
    } elseif ($needCode && !hash_equals(strtolower(trim((string) $t['reg_code'])), strtolower(post_str('code', 50)))) {
        $error = 'Wrong registration code. Ask an organizer for the code.';
    } elseif ($form['name'] === '') {
        $error = 'Write your name or nickname.';
    } elseif ($form['discord'] === '') {
        $error = 'Write your Discord username.';
    }

    // Which team
    $team = null;
    $isNewTeam = false;
    if ($error === '') {
        if ($form['team'] === 'new') {
            if (!$allowNew) {
                $error = 'New teams cannot be added any more. Pick one of the teams.';
            } elseif ($form['new_team'] === '') {
                $error = 'Write the name of your new team.';
            } else {
                $team = one('SELECT * FROM teams WHERE tournament_id = ? AND name = ?', [$tid, $form['new_team']]);
                $isNewTeam = $team === null;
            }
        } else {
            $team = one('SELECT * FROM teams WHERE tournament_id = ? AND id = ?', [$tid, (int) $form['team']]);
            if (!$team) {
                $error = 'Pick your team.';
            }
        }
    }

    // Discord name must exist on the server (checked through the bot when it is connected).
    if ($error === '' && $useDiscord) {
        $check = discord_resolve_member($form['discord']);
        if ($check['checked'] && !$check['found']) {
            $error = 'Discord name "' . $form['discord'] . '" was not found on our Discord server. Join the server first, '
                   . 'then write your username (Discord: Settings → My Account → Username).';
        } elseif ($check['found'] && $check['username'] !== '') {
            $form['discord'] = $check['username'];
        }
    }

    // Already registered?
    $existing = null;
    if ($error === '') {
        $existing = one(
            'SELECT p.*, t.name AS team_name FROM players p JOIN teams t ON t.id = p.team_id
              WHERE t.tournament_id = ? AND LOWER(p.discord) = LOWER(?)',
            [$tid, $form['discord']]
        );
        if ($existing && ($team === null || (int) $existing['team_id'] !== (int) $team['id'])) {
            $error = "This Discord name is already in team {$existing['team_name']}. Ask an organizer if you need to change teams.";
        }
    }

    if ($error === '') {
        if ($isNewTeam) {
            $groups = get_groups($tid);
            $groupId = count($groups) === 1 ? (int) $groups[0]['id'] : null;
            q('INSERT INTO teams (tournament_id, group_id, name) VALUES (?, ?, ?)', [$tid, $groupId, $form['new_team']]);
            $team = one('SELECT * FROM teams WHERE id = ?', [(int) db()->lastInsertId()]);
        }
        $teamId = (int) $team['id'];

        if ($existing) {
            // Same person again: just update the name.
            q('UPDATE players SET name = ? WHERE id = ?', [$form['name'], $existing['id']]);
        } else {
            // An organizer may have typed the name already without a Discord name: fill that row in.
            $placeholder = one(
                "SELECT id FROM players WHERE team_id = ? AND LOWER(name) = LOWER(?) AND (discord IS NULL OR discord = '')",
                [$teamId, $form['name']]
            );
            if ($placeholder) {
                q('UPDATE players SET discord = ? WHERE id = ?', [$form['discord'], $placeholder['id']]);
            } elseif ($max > 0 && (reg_counts($tid)[$teamId] ?? 0) >= $max) {
                $error = "Team {$team['name']} is already full ({$max} players). Ask an organizer.";
            } else {
                q('INSERT INTO players (team_id, name, discord, self_registered) VALUES (?, ?, ?, 1)',
                    [$teamId, $form['name'], $form['discord']]);
            }
        }
    }

    if ($error === '') {
        $_SESSION['reg_times'] = [...$recent, time()];
        $msg = "You are registered in {$team['name']} as {$form['name']}.";
        if ($useDiscord) {
            $r = discord_add_player($team['name'], $form['discord']);
            $msg .= $r['ok']
                ? ' You now have the team role on Discord.'
                : ' The organizers will give you the team role on Discord.';
        }
        flash($msg);
        redirect(url("/register.php?id={$tid}&done=1"));
    }
}

$teams = get_teams($tid);
$counts = reg_counts($tid);

page_header('Register · ' . $t['name']);
?>
<div class="card auth-card register-card">
  <p class="eyebrow"><?= e($t['name']) ?></p>
  <h1>Register for the tournament</h1>

  <?php if (isset($_GET['done'])): ?>
    <p><a class="btn ghost" href="<?= b() ?>/tournament.php?id=<?= $tid ?>">See the teams and schedule →</a></p>
  <?php endif; ?>

  <?php if (!$open): ?>
    <p class="muted">Registration is closed. Ask an organizer to add you to your team.</p>
  <?php else: ?>
    <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <p class="muted small">Pick your team and write your name and Discord username.
      <?= $useDiscord ? 'You get your team role and the team channel on Discord right away.' : 'The organizers give the Discord roles.' ?></p>

    <form method="post" class="stack" autocomplete="off">
      <?= csrf_field() ?>
      <label class="hp" aria-hidden="true">Website <input name="website" tabindex="-1" autocomplete="off"></label>
      <label>Team
        <select name="team" required id="reg-team">
          <option value="">— pick your team —</option>
          <?php foreach ($teams as $team):
              $n = $counts[(int) $team['id']] ?? 0;
              $full = $max > 0 && $n >= $max; ?>
            <option value="<?= (int) $team['id'] ?>" <?= $form['team'] === (string) $team['id'] ? 'selected' : '' ?>>
              <?= e($team['name']) ?><?= $max > 0 ? " ({$n}/{$max})" : '' ?><?= $full ? ' – full' : '' ?>
            </option>
          <?php endforeach; ?>
          <?php if ($allowNew): ?><option value="new" <?= $form['team'] === 'new' ? 'selected' : '' ?>>+ New team…</option><?php endif; ?>
        </select>
      </label>
      <?php if ($allowNew): ?>
        <label id="reg-new" <?= $form['team'] === 'new' ? '' : 'hidden' ?>>New team name
          <input name="new_team" maxlength="100" value="<?= e($form['new_team']) ?>">
        </label>
      <?php endif; ?>
      <label>Your name or nickname <input name="name" required maxlength="100" value="<?= e($form['name']) ?>"></label>
      <label>Discord username
        <input name="discord" required maxlength="100" value="<?= e($form['discord']) ?>" placeholder="e.g. pelaaja123" autocapitalize="off" spellcheck="false">
        <span class="muted small">Discord: Settings → My Account → Username. You must be on our Discord server.</span>
      </label>
      <?php if ($needCode): ?>
        <label>Registration code <input name="code" required maxlength="50" autocapitalize="off"></label>
      <?php endif; ?>
      <button class="btn primary">Register</button>
    </form>
    <?php if (!$teams && !$allowNew): ?><p class="muted small">No teams yet. Ask an organizer.</p><?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($allowNew): ?>
<script>
  (function () {
    var sel = document.getElementById('reg-team'), box = document.getElementById('reg-new');
    sel.addEventListener('change', function () { box.hidden = sel.value !== 'new'; });
  })();
</script>
<?php endif; ?>
<?php
page_footer();
