<?php
declare(strict_types=1);

/* =====================================================================
 * Discord integration – talks to the Liftup bot's REST API.
 *
 * Tourney stays the source of truth for tournaments; the bot owns Discord.
 * Tourney pushes: teams (+ players' Discord names) → bot team roles/channels,
 * and match events → announcements. The bot never calls back.
 *
 * Every call has a short timeout and never throws: a Discord problem must
 * never stop scores from being saved.
 * ===================================================================== */

const DISCORD_SETTINGS = [
    'discord_bot_url'         => '',   // e.g. http://10.0.0.5:8080
    'discord_bot_token'       => '',   // the bot's API_TOKEN
    'discord_auto_live'       => '1',  // announce to both teams when a match goes live
    'discord_auto_result'     => '1',  // post the result when a match is finished
    'discord_auto_champion'   => '1',  // announce the champion when the final is finished
    'discord_result_channel'  => '',   // Discord channel ID for results; empty = bot's default channel
    'discord_create_channels' => '1',  // team sync creates a text + voice channel per team
    'discord_attach_server'   => '1',  // attach the bot server whose name equals the court name
];

function discord_settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (DISCORD_SETTINGS as $key => $default) {
            $cache[$key] = setting_get($key) ?? $default;
        }
    }
    return $cache;
}

function discord_on(string $key): bool
{
    return (discord_settings()[$key] ?? '0') === '1';
}

/** True when the bot URL and token are set. */
function discord_enabled(): bool
{
    $s = discord_settings();
    return $s['discord_bot_url'] !== '' && $s['discord_bot_token'] !== '';
}

/**
 * One HTTP call to the bot. Returns ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => string].
 * Uses cURL when available, otherwise PHP streams (both common on webhotels).
 */
function discord_request(string $method, string $path, ?array $body = null, int $timeout = 4): array
{
    $s = discord_settings();
    if (!discord_enabled()) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Discord integration is not configured.'];
    }
    $url = rtrim($s['discord_bot_url'], '/') . '/api' . $path;
    $headers = [
        'Authorization: Bearer ' . $s['discord_bot_token'],
        'Accept: application/json',
        'User-Agent: Tourney-Discord/1.0',
    ];
    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }

    $status = 0;
    $raw = false;
    $error = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            $error = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $payload ?? '',
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $mm)) {
            $status = (int) $mm[1];
        }
        if ($raw === false) {
            $error = 'Could not connect to the bot.';
        }
    }

    if ($raw === false) {
        error_log("Discord bot {$method} {$path} failed: {$error}");
        return ['ok' => false, 'status' => $status, 'data' => null, 'error' => "Bot not reachable ({$error})"];
    }
    $data = json_decode((string) $raw, true);
    $ok = $status >= 200 && $status < 300;
    if (!$ok) {
        $msg = is_array($data) && isset($data['error']) ? (string) $data['error'] : "HTTP {$status}";
        error_log("Discord bot {$method} {$path}: {$msg}");
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $msg];
    }
    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
}

/** Checks the connection: returns the bot's status or an error. */
function discord_test(): array
{
    $r = discord_request('GET', '/status', null, 4);
    if (!$r['ok']) {
        return $r;
    }
    $d = $r['data'];
    if (empty($d['bot']['online'])) {
        $r['ok'] = false;
        $r['error'] = 'The bot answers, but is not connected to Discord: ' . ($d['bot']['error'] ?? 'unknown');
    }
    return $r;
}

/* ---------- Teams ---------- */

/** Teams of a tournament with the Discord names of their players. */
function discord_team_payload(int $tid): array
{
    $players = get_players_by_team($tid);
    $teams = [];
    foreach (get_teams($tid) as $team) {
        $members = [];
        foreach ($players[(int) $team['id']] ?? [] as $p) {
            $d = trim((string) ($p['discord'] ?? ''));
            if ($d !== '') {
                $members[] = $d;
            }
        }
        $teams[] = ['name' => $team['name'], 'members' => $members];
    }
    return $teams;
}

/**
 * Creates/updates the bot teams for this tournament and gives the roles.
 * Returns ['ok', 'message', 'missing' => [names the bot couldn't find]].
 */
function discord_sync_teams(int $tid): array
{
    $teams = discord_team_payload($tid);
    if (!$teams) {
        return ['ok' => false, 'message' => 'This tournament has no teams yet.', 'missing' => []];
    }
    $r = discord_request('POST', '/teams/import', [
        'teams'          => $teams,
        'replace'        => true,
        'createChannels' => discord_on('discord_create_channels'),
    ], 30); // creating roles and channels takes a few seconds
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Discord sync failed: ' . $r['error'], 'missing' => []];
    }
    $added = 0;
    $missing = [];
    foreach ((array) $r['data'] as $row) {
        $added += count($row['added'] ?? []);
        foreach ($row['failed'] ?? [] as $f) {
            $missing[] = ($row['team'] ?? '?') . ': ' . ($f['user'] ?? '?');
        }
    }
    $msg = sprintf('Discord: %d team(s) synced, %d player(s) got their team role.', count($teams), $added);
    if ($missing) {
        $msg .= ' Not found on the Discord server: ' . implode(', ', $missing) . '.';
    }
    return ['ok' => true, 'message' => $msg, 'missing' => $missing];
}

