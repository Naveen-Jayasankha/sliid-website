<?php
return [
    'app_name' => 'SLIID Administration',
    'app_url' => 'https://api.example.com',
    'timezone' => 'Asia/Colombo',
    'debug' => false,
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'sliid',
        'user' => 'sliid_user',
        'password' => 'change-this-password',
        'charset' => 'utf8mb4',
    ],
    'cors_origins' => [
        'https://naveen-jayasankha.github.io',
        'https://www.sliid.org',
        'https://sliid.org',
    ],
    'mail' => [
        'enabled' => false,
        'host' => 'mail.sliid.org',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'events@sliid.org',
        'password' => 'enter-the-mailbox-password',
        'from_email' => 'events@sliid.org',
        'from_name' => 'SLIID Events',
        'reply_to' => 'events@sliid.org',
    ],
    'upload_max_bytes' => 5242880,
];
