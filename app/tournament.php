<?php
declare(strict_types=1);

/* =====================================================================
 * Data access
 * ===================================================================== */

function get_tournament(int $id): ?array
{
    return one('SELECT * FROM tournaments WHERE id = ?', [$id]);
}

function get_groups(int $tid): array
{
    return all('SELECT * FROM tournament_groups WHERE tournament_id = ? ORDER BY position, id', [$tid]);
}

function get_courts(int $tid): array
{
    return all('SELECT * FROM courts WHERE tournament_id = ? ORDER BY position, id', [$tid]);
}

function get_teams(int $tid): array
{
    return all(
        'SELECT t.*, g.name AS group_name
           FROM teams t
           LEFT JOIN tournament_groups g ON g.id = t.group_id
          WHERE t.tournament_id = ?
          ORDER BY g.position IS NULL, g.position, g.id, t.name',
        [$tid]
    );
}

/** team_id => list of players */
function get_players_by_team(int $tid): array
{
    $rows = all(
        'SELECT p.* FROM players p
           JOIN teams t ON t.id = p.team_id
          WHERE t.tournament_id = ?
          ORDER BY p.number IS NULL, CAST(p.number AS UNSIGNED), p.number, p.name',
        [$tid]
    );
    $byTeam = [];
    foreach ($rows as $row) {
        $byTeam[(int) $row['team_id']][] = $row;
    }
    return $byTeam;
}

/**
 * All matches with team, court and group names.
 * $stage: null = all, 'group' or 'final'.
 */
function get_matches(int $tid, ?string $stage = null): array
{
    $sql = 'SELECT m.*,
                   t1.name AS team1_name, t2.name AS team2_name,
                   c.name AS court_name, c.position AS court_position,
                   g.name AS group_name
              FROM matches m
              JOIN teams t1 ON t1.id = m.team1_id
              JOIN teams t2 ON t2.id = m.team2_id
              LEFT JOIN courts c ON c.id = m.court_id
              LEFT JOIN tournament_groups g ON g.id = m.group_id
             WHERE m.tournament_id = ?';
    $params = [$tid];
    if ($stage !== null) {
        $sql .= ' AND m.stage = ?';
        $params[] = $stage;
    }
    $sql .= ' ORDER BY m.start_time IS NULL, m.start_time, c.position, c.id, m.id';
    return all($sql, $params);
}

/** One match with team, court and group names (null if not in this tournament). */
function get_match(int $tid, int $matchId): ?array
{
    return one(
        'SELECT m.*,
                t1.name AS team1_name, t2.name AS team2_name,
                c.name AS court_name, g.name AS group_name
           FROM matches m
           JOIN teams t1 ON t1.id = m.team1_id
           JOIN teams t2 ON t2.id = m.team2_id
           LEFT JOIN courts c ON c.id = m.court_id
           LEFT JOIN tournament_groups g ON g.id = m.group_id
          WHERE m.tournament_id = ? AND m.id = ?',
        [$tid, $matchId]
    );
}

function get_final(int $tid): ?array
{
    $finals = get_matches($tid, 'final');
    return $finals[0] ?? null;
}

/* =====================================================================
 * Round robin scheduling
 * ===================================================================== */

/**
 * Circle method: every team meets every other team once.
 * Returns a list of rounds, each round a list of [teamA, teamB] pairs.
 * With an odd number of teams one team rests each round.
 */
function round_robin_rounds(array $teamIds): array
{
    $teamIds = array_values($teamIds);
    if (count($teamIds) < 2) {
        return [];
    }
    if (count($teamIds) % 2 === 1) {
        $teamIds[] = null; // bye
    }
    $n = count($teamIds);
    $rounds = [];
    for ($r = 0; $r < $n - 1; $r++) {
        $pairs = [];
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $teamIds[$i];
            $b = $teamIds[$n - 1 - $i];
            if ($a === null || $b === null) {
                continue;
            }
            // Alternate who is listed first so no team is always "home".
            $pairs[] = ($r % 2 === 0) ? [$a, $b] : [$b, $a];
        }
        $rounds[] = $pairs;
        // Keep the first team fixed, rotate everyone else one step.
        $fixed = array_shift($teamIds);
        array_unshift($teamIds, array_pop($teamIds));
        array_unshift($teamIds, $fixed);
    }
    return $rounds;
}

