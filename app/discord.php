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
    'discord_mapban_on_live'  => '1',  // the Live message to each team includes its own map ban link
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

/**
 * Text channels of the Discord server, from the bot: [['id' => …, 'name' => …, 'parent' => …], …].
 * Null when the bot can't be asked. Cached for the request.
 */
function discord_channels(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $r = discord_request('GET', '/guild', null, 3);
    $cache = ($r['ok'] && isset($r['data']['channels']) && is_array($r['data']['channels'])) ? $r['data']['channels'] : null;
    return $cache;
}

/**
 * A channel picker: a <select> of the server's text channels when the bot answers,
 * otherwise a plain input for the channel ID. $emptyLabel = text of the "no channel" choice.
 */
function discord_channel_select(string $name, ?string $selected, string $emptyLabel): string
{
    $selected = (string) $selected;
    $channels = discord_channels();
    if ($channels === null) {
        return '<input name="' . e($name) . '" value="' . e($selected) . '" placeholder="Channel ID, e.g. 123456789012345678" inputmode="numeric" maxlength="20">';
    }
    $html = '<select name="' . e($name) . '"><option value="">' . e($emptyLabel) . '</option>';
    $found = $selected === '';
    foreach ($channels as $c) {
        $id = (string) ($c['id'] ?? '');
        $sel = $id === $selected ? ' selected' : '';
        $found = $found || $sel !== '';
        $label = '#' . ($c['name'] ?? $id) . (!empty($c['parent']) ? ' (' . $c['parent'] . ')' : '');
        $html .= '<option value="' . e($id) . '"' . $sel . '>' . e($label) . '</option>';
    }
    if (!$found) {
        $html .= '<option value="' . e($selected) . '" selected>' . e($selected) . ' (channel not found)</option>';
    }
    return $html . '</select>';
}

/** A Discord channel ID from a form field, or '' (IDs are 17–20 digits). */
function post_channel_id(string $key): string
{
    $v = post_str($key, 30);
    return preg_match('/^\d{1,25}$/', $v) ? $v : '';
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

/**
 * Looks a Discord name up on the bot's server (username, display name or user ID).
 * Returns ['checked' => bool, 'found' => bool, 'username' => string].
 * checked = false when the bot can't be asked (not set up, down, or an old bot version):
 * the caller then accepts the name as typed.
 */
function discord_resolve_member(string $name): array
{
    $none = ['checked' => false, 'found' => false, 'username' => ''];
    if (!discord_enabled()) {
        return $none;
    }
    $r = discord_request('POST', '/members/resolve', ['users' => [$name]], 4);
    if (!$r['ok'] || !is_array($r['data']) || !isset($r['data'][0])) {
        return $none;
    }
    $row = $r['data'][0];
    return ['checked' => true, 'found' => !empty($row['found']), 'username' => (string) ($row['username'] ?? '')];
}

/** Gives one player the team role right away (creates the team on Discord if needed). */
function discord_add_player(string $teamName, string $discord): array
{
    $r = discord_request('POST', '/teams/import', [
        'teams'          => [['name' => $teamName, 'members' => [$discord]]],
        'replace'        => false,
        'createChannels' => discord_on('discord_create_channels'),
    ], 20);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => $r['error']];
    }
    $row = $r['data'][0] ?? [];
    return ['ok' => !empty($row['added']), 'message' => empty($row['added']) ? 'not found on the Discord server' : ''];
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
    $error = $r['error'];
    // A bot from before the Tourney integration ignores "teams" and answers with its generic "pick a channel" error.
    if (!$r['ok'] && empty($payload['teams']) && empty($payload['channelId']) && empty($payload['channelIds'])
        && str_contains($error, 'Valitse vähintään yksi kanava')) {
        $error = 'no channel was chosen and the bot has no default announcement channel. Pick the channel here in Tourney.';
    }
    if (!$r['ok'] && !empty($payload['teams']) && str_contains($error, 'Valitse vähintään yksi kanava')) {
        $error .= ' – The bot is probably an older version that cannot message teams yet: update the bot (lanibot26) and restart it.';
    }
    return ['ok' => $r['ok'], 'message' => $r['ok'] ? 'Sent to Discord.' : 'Discord: ' . $error];
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

/* ---------- Map ban: a separate link for each team ---------- */

/**
 * Own text channel of each team on Discord, from the bot: ['team name' => channel ID or null].
 * Returns null when the bot can't be asked.
 */
