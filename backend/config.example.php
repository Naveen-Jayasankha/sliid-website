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
        'https://www.sliid.lk',
        'https://sliid.lk',
    ],
    'upload_max_bytes' => 5242880,
];
