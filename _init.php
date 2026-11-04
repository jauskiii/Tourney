<?php
declare(strict_types=1);

// Finds the app/ folder in both layouts:
//  - webhotel: app/ sits next to this file (protected by app/.htaccess)
//  - Debian:   app/ sits one level up, outside the web root (/var/www/tourney/app)
define('PUBLIC_ROOT', __DIR__);

foreach ([__DIR__ . '/app', dirname(__DIR__) . '/app'] as $appDir) {
    if (is_file($appDir . '/bootstrap.php')) {
        require $appDir . '/bootstrap.php';
        return;
    }
}

http_response_code(500);
exit('Could not find the app/ folder. Upload it next to index.php.');
