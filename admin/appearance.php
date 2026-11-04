<?php
declare(strict_types=1);
require __DIR__ . '/../_init.php';

$me = require_permission('settings_manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'site_name':
                $name = post_str('site_name', 60);
                setting_set('site_name', $name !== '' ? $name : 'Tourney');
                setting_set('show_site_name', isset($_POST['show_site_name']) ? '1' : '0');
                flash('Name saved.');
                break;

            case 'logo':
                replace_setting_file('logo_file', save_uploaded_image('logo', 'logo'));
                flash('Logo uploaded.');
                break;

            case 'logo_remove':
                replace_setting_file('logo_file', null);
                flash('Logo removed.');
                break;

            case 'background':
                replace_setting_file('bg_file', save_uploaded_image('background', 'background'));
                flash('Background image uploaded.');
                break;

            case 'background_remove':
                replace_setting_file('bg_file', null);
                flash('Background image removed.');
                break;

            case 'preset':
                $preset = THEME_PRESETS[(string) ($_POST['preset'] ?? '')] ?? null;
                if ($preset === null) {
                    setting_set('theme_mode', 'default');
                    flash('Back to the built-in look (follows light/dark mode of each device).');
                    break;
                }
                foreach (array_keys(THEME_COLOR_FIELDS) as $key) {
                    setting_set($key, $preset[$key]);
                }
                setting_set('theme_mode', 'custom');
                flash($preset['label'] . ' colors applied. Fine-tune them below if you like.');
                break;

            case 'colors':
                foreach (array_keys(THEME_COLOR_FIELDS) as $key) {
                    $color = valid_color((string) ($_POST[$key] ?? ''));
                    if ($color === null) {
                        throw new InvalidArgumentException('Pick a valid color for ' . THEME_COLOR_FIELDS[$key] . '.');
                    }
                    setting_set($key, $color);
                }
                setting_set('theme_mode', 'custom');
                flash('Colors saved.');
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect(url('/admin/appearance.php'));
}

$theme = theme();
$maxUpload = ini_get('upload_max_filesize');

page_header('Appearance', ['nav' => 'appearance']);
?>
<div class="page-title">
  <div>
    <h1>Appearance</h1>
    <p class="muted">Your own logo, background and colors. They apply to every page and the TV dashboard.</p>
  </div>
</div>

<div class="two-col">
  <div class="card">
    <h2>Logo</h2>
    <?php if ($theme['logo']): ?>
      <div class="logo-preview"><img src="<?= e($theme['logo']) ?>" alt="Current logo"></div>
    <?php else: ?>
      <p class="muted small">No logo yet. The top bar shows 🏆 and the site name.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="logo">
      <label>PNG with a transparent background works best (also JPG, WebP, GIF, max <?= e($maxUpload) ?>)
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif" required>
      </label>
      <div><button class="btn primary">Upload logo</button></div>
    </form>
    <?php if ($theme['logo']): ?>
      <form method="post" class="mt">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logo_remove">
        <button class="linkbtn danger-text small">Remove logo</button>
      </form>
    <?php endif; ?>

    <hr>
    <h2>Site name</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="site_name">
      <label>Name <input name="site_name" maxlength="60" value="<?= e(site_name()) ?>"></label>
      <label class="check"><input type="checkbox" name="show_site_name" <?= $theme['show_name'] ? 'checked' : '' ?>> Show the name next to the logo (untick if the logo already has the name in it)</label>
      <div><button class="btn primary">Save</button></div>
    </form>
  </div>

  <div class="card">
    <h2>Background image</h2>
    <?php if ($theme['background']): ?>
      <div class="bg-preview" style="background-image:url('<?= e($theme['background']) ?>')"></div>
    <?php else: ?>
      <p class="muted small">Optional. A big photo or pattern behind everything. Cards stay solid so text is readable.</p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="background">
      <label>JPG or PNG, around 1920×1080 (max <?= e($maxUpload) ?>)
        <input type="file" name="background" accept="image/png,image/jpeg,image/webp" required>
      </label>
      <div><button class="btn primary">Upload background</button></div>
    </form>
    <?php if ($theme['background']): ?>
      <form method="post" class="mt">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="background_remove">
        <button class="linkbtn danger-text small">Remove background image</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card mt">
  <h2>Colors</h2>
  <p class="muted small">Start from a preset, then fine-tune. The page previews your changes as you pick. Press Save to keep them.</p>
  <div class="preset-row">
    <?php foreach (THEME_PRESETS as $key => $p): ?>
      <form method="post" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preset">
        <input type="hidden" name="preset" value="<?= e($key) ?>">
        <button class="preset" style="--p-bg:<?= e($p['theme_bg']) ?>;--p-surface:<?= e($p['theme_surface']) ?>;--p-accent:<?= e($p['theme_accent']) ?>;--p-text:<?= e($p['theme_text']) ?>">
          <span class="preset-swatch"><i></i><b></b></span><?= e($p['label']) ?>
        </button>
      </form>
    <?php endforeach; ?>
    <form method="post" class="inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preset">
      <input type="hidden" name="preset" value="default">
      <button class="preset <?= $theme['custom'] ? '' : 'current' ?>">
        <span class="preset-swatch auto"><i></i><b></b></span>Built-in (auto light/dark)
      </button>
    </form>
  </div>

  <form method="post" class="color-grid" id="color-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="colors">
    <?php foreach (THEME_COLOR_FIELDS as $key => $label): ?>
      <label class="color-field">
        <input type="color" name="<?= e($key) ?>" value="<?= e($theme[$key] ?? '#000000') ?>" data-key="<?= e($key) ?>">
        <span><?= e($label) ?><br><code class="muted small"><?= e($theme[$key] ?? '') ?></code></span>
      </label>
    <?php endforeach; ?>
    <div class="color-actions">
      <button class="btn primary">Save colors</button>
      <?php if (!$theme['custom']): ?><span class="muted small">Using the built-in look right now. Saving switches to these colors.</span><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2>Preview</h2>
  <div class="theme-preview">
    <div class="match status-live">
      <div class="match-meta"><span class="time">18:30</span><span class="court">Court 1</span><span class="group">Group A</span></div>
      <div class="match-teams"><span class="t1 winner">Team Red</span><span class="score">2 – 1</span><span class="t2">Team Blue</span></div>
      <div class="match-status"><span class="badge badge-live">LIVE</span></div>
    </div>
    <p><a class="btn primary" href="#" onclick="return false">Button</a> <a href="#" onclick="return false">A link</a> <span class="muted">Muted text</span></p>
  </div>
</div>

<script>
// Live preview: update the page colors while picking (only the basics; Save applies everything).
(function () {
  var root = document.documentElement;
  var map = { theme_bg: '--bg', theme_surface: '--surface', theme_text: '--text', theme_accent: '--accent' };
  document.querySelectorAll('#color-form input[type=color]').forEach(function (input) {
    input.addEventListener('input', function () {
      var v = map[input.dataset.key];
      if (v) { root.style.setProperty(v, input.value); document.body.style.setProperty(v, input.value); }
      input.parentNode.querySelector('code').textContent = input.value;
    });
  });
})();
</script>
<?php
page_footer();