function discord_team_channels(array $names): ?array
{
    $r = discord_request('GET', '/teams', null, 4);
    if (!$r['ok'] || !is_array($r['data'])) {
        return null;
    }
    $byName = [];
    foreach ($r['data'] as $team) {
        if (isset($team['name'])) {
            $byName[strtolower((string) $team['name'])] = $team['textChannelId'] ?? null;
        }
    }
    $out = [];
    foreach ($names as $name) {
        $out[$name] = $byName[strtolower($name)] ?? null;
    }
    return $out;
}

/** Map ban link of one side of a match ('team1' / 'team2'), or ''. */
function mapban_link(array $m, string $side): string
{
    return trim((string) ($m[$side === 'team1' ? 'mapban1' : 'mapban2'] ?? ''));
}

/**
 * Sends a message that only one team may see (its own map ban link) to that team's OWN channel.
 * Never falls back to a shared channel: if the team has no own channel, nothing is sent.
 */
function discord_private_to_team(array $m, string $side, ?array $channels, string $title, string $body, string $color): array
{
    $team = $m[$side . '_name'];
    if ($channels === null) {
        return ['ok' => false, 'message' => 'Discord: the bot could not be reached, so the map ban link of ' . $team . ' was not sent.'];
    }
    $channelId = $channels[$team] ?? null;
    if (!$channelId) {
        return ['ok' => false, 'message' => "Discord: {$team} has no own team channel, so its map ban link was not sent "
            . '(it would have been visible to others). Sync the teams with channels first.'];
    }
    $payload = [
        'channelIds' => [$channelId],
        'mention'    => 'team',
        'title'      => $title,
        'body'       => $body,
        'color'      => $color,
    ];
    if (discord_on('discord_attach_server') && !empty($m['court_name'])) {
        $payload['serverNames'] = [$m['court_name']];
    }
    return discord_announce($payload);
}

/** Body text: the team's own map ban link + a reminder not to share it. */
function discord_mapban_text(string $link): string
{
    return "🗺️ **Teidän map ban -linkki:** {$link}\nLinkki on vain teidän tiimille. Älä jaa sitä vastustajalle.";
}

/**
 * Sends each team ONLY its own map ban link. Teams without a link are skipped.
 * Returns ['ok', 'message'].
 */
function discord_send_mapbans(array $tournament, array $m): array
{
    $sides = array_values(array_filter(['team1', 'team2'], fn($s) => mapban_link($m, $s) !== ''));
    if (!$sides) {
        return ['ok' => false, 'message' => 'Add at least one map ban link first.'];
    }
    $channels = discord_team_channels([$m['team1_name'], $m['team2_name']]);
    $sent = [];
    $errors = [];
    foreach ($sides as $side) {
        $other = $side === 'team1' ? 'team2' : 'team1';
        $r = discord_private_to_team(
            $m, $side, $channels,
            '🗺️ Map ban: ' . $m[$side . '_name'] . ' vs ' . $m[$other . '_name'],
            discord_mapban_text(mapban_link($m, $side)) . "\n\n" . discord_match_line($m) . "\n*" . $tournament['name'] . '*',
            '#9b59b6'
        );
        if ($r['ok']) {
            $sent[] = $m[$side . '_name'];
        } else {
            $errors[] = $r['message'];
        }
    }
    $msg = $sent ? 'Map ban link sent to ' . implode(' and ', $sent) . ' (each only its own).' : '';
    if ($errors) {
        $msg = trim($msg . ' ' . implode(' ', $errors));
    }
    return ['ok' => !$errors, 'message' => $msg];
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
        $title = '⚔️ Ottelu alkaa: ' . $after['team1_name'] . ' vs ' . $after['team2_name'];
        $body = discord_match_line($after) . "\n*" . $tournament['name'] . '*';
        $withLinks = discord_on('discord_mapban_on_live')
            && (mapban_link($after, 'team1') !== '' || mapban_link($after, 'team2') !== '');
        if (!$withLinks) {
            $results[] = discord_to_teams($tournament, $after, $title, $body, '#e74c3c');
        } else {
            // Each team gets its own message: the shared start info + only its own map ban link.
            $channels = discord_team_channels([$after['team1_name'], $after['team2_name']]);
            foreach (['team1', 'team2'] as $side) {
                $link = mapban_link($after, $side);
                if ($link === '') {
                    $results[] = discord_to_teams($tournament, $after, $title, $body, '#e74c3c', $side);
                    continue;
                }
                $r = discord_private_to_team($after, $side, $channels, $title, discord_mapban_text($link) . "\n\n" . $body, '#e74c3c');
                if (!$r['ok']) {
                    // Still tell the team the match started, just without the private link.
                    discord_to_teams($tournament, $after, $title, $body, '#e74c3c', $side);
                }
                $results[] = $r;
            }
        }
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
