<?php
declare(strict_types=1);

/**
 * Handles every POST action on the admin tournament page.
 * Always redirects back (Post/Redirect/Get) so refreshing never resubmits.
 */
function handle_admin_action(array $tournament): never
{
    csrf_check();
    $tid = (int) $tournament['id'];
    $action = $_POST['action'] ?? '';
    $tab = preg_replace('/[^a-z]/', '', (string) ($_POST['tab'] ?? 'overview'));
    $back = url("/admin/tournament.php?id={$tid}&tab={$tab}");

    // Scorekeepers may only enter scores; everything else needs tournaments_manage.
    $scoreActions = ['save_match', 'reset_match', 'discord_notify'];
    require_permission(in_array($action, $scoreActions, true) ? 'scores_enter' : 'tournaments_manage');

    try {
        switch ($action) {

            /* ---------- Settings ---------- */

            case 'update_settings':
                $name = post_str('name', 150);
                $start = post_datetime('start_time');
                if ($name === '' || !$start) {
                    flash('Name and start time are required.', 'error');
                    break;
                }
                q('UPDATE tournaments SET name = ?, start_time = ?, match_minutes = ?, break_minutes = ?,
                          points_win = ?, points_draw = ?, points_loss = ?, is_public = ?
                    WHERE id = ?', [
                    $name, $start,
                    max(1, post_int('match_minutes', 20)),
                    max(0, post_int('break_minutes', 5)),
                    post_int('points_win', 3), post_int('points_draw', 1), post_int('points_loss', 0),
                    isset($_POST['is_public']) ? 1 : 0,
                    $tid,
                ]);
                flash('Settings saved. If you changed times, regenerate the schedule or edit match times.');
                break;

            case 'delete_tournament':
                if (post_str('confirm_name', 150) !== $tournament['name']) {
                    flash('Type the tournament name exactly to delete it.', 'error');
                    break;
                }
                q('DELETE FROM tournaments WHERE id = ?', [$tid]);
                flash('Tournament deleted.');
                redirect(url('/admin/'));

            /* ---------- Teams ---------- */

            case 'add_teams':
                $groupId = owned_id('tournament_groups', post_int('group_id'), $tid);
                $lines = preg_split('/\r\n|\r|\n/', (string) ($_POST['names'] ?? ''));
                $added = 0;
                $skipped = [];
                foreach ($lines as $line) {
                    $name = str_cut(trim($line), 100);
                    if ($name === '') {
                        continue;
                    }
                    if (one('SELECT id FROM teams WHERE tournament_id = ? AND name = ?', [$tid, $name])) {
                        $skipped[] = $name;
                        continue;
                    }
                    q('INSERT INTO teams (tournament_id, group_id, name) VALUES (?, ?, ?)', [$tid, $groupId, $name]);
                    $added++;
                }
                flash("Added {$added} team(s)." . ($skipped ? ' Already existed: ' . implode(', ', $skipped) : ''));
                break;

            case 'update_team':
                $teamId = require_owned('teams', post_int('team_id'), $tid);
                $name = post_str('name', 100);
                if ($name === '') {
                    flash('Team name cannot be empty.', 'error');
                    break;
                }
                $groupId = owned_id('tournament_groups', post_int('group_id'), $tid);
                q('UPDATE teams SET name = ?, group_id = ? WHERE id = ?', [$name, $groupId, $teamId]);
                flash('Team saved.');
                break;

            case 'delete_team':
                $teamId = require_owned('teams', post_int('team_id'), $tid);
                q('DELETE FROM teams WHERE id = ?', [$teamId]);
                flash('Team deleted (with its players and matches).');
                break;

            case 'add_player':
                $teamId = require_owned('teams', post_int('team_id'), $tid);
                $name = post_str('name', 100);
                if ($name === '') {
                    flash('Player name cannot be empty.', 'error');
                    break;
                }
                $number = post_str('number', 10);
                $discord = post_str('discord', 100);
                q('INSERT INTO players (team_id, name, number, discord) VALUES (?, ?, ?, ?)',
                    [$teamId, $name, $number !== '' ? $number : null, $discord !== '' ? $discord : null]);
                flash("Player {$name} added.");
                break;

            case 'set_player_discord':
                $player = one(
                    'SELECT p.id, p.name FROM players p JOIN teams t ON t.id = p.team_id WHERE p.id = ? AND t.tournament_id = ?',
                    [post_int('player_id', 0), $tid]
                );
                if ($player) {
                    $discord = post_str('discord', 100);
                    q('UPDATE players SET discord = ? WHERE id = ?', [$discord !== '' ? $discord : null, $player['id']]);
                    flash($discord !== '' ? "Discord name for {$player['name']} saved." : "Discord name for {$player['name']} removed.");
                }
                break;

            case 'delete_player':
                $player = one(
                    'SELECT p.id FROM players p JOIN teams t ON t.id = p.team_id WHERE p.id = ? AND t.tournament_id = ?',
                    [post_int('player_id', 0), $tid]
                );
                if ($player) {
                    q('DELETE FROM players WHERE id = ?', [$player['id']]);
                    flash('Player removed.');
                }
                break;

            /* ---------- Groups & courts ---------- */

            case 'add_group':
                $name = post_str('name', 50);
                if ($name === '') {
                    $count = (int) one('SELECT COUNT(*) AS n FROM tournament_groups WHERE tournament_id = ?', [$tid])['n'];
                    $name = 'Group ' . chr(ord('A') + min($count, 25));
                }
                $pos = (int) one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM tournament_groups WHERE tournament_id = ?', [$tid])['p'];
                q('INSERT INTO tournament_groups (tournament_id, name, position) VALUES (?, ?, ?)', [$tid, $name, $pos]);
                flash("{$name} added.");
                break;

            case 'rename_group':
                $groupId = require_owned('tournament_groups', post_int('group_id'), $tid);
                $name = post_str('name', 50);
                if ($name !== '') {
                    q('UPDATE tournament_groups SET name = ? WHERE id = ?', [$name, $groupId]);
                    flash('Group renamed.');
                }
                break;

            case 'delete_group':
                $groupId = require_owned('tournament_groups', post_int('group_id'), $tid);
                q('DELETE FROM tournament_groups WHERE id = ?', [$groupId]);
                flash('Group deleted. Its teams are now without a group.');
                break;

            case 'distribute_teams':
                $groups = get_groups($tid);
                if (!$groups) {
                    flash('Create at least one group first.', 'error');
                    break;
                }
                $teams = array_column(all('SELECT id FROM teams WHERE tournament_id = ?', [$tid]), 'id');
                shuffle($teams);
                foreach ($teams as $i => $teamId) {
                    q('UPDATE teams SET group_id = ? WHERE id = ?', [$groups[$i % count($groups)]['id'], $teamId]);
                }
                flash('Teams shuffled evenly into ' . count($groups) . ' group(s). Remember to regenerate the schedule.');
                break;

            case 'add_court':
                $name = post_str('name', 50);
                if ($name === '') {
                    $count = (int) one('SELECT COUNT(*) AS n FROM courts WHERE tournament_id = ?', [$tid])['n'];
                    $name = 'Court ' . ($count + 1);
                }
                $pos = (int) one('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM courts WHERE tournament_id = ?', [$tid])['p'];
                q('INSERT INTO courts (tournament_id, name, position) VALUES (?, ?, ?)', [$tid, $name, $pos]);
                flash("{$name} added.");
                break;

            case 'rename_court':
                $courtId = require_owned('courts', post_int('court_id'), $tid);
                $name = post_str('name', 50);
                if ($name !== '') {
                    q('UPDATE courts SET name = ? WHERE id = ?', [$name, $courtId]);
                    flash('Court renamed.');
                }
                break;

            case 'delete_court':
                $courtId = require_owned('courts', post_int('court_id'), $tid);
                q('DELETE FROM courts WHERE id = ?', [$courtId]);
                flash('Court deleted. Matches on it have no court now.');
                break;

            /* ---------- Schedule & results ---------- */

            case 'generate_schedule':
                $unassigned = (int) one('SELECT COUNT(*) AS n FROM teams WHERE tournament_id = ? AND group_id IS NULL', [$tid])['n'];
                $count = generate_schedule($tid);
                $msg = "Schedule created: {$count} matches.";
                if ($unassigned) {
                    $msg .= " Note: {$unassigned} team(s) have no group and got no matches.";
                }
                flash($msg, $count ? 'ok' : 'error');
                break;

            case 'save_match':
                $result = save_match($tid);
                if (is_ajax()) {
                    json_response($result);
                }
                flash($result['message'], $result['ok'] ? 'ok' : 'error');
                if (!empty($result['discord'])) {
                    // "Announced on Discord." = ok; anything else is a Discord problem (the score is saved anyway).
                    $fine = $result['discord'] === 'Announced on Discord.';
                    flash($fine ? $result['discord'] : $result['discord'] . ' The match itself was saved.', $fine ? 'ok' : 'warn');
                }
                $back .= '#m' . (int) post_int('match_id', 0);
                break;

            case 'reset_match':
                $matchId = require_owned('matches', post_int('match_id'), $tid);
                q("UPDATE matches SET score1 = NULL, score2 = NULL, winner_team_id = NULL, status = 'scheduled' WHERE id = ?", [$matchId]);
                flash('Match reset.');
                $back .= '#m' . $matchId;
                break;

            /* ---------- Final ---------- */

            case 'save_final':
                $t1 = require_owned('teams', post_int('team1_id'), $tid);
                $t2 = require_owned('teams', post_int('team2_id'), $tid);
                if ($t1 === $t2) {
                    flash('Pick two different teams for the final.', 'error');
                    break;
                }
                $courtId = owned_id('courts', post_int('court_id'), $tid);
                $time = post_datetime('start_time');
                $existing = get_final($tid);
                if ($existing) {
                    $changed = (int) $existing['team1_id'] !== $t1 || (int) $existing['team2_id'] !== $t2;
                    q('UPDATE matches SET team1_id = ?, team2_id = ?, court_id = ?, start_time = ?' .
                      ($changed ? ", score1 = NULL, score2 = NULL, winner_team_id = NULL, status = 'scheduled'" : '') .
                      ' WHERE id = ?', [$t1, $t2, $courtId, $time, $existing['id']]);
                    flash('Final updated.');
                } else {
                    q("INSERT INTO matches (tournament_id, stage, round, team1_id, team2_id, court_id, start_time)
                       VALUES (?, 'final', 99, ?, ?, ?, ?)", [$tid, $t1, $t2, $courtId, $time]);
                    flash('Final created! Enter its result on this tab or the Schedule tab.');
                }
                break;

            case 'delete_final':
                q("DELETE FROM matches WHERE tournament_id = ? AND stage = 'final'", [$tid]);
                flash('Final removed.');
                break;

            /* ---------- Discord (Liftup bot) ---------- */

            case 'discord_sync':
                $r = discord_sync_teams($tid);
                flash($r['message'], $r['ok'] ? ($r['missing'] ? 'warn' : 'ok') : 'error');
                break;

            case 'discord_remove':
                $r = discord_remove_teams($tid);
                flash($r['message'], $r['ok'] ? 'ok' : 'error');
                break;

            case 'discord_notify':
                $matchId = require_owned('matches', post_int('match_id'), $tid);
                $message = post_str('message', 1500);
                if ($message === '') {
                    flash('Write a message first (e.g. the map ban link).', 'error');
                } else {
                    $r = discord_notify_match($tournament, get_match($tid, $matchId), $message);
                    flash($r['ok'] ? 'Message sent to both teams on Discord.' : $r['message'], $r['ok'] ? 'ok' : 'error');
                }
                $back .= '#m' . $matchId;
                break;

            default:
                flash('Unknown action.', 'error');
        }
    } catch (PDOException $e) {
        error_log('Admin action failed: ' . $e->getMessage());
        $msg = str_contains($e->getMessage(), 'Duplicate')
            ? 'That name is already used in this tournament.'
            : 'Database error: ' . $e->getMessage();
        flash($msg, 'error');
    }

    redirect($back);
}

