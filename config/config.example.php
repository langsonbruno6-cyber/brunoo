<?php
// Copy this file to config/config.local.php and enter your own InfinityFree MySQL details.
// NEVER commit config.local.php to GitHub and NEVER send your database password in chat.
return [
    'db' => [
        'host' => 'YOUR_INFINITYFREE_MYSQL_HOST',
        'name' => 'YOUR_DATABASE_NAME',
        'user' => 'YOUR_DATABASE_USERNAME',
        'pass' => 'YOUR_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'session_name' => 'bloodlink_session',
        'allowed_origin' => '', // Keep empty for same-origin hosting.
    ],
];
