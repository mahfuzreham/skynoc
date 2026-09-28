<?php
declare(strict_types=1);

/*
 * Production DB config.
 * Set real credentials here. This file should not contain provider credentials.
 */
return [
    'app_name' => 'SkyNoc License Manager',
    'base_url' => 'https://SkyNoc.Net',
    'timezone' => 'Asia/Dhaka',
    'session_name' => 'skynoc_session',
    'auto_migrate' => true,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'DATABASE_NAME',
        'user' => 'DATABASE_USER',
        'pass' => 'DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];
