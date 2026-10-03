<?php
declare(strict_types=1);

return [
    'app_name' => 'SkyNoc License Manager',
    'base_url' => 'https://SkyNoc.Net',
    'timezone' => 'Asia/Dhaka',
    'session_name' => 'skynoc_session',
    'auto_migrate' => true,
    'mail' => [
        'from' => 'no-reply@skynoc.net',
    ],
    'usdt_bep20' => [
        'enabled' => true,
        'chain_id' => 56,
        'rpc_url' => 'https://bsc-dataseed.bnbchain.org',
        'token_contract' => '0x55d398326f99059ff775485246999027b3197955',
        'decimals' => 18,
        'receiving_address' => 'YOUR_BSC_USDT_RECEIVING_ADDRESS',
        'min_confirmations' => 12,
    ],
    'cloudflare' => [
        'enabled' => false,
        'api_token' => 'YOUR_CLOUDFLARE_API_TOKEN',
        'account_id' => 'YOUR_CLOUDFLARE_ACCOUNT_ID',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'DATABASE_NAME',
        'user' => 'DATABASE_USER',
        'pass' => 'DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],
];
