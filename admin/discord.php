<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';

$me = require_permission('settings_manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $url = rtrim(post_str('discord_bot_url', 200), '/');
        if ($url !== '' && !preg_match('#^https?://[^\s/]+#i', $url)) {
            flash('The bot address must start with http:// or https://', 'error');
            redirect(url('/admin/discord.php'));
        }
        setting_set('discord_bot_url', $url);
        // An empty token field keeps the saved token (it is never shown again).
        $token = post_str('discord_bot_token', 200);
        if ($token !== '') {
            setting_set('discord_bot_token', $token);
        }
        if (isset($_POST['clear_token'])) {
            setting_set('discord_bot_token', '');
        }
        $channel = post_str('discord_result_channel', 30);
        setting_set('discord_result_channel', preg_match('/^\d{1,25}$/', $channel) ? $channel : '');
        foreach (['discord_auto_live', 'discord_mapban_on_live', 'discord_auto_result', 'discord_auto_champion', 'discord_create_channels', 'discord_attach_server'] as $key) {
            setting_set($key, isset($_POST[$key]) ? '1' : '0');
        }
        flash('Discord settings saved.');
        redirect(url('/admin/discord.php?test=1'));
    }
    redirect(url('/admin/discord.php'));
}

$s = discord_settings();
$test = null;
if (discord_enabled() && isset($_GET['test'])) {
    $test = discord_test();
}

page_header('Discord', ['nav' => 'discord']);
?>
<div class="page-title">
  <div>
    <h1>Discord</h1>
    <p class="muted">Connect Tourney to the Liftup Discord bot: team roles and channels, match announcements and results.</p>
  </div>
  <?php if (discord_enabled()): ?>
  <div class="actions"><a class="btn ghost" href="?test=1">Test connection</a></div>
  <?php endif; ?>
</div>

<?php if ($test !== null): ?>
  <?php if ($test['ok']): $d = $test['data']; ?>
    <div class="flash flash-ok">✓ Connected to <strong><?= e($d['bot']['tag'] ?? 'the bot') ?></strong>
      on <strong><?= e($d['guild']['name'] ?? '?') ?></strong> (<?= (int) ($d['guild']['members'] ?? 0) ?> members, <?= (int) ($d['teams'] ?? 0) ?> teams in the bot).</div>
  <?php else: ?>
    <div class="flash flash-error">✕ <?= e($test['error']) ?></div>
  <?php endif; ?>
<?php endif; ?>

<div class="two-col">
  <div class="card">
    <h2>Connection</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <label>Bot address
        <input name="discord_bot_url" value="<?= e($s['discord_bot_url']) ?>" placeholder="http://10.0.0.5:8080" maxlength="200">
      </label>
      <label>API token
        <input type="password" name="discord_bot_token" value="" autocomplete="new-password" maxlength="200"
               placeholder="<?= $s['discord_bot_token'] !== '' ? '•••••••• saved – leave empty to keep' : 'API_TOKEN of the bot' ?>">
      </label>
      <?php if ($s['discord_bot_token'] !== ''): ?>
        <label class="check small"><input type="checkbox" name="clear_token"> Remove the saved token (turns the integration off)</label>
      <?php endif; ?>

      <h3>Automatic announcements</h3>
      <label class="check"><input type="checkbox" name="discord_auto_live" <?= $s['discord_auto_live'] === '1' ? 'checked' : '' ?>>
        <span>When a match goes <strong>▶ Live</strong>, tell both teams on their own channels</span></label>
      <label class="check"><input type="checkbox" name="discord_mapban_on_live" <?= $s['discord_mapban_on_live'] === '1' ? 'checked' : '' ?>>
        <span>Put each team's <strong>own map ban link</strong> in its Live message (only sent to the team's own channel, never to a shared one)</span></label>
      <label class="check"><input type="checkbox" name="discord_auto_result" <?= $s['discord_auto_result'] === '1' ? 'checked' : '' ?>>
        <span>When a match is <strong>✓ Finished</strong>, post the result</span></label>
      <label class="check"><input type="checkbox" name="discord_auto_champion" <?= $s['discord_auto_champion'] === '1' ? 'checked' : '' ?>>
        <span>When the final is finished, announce the champion (@here)</span></label>
      <label>Results channel <span class="muted small">(empty = the bot's default announcement channel)</span>
        <?= discord_enabled() ? discord_channel_select('discord_result_channel', $s['discord_result_channel'], "— the bot's default announcement channel —") : '<input name="discord_result_channel" value="' . e($s['discord_result_channel']) . '" placeholder="123456789012345678" inputmode="numeric" maxlength="20">' ?>
      </label>

      <h3>Teams</h3>
      <label class="check"><input type="checkbox" name="discord_create_channels" <?= $s['discord_create_channels'] === '1' ? 'checked' : '' ?>>
        <span>Team sync creates a private text + voice channel for every team</span></label>
      <label class="check"><input type="checkbox" name="discord_attach_server" <?= $s['discord_attach_server'] === '1' ? 'checked' : '' ?>>
        <span>Attach the server address when a bot server has the same name as the court (e.g. court "CS2 #1" ↔ bot server "CS2 #1")</span></label>

      <div><button class="btn primary">Save and test</button></div>
    </form>
  </div>

  <div class="card">
    <h2>How it works</h2>
    <ol class="steps">
      <li>In the bot's environment, set <code>API_TOKEN</code> to a long random string, and paste the same value here.
        The bot address is its web panel address (port of its Pterodactyl allocation).</li>
      <li>Give players a <strong>Discord name</strong> on the <em>Teams &amp; players</em> tab: username, display name or user ID.</li>
      <li>Press <strong>⇅ Sync teams to Discord</strong>. Each team gets a role (and channels), and players get their role.
        Sync again after changes.</li>
      <li>During the tournament: <strong>▶ Live</strong> and <strong>✓ Finished</strong> announce by themselves.
        Use <strong>📣 Send</strong> under a match for e.g. the map ban link – it goes to both teams.</li>
      <li>After the event: <strong>Remove teams from Discord</strong> deletes the roles and channels.</li>
    </ol>
    <p class="muted small">Score autosaves while a match is live are never announced – only status changes.
      If the bot is down, scores still save normally and the admin page shows a warning.</p>
  </div>
</div>
<?php
page_footer();