/** Removes this tournament's teams (roles + channels) from Discord. */
function discord_remove_teams(int $tid): array
{
    $removed = 0;
    $errors = [];
    foreach (get_teams($tid) as $team) {
        $r = discord_request('DELETE', '/teams/' . rawurlencode($team['name']), null, 15);
        if ($r['ok']) {
            $removed++;
        } elseif ($r['status'] !== 404) {
            $errors[] = $team['name'] . ' (' . $r['error'] . ')';
        }
    }
    $msg = "Discord: removed {$removed} team(s) with their roles and channels.";
    if ($errors) {
        $msg .= ' Failed: ' . implode(', ', $errors);
    }
    return ['ok' => !$errors, 'message' => $msg];
}

/* ---------- Announcements ---------- */

function discord_announce(array $payload): array
{
    $r = discord_request('POST', '/announcements', $payload, 8);
    return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'Sent to Discord.' : 'Discord: ' . $r['error']];
}

/** "Lohko A · kierros 2" / "FINAALI" + court + time, for message bodies. */
function discord_match_line(array $m): string
{
    $parts = [];
    $parts[] = $m['stage'] === 'final' ? '**FINAALI**' : trim(($m['group_name'] ?? '') . ' · kierros ' . $m['round'], ' ·');
    if (!empty($m['court_name'])) {
        $parts[] = 'Kenttä: **' . $m['court_name'] . '**';
    }
    if (!empty($m['start_time'])) {
        $parts[] = 'klo ' . fmt_time($m['start_time']);
    }
    return implode(' · ', $parts);
}

/**
 * Teams of a match ($to = 'both', 'team1' or 'team2'), on their own team channels,
 * each pinging its own role.
 */
function discord_to_teams(array $tournament, array $m, string $title, string $body, string $color, string $to = 'both'): array
{
    $teams = match ($to) {
        'team1' => [$m['team1_name']],
        'team2' => [$m['team2_name']],
        default => [$m['team1_name'], $m['team2_name']],
    };
    $payload = [
        'teams'   => $teams,
        'mention' => 'team',
        'title'   => $title,
        'body'    => $body,
        'color'   => $color,
    ];
    if (discord_on('discord_attach_server') && !empty($m['court_name'])) {
        $payload['serverNames'] = [$m['court_name']];
    }
    return discord_announce($payload);
}

/**
 * Free-text message about a match (e.g. a map ban link) to both teams, or only one:
 * $to = 'both', 'team1' or 'team2'.
 */
function discord_notify_match(array $tournament, array $m, string $message, string $to = 'both'): array
{
    $body = trim($message) . "\n\n" . discord_match_line($m) . "\n*" . $tournament['name'] . '*';
    // A message for one team says who it is for, so it can't be mistaken for a shared one.
    $title = match ($to) {
        'team1' => '📣 ' . $m['team1_name'] . ' (vs ' . $m['team2_name'] . ')',
        'team2' => '📣 ' . $m['team2_name'] . ' (vs ' . $m['team1_name'] . ')',
        default => '📣 ' . $m['team1_name'] . ' vs ' . $m['team2_name'],
    };
    return discord_to_teams($tournament, $m, $title, $body, '#5865f2', $to);
}

/**
 * Called after a match was saved. Announces only on a status CHANGE,
 * so live score autosaves never spam Discord.
 * Returns a short message for the admin, or '' when nothing was sent.
 */
function discord_on_match_change(array $tournament, array $before, array $after): string
{
    if (!discord_enabled() || $before['status'] === $after['status']) {
        return '';
    }
    $results = [];

    if ($after['status'] === 'live' && discord_on('discord_auto_live')) {
        $results[] = discord_to_teams(
            $tournament, $after,
            '⚔️ Ottelu alkaa: ' . $after['team1_name'] . ' vs ' . $after['team2_name'],
            discord_match_line($after) . "\n*" . $tournament['name'] . '*',
            '#e74c3c'
        );
    }

    if ($after['status'] === 'finished') {
        $isFinal = $after['stage'] === 'final';
        $champion = $isFinal ? champion_name($after) : null;
        $channel = discord_settings()['discord_result_channel'];

        if (discord_on('discord_auto_result')) {
            $score = $after['team1_name'] . ' **' . (int) $after['score1'] . '–' . (int) $after['score2'] . '** ' . $after['team2_name'];
            $payload = [
                'title' => ($isFinal ? '🏁 Finaalin tulos' : '✅ Tulos') . ': ' . $after['team1_name'] . ' vs ' . $after['team2_name'],
                'body'  => $score . "\n" . discord_match_line($after) . "\n*" . $tournament['name'] . '*',
                'color' => '#2ecc71',
            ];
            if ($channel !== '') {
                $payload['channelId'] = $channel;
            }
            $results[] = discord_announce($payload);
        }

        if ($champion !== null && discord_on('discord_auto_champion')) {
            $payload = [
                'title'   => '🏆 ' . $champion . ' voitti turnauksen!',
                'body'    => '**' . $tournament['name'] . "** on ratkennut. Onnea voittajille! 🎉",
                'color'   => '#f1c40f',
                'mention' => 'here',
            ];
            if ($channel !== '') {
                $payload['channelId'] = $channel;
            }
            $results[] = discord_announce($payload);
        }
    }

    if (!$results) {
        return '';
    }
    $failed = array_filter($results, fn($r) => !$r['ok']);
    return $failed ? reset($failed)['message'] : 'Announced on Discord.';
}