/**
 * Deletes all group matches and builds a fresh round robin schedule
 * for every group, with courts and start times filled in.
 * Returns the number of matches created.
 */
function generate_schedule(int $tid): int
{
    $tournament = get_tournament($tid);
    $courts = get_courts($tid);
    if (!$courts) {
        q('INSERT INTO courts (tournament_id, name, position) VALUES (?, ?, 1)', [$tid, 'Court 1']);
        $courts = get_courts($tid);
    }

    // Build a queue of matches: round 1 of every group, then round 2, ...
    $roundsByGroup = [];
    foreach (get_groups($tid) as $group) {
        $teamIds = array_map(
            'intval',
            array_column(all('SELECT id FROM teams WHERE group_id = ? ORDER BY id', [$group['id']]), 'id')
        );
        $roundsByGroup[(int) $group['id']] = round_robin_rounds($teamIds);
    }
    $maxRounds = $roundsByGroup ? max(array_map('count', $roundsByGroup)) : 0;
    $queue = [];
    for ($r = 0; $r < $maxRounds; $r++) {
        foreach ($roundsByGroup as $groupId => $rounds) {
            foreach ($rounds[$r] ?? [] as [$a, $b]) {
                $queue[] = ['group_id' => $groupId, 'round' => $r + 1, 'a' => $a, 'b' => $b];
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q("DELETE FROM matches WHERE tournament_id = ? AND stage = 'group'", [$tid]);

        $slotMinutes = (int) $tournament['match_minutes'] + (int) $tournament['break_minutes'];
        $start = new DateTimeImmutable($tournament['start_time']);
        $insert = $pdo->prepare(
            "INSERT INTO matches (tournament_id, stage, group_id, round, team1_id, team2_id, court_id, start_time)
             VALUES (?, 'group', ?, ?, ?, ?, ?, ?)"
        );

        // Fill time slots: up to one match per court, and never the same
        // team twice in the same slot.
        $slot = 0;
        while ($queue) {
            $busy = [];
            $picked = [];
            $rest = [];
            foreach ($queue as $m) {
                if (count($picked) < count($courts) && !isset($busy[$m['a']]) && !isset($busy[$m['b']])) {
                    $picked[] = $m;
                    $busy[$m['a']] = $busy[$m['b']] = true;
                } else {
                    $rest[] = $m;
                }
            }
            $time = $start->modify('+' . ($slot * $slotMinutes) . ' minutes')->format('Y-m-d H:i:00');
            foreach ($picked as $i => $m) {
                $insert->execute([$tid, $m['group_id'], $m['round'], $m['a'], $m['b'], $courts[$i]['id'], $time]);
            }
            $queue = $rest;
            $slot++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return (int) one("SELECT COUNT(*) AS n FROM matches WHERE tournament_id = ? AND stage = 'group'", [$tid])['n'];
}

/* =====================================================================
 * Standings
 * ===================================================================== */

/**
 * Standings for one group, sorted by: points, goal difference,
 * goals scored, then name.
 */
function group_standings(array $tournament, int $groupId): array
{
    $rows = [];
    foreach (all('SELECT id, name FROM teams WHERE group_id = ? ORDER BY name', [$groupId]) as $team) {
        $rows[(int) $team['id']] = [
            'team_id' => (int) $team['id'], 'name' => $team['name'],
            'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
            'for' => 0, 'against' => 0, 'diff' => 0, 'points' => 0,
        ];
    }

    $matches = all(
        "SELECT team1_id, team2_id, score1, score2 FROM matches
          WHERE group_id = ? AND stage = 'group' AND status = 'finished'
            AND score1 IS NOT NULL AND score2 IS NOT NULL",
        [$groupId]
    );
    $pw = (int) $tournament['points_win'];
    $pd = (int) $tournament['points_draw'];
    $pl = (int) $tournament['points_loss'];

    foreach ($matches as $m) {
        $sides = [
            [(int) $m['team1_id'], (int) $m['score1'], (int) $m['score2']],
            [(int) $m['team2_id'], (int) $m['score2'], (int) $m['score1']],
        ];
        foreach ($sides as [$teamId, $scored, $conceded]) {
            if (!isset($rows[$teamId])) {
                continue; // team moved to another group after playing
            }
            $row = &$rows[$teamId];
            $row['played']++;
            $row['for'] += $scored;
            $row['against'] += $conceded;
            if ($scored > $conceded) {
                $row['won']++;
                $row['points'] += $pw;
            } elseif ($scored === $conceded) {
                $row['drawn']++;
                $row['points'] += $pd;
            } else {
                $row['lost']++;
                $row['points'] += $pl;
            }
            unset($row);
        }
    }

    foreach ($rows as &$row) {
        $row['diff'] = $row['for'] - $row['against'];
    }
    unset($row);

    $rows = array_values($rows);
    usort($rows, fn($a, $b) =>
        [$b['points'], $b['diff'], $b['for'], $a['name']] <=> [$a['points'], $a['diff'], $a['for'], $b['name']]);
    return $rows;
}

/** How many teams from each group go to the final (for highlighting). */
function qualifiers_per_group(int $groupCount): int
{
    return match ($groupCount) {
        1 => 2,
        2 => 1,
        default => 0,
    };
}

/** Are all group matches finished? */
function group_stage_complete(int $tid): bool
{
    $row = one(
        "SELECT COUNT(*) AS total, SUM(status = 'finished') AS done
           FROM matches WHERE tournament_id = ? AND stage = 'group'",
        [$tid]
    );
    return (int) $row['total'] > 0 && (int) $row['total'] === (int) $row['done'];
}

/**
 * Suggested finalists:
 *  - one group:  1st vs 2nd
 *  - two groups: winner of group A vs winner of group B
 * Returns [team1_id|null, team2_id|null].
 */
function suggested_finalists(array $tournament): array
{
    $groups = get_groups((int) $tournament['id']);
    if (count($groups) === 1) {
        $s = group_standings($tournament, (int) $groups[0]['id']);
        return [$s[0]['team_id'] ?? null, $s[1]['team_id'] ?? null];
    }
    if (count($groups) === 2) {
        $a = group_standings($tournament, (int) $groups[0]['id']);
        $b = group_standings($tournament, (int) $groups[1]['id']);
        return [$a[0]['team_id'] ?? null, $b[0]['team_id'] ?? null];
    }
    return [null, null];
}

/** Default start time for the final: one slot after the last group match. */
function suggested_final_time(array $tournament): string
{
    $last = one(
        "SELECT MAX(start_time) AS t FROM matches WHERE tournament_id = ? AND stage = 'group'",
        [$tournament['id']]
    );
    $slot = (int) $tournament['match_minutes'] + (int) $tournament['break_minutes'];
    $base = $last['t'] ?? null;
    if (!$base) {
        return $tournament['start_time'];
    }
    return (new DateTimeImmutable($base))->modify("+{$slot} minutes")->format('Y-m-d H:i:00');
}

/** Winner team id of a finished match, or null (draw / not finished). */
function match_winner(array $m): ?int
{
    if ($m['status'] !== 'finished') {
        return null;
    }
    if ($m['winner_team_id']) {
        return (int) $m['winner_team_id'];
    }
    if ($m['score1'] === null || $m['score2'] === null || (int) $m['score1'] === (int) $m['score2']) {
        return null;
    }
    return (int) $m['score1'] > (int) $m['score2'] ? (int) $m['team1_id'] : (int) $m['team2_id'];
}

/** Name of the tournament champion, or null if the final isn't finished. */
function champion_name(?array $final): ?string
{
    if (!$final) {
        return null;
    }
    $winner = match_winner($final);
    if ($winner === null) {
        return null;
    }
    return $winner === (int) $final['team1_id'] ? $final['team1_name'] : $final['team2_name'];
}

function match_end_time(array $tournament, array $m): ?string
{
    if (!$m['start_time']) {
        return null;
    }
    return date('Y-m-d H:i:s', strtotime($m['start_time']) + 60 * (int) $tournament['match_minutes']);
}
