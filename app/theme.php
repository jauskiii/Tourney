<?php
declare(strict_types=1);

/*
 * Appearance: logo, background image and colors, stored in the settings table.
 * The colors become CSS variables that every page (and the TV dashboard) uses.
 */

const THEME_COLOR_FIELDS = [
    'theme_bg'      => 'Page background',
    'theme_surface' => 'Cards and top bar',
    'theme_text'    => 'Text',
    'theme_accent'  => 'Accent (buttons, links, highlights)',
    'theme_dash_bg' => 'TV dashboard background',
];

const THEME_PRESETS = [
    'liftup' => [
        'label' => 'Liftup',
        'theme_bg' => '#171e2a', 'theme_surface' => '#29344a', 'theme_text' => '#ffffff',
        'theme_accent' => '#ff9ab0', 'theme_dash_bg' => '#000000',
    ],
    'midnight' => [
        'label' => 'Midnight blue',
        'theme_bg' => '#0b1220', 'theme_surface' => '#141d2f', 'theme_text' => '#e8edf7',
        'theme_accent' => '#4f8cff', 'theme_dash_bg' => '#05080f',
    ],
    'neon' => [
        'label' => 'Neon green',
        'theme_bg' => '#0d0f0e', 'theme_surface' => '#181c1a', 'theme_text' => '#eef3f0',
        'theme_accent' => '#3ddc84', 'theme_dash_bg' => '#000000',
    ],
    'light' => [
        'label' => 'Clean light',
        'theme_bg' => '#f4f5f7', 'theme_surface' => '#ffffff', 'theme_text' => '#1c2230',
        'theme_accent' => '#2f6fed', 'theme_dash_bg' => '#0b0e14',
    ],
];

function theme_defaults(): array
{
    return ['custom' => false, 'logo' => null, 'background' => null, 'show_name' => true];
}

/** Current theme values. 'custom' is false while the built-in look is used. */
function theme(): array
{
    static $theme = null;
    if ($theme !== null) {
        return $theme;
    }
    $theme = theme_defaults();
    if (!has_config()) {
        return $theme;
    }
    try {
        $rows = all("SELECT `key`, value FROM settings WHERE `key` LIKE 'theme\\_%' OR `key` IN ('logo_file', 'bg_file', 'show_site_name')");
    } catch (Throwable $e) {
        return $theme;
    }
    $s = array_column($rows, 'value', 'key');

    $theme['custom'] = ($s['theme_mode'] ?? 'default') === 'custom';
    foreach (array_keys(THEME_COLOR_FIELDS) as $key) {
        $theme[$key] = valid_color($s[$key] ?? '') ?? THEME_PRESETS['midnight'][$key];
    }
    foreach (['logo_file' => 'logo', 'bg_file' => 'background'] as $key => $name) {
        $file = basename($s[$key] ?? '');
        if ($file !== '' && is_file(uploads_dir() . '/' . $file)) {
            $theme[$name] = url('/uploads/' . rawurlencode($file));
        }
    }
    $theme['show_name'] = ($s['show_site_name'] ?? '1') === '1';
    return $theme;
}

function valid_color(string $value): ?string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
}

/** Mix two #rrggbb colors. $weight = share of $a (0..1). */
function mix_colors(string $a, string $b, float $weight): string
{
    $ca = sscanf($a, '#%02x%02x%02x');
    $cb = sscanf($b, '#%02x%02x%02x');
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $out .= sprintf('%02x', (int) round($ca[$i] * $weight + $cb[$i] * (1 - $weight)));
    }
    return $out;
}

function color_luminance(string $hex): float
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
}

function hex_rgba(string $hex, float $alpha): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return "rgba({$r}, {$g}, {$b}, {$alpha})";
}

/** The <style> block that applies the theme, or '' for the built-in look. */
function theme_css(): string
{
    $t = theme();
    $css = '';
    if ($t['custom']) {
        $bg = $t['theme_bg'];
        $surface = $t['theme_surface'];
        $text = $t['theme_text'];
        $accent = $t['theme_accent'];
        // Text on accent-coloured buttons: dark on light accents, white on dark ones.
        $accentText = '#ffffff';
        if (color_luminance($accent) > 0.55) {
            $accentText = color_luminance($bg) < 0.3 ? $bg : '#111111';
        }
        $vars = [
            '--bg' => $bg,
            '--surface' => $surface,
            '--surface-2' => mix_colors($surface, $text, 0.9),
            '--text' => $text,
            '--muted' => mix_colors($text, $surface, 0.65),
            '--border' => mix_colors($surface, $text, 0.8),
            '--accent' => $accent,
            '--accent-text' => $accentText,
            '--qualify' => hex_rgba($accent, 0.18),
            '--shadow' => 'none',
        ];
        $decl = '';
        foreach ($vars as $k => $v) {
            $decl .= "{$k}:{$v};";
        }
        $css .= ":root,body.dashboard{{$decl}}body.dashboard{--bg:{$t['theme_dash_bg']};}";
        $css .= '.swatch{border-color:' . $accent . ';}';
        $css .= '.final-banner{background:linear-gradient(135deg,' . hex_rgba($accent, 0.28) . ',' . $surface . ');border-color:' . $accent . ';}';
        $css .= '.final-label{color:' . $accent . ';}';
        $css .= 'body{background-color:var(--bg);color:var(--text);}';
    }
    if ($t['background']) {
        $css .= 'body{background-image:url("' . $t['background'] . '");background-size:cover;'
              . 'background-position:center;background-attachment:fixed;background-repeat:no-repeat;}';
    }
    return $css === '' ? '' : "<style id=\"theme\">{$css}</style>\n";
}

/* ---------- uploads ---------- */

function uploads_dir(): string
{
    return PUBLIC_ROOT . '/uploads';
}

/**
 * Saves an uploaded image into public/uploads with a random name.
 * Returns the new file name. Throws InvalidArgumentException with a readable message.
 */
function save_uploaded_image(string $field, string $prefix): string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose an image file first.');
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new InvalidArgumentException('That file is bigger than this server allows (' . ini_get('upload_max_filesize') . '). Make it smaller and try again.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The upload failed. Try again.');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('Images can be at most 8 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_PNG => '.png', IMAGETYPE_JPEG => '.jpg', IMAGETYPE_WEBP => '.webp', IMAGETYPE_GIF => '.gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new InvalidArgumentException('Only PNG, JPG, WebP or GIF images work.');
    }

    $dir = uploads_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new InvalidArgumentException('Could not create the uploads folder. Make the site folder writable for the web server.');
    }
    $name = $prefix . '-' . bin2hex(random_bytes(6)) . $types[$info[2]];
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new InvalidArgumentException('Could not save the file. Make the uploads folder writable for the web server.');
    }
    @chmod($dir . '/' . $name, 0644);
    return $name;
}

/** Replaces the file stored in a setting and deletes the old one. */
function replace_setting_file(string $key, ?string $newName): void
{
    $old = basename((string) setting_get($key));
    setting_set($key, $newName ?? '');
    if ($old !== '' && $old !== $newName && is_file(uploads_dir() . '/' . $old)) {
        @unlink(uploads_dir() . '/' . $old);
    }
}
