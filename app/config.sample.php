<?php
// Normally you DON'T need this file: open install.php in your browser and it
// writes app/config.php for you (like Dev Board).
//
// Only if install.php can't write the file: copy this to app/config.php,
// fill in your MySQL details, then open install.php to create the admin account.

return [
    'DB_HOST'   => 'localhost',
    'DB_PORT'   => '3306',
    'DB_NAME'   => 'your_database_name',
    'DB_USER'   => 'your_database_user',
    'DB_PASS'   => 'your_database_password',

    // Sender address for password reset emails (optional)
    'MAIL_FROM' => '',

    'TZ'        => 'Europe/Helsinki',

    // Leave empty: the folder is detected automatically.
    // Only set this if links break, e.g. '/turnaus' for https://yoursite.fi/turnaus/
    'BASE_URL'  => '',
];
