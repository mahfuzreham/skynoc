<?php
declare(strict_types=1);
$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['timezone']);
header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
if (session_status() !== PHP_SESSION_ACTIVE) { session_name($config['session_name']); session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'cookie_lifetime'=>0,'use_strict_mode'=>true]); }
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$config['db']['host'],$config['db']['port'],$config['db']['name'],$config['db']['charset']);
try{$db=new PDO($dsn,$config['db']['user'],$config['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(Throwable $e){http_response_code(500);exit('Database connection failed. Check config/config.php.');}
require_once __DIR__.'/helpers.php'; require_once __DIR__.'/platform_plus.php'; require_once __DIR__.'/twofa.php'; require_once __DIR__.'/telegram.php'; require_once __DIR__.'/usdt.php'; require_once __DIR__.'/payment.php'; require_once __DIR__.'/cloudflare.php'; require_once __DIR__.'/reseller_notifications.php'; require_once __DIR__.'/order_automation.php';
if(($config['auto_migrate']??true)===true){try{require_once __DIR__.'/migrator.php'; skynoc_migrate($db);}catch(Throwable $e){http_response_code(500);error_log('SkyNoc migration failed: '.$e->getMessage());exit('Database migration failed. Check the server error log.');}}
try{require_once __DIR__.'/hostname_migrate.php'; skynoc_hostname_migrate($db);}catch(Throwable $e){http_response_code(500);error_log('SkyNoc Hostname schema failed: '.$e->getMessage());exit('Hostname service database setup failed. Check the server error log.');}