/**
 * Saves score, status, court and time for one match.
 * Returns ['ok' => bool, 'message' => string, ...current match state].
 */
function save_match(int $tid): array
{
    $matchId = require_owned('matches', post_int('match_id'), $tid);
    $m = one('SELECT * FROM matches WHERE id = ?', [$matchId]);

    $s1 = post_int('score1');
    $s2 = post_int('score2');
    if (($s1 !== null && $s1 < 0) || ($s2 !== null && $s2 < 0)) {
        return ['ok' => false, 'message' => 'Scores cannot be negative.'];
    }

    $status = (string) ($_POST['status'] ?? 'keep');
    if (!in_array($status, ['scheduled', 'live', 'finished'], true)) {
        $status = $m['status'];
    }
    if ($status === 'live') {
        $s1 ??= 0;
        $s2 ??= 0;
    }

    $winner = null;
    if ($status === 'finished') {
        if ($s1 === null || $s2 === null) {
            return ['ok' => false, 'message' => 'Enter both scores before marking the match finished.'];
        }
        if ($s1 > $s2) {
            $winner = (int) $m['team1_id'];
        } elseif ($s2 > $s1) {
            $winner = (int) $m['team2_id'];
        } elseif ($m['stage'] === 'final') {
            $picked = post_int('winner_team_id');
            if (!in_array($picked, [(int) $m['team1_id'], (int) $m['team2_id']], true)) {
                return ['ok' => false, 'message' => 'The final is tied – choose the winner (e.g. after penalties / overtime).'];
            }
            $winner = $picked;
        }
    }

    // Only organizers/admins may move matches; scorekeepers change scores only.
    $mayMove = can('tournaments_manage');
    $courtId = $mayMove && array_key_exists('court_id', $_POST)
        ? owned_id('courts', post_int('court_id'), $tid)
        : ($m['court_id'] !== null ? (int) $m['court_id'] : null);
    $time = $mayMove && array_key_exists('start_time', $_POST) ? post_datetime('start_time') : $m['start_time'];

    q('UPDATE matches SET score1 = ?, score2 = ?, status = ?, winner_team_id = ?, court_id = ?, start_time = ? WHERE id = ?',
        [$s1, $s2, $status, $winner, $courtId, $time, $matchId]);

    $label = match ($status) {
        'live' => 'Match is now live.',
        'finished' => 'Result saved.',
        default => 'Match saved.',
    };

    // Discord: only status changes are announced (never the live autosaves).
    $discordNote = '';
    if ($status !== $m['status']) {
        $discordNote = discord_on_match_change(get_tournament($tid), $m, get_match($tid, $matchId));
    }
    return ['ok' => true, 'message' => $label, 'status' => $status, 'score1' => $s1, 'score2' => $s2,
            'discord' => $discordNote];
}

/**
 * Returns $id if the row belongs to the tournament, otherwise null.
 * Table names are hard-coded by callers, never user input.
 */
function owned_id(string $table, ?int $id, int $tid): ?int
{
    if (!$id) {
        return null;
    }
    return one("SELECT id FROM {$table} WHERE id = ? AND tournament_id = ?", [$id, $tid]) ? $id : null;
}

function require_owned(string $table, ?int $id, int $tid): int
{
    $owned = owned_id($table, $id, $tid);
    if ($owned === null) {
        http_response_code(400);
        exit('Item not found in this tournament. Go back and refresh.');
    }
    return $owned;
}
