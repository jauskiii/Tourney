<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout();
}
redirect(url('/'));
