<?php
require __DIR__ . '/../app/bootstrap.php';
if(PHP_SAPI!=='cli' && !hash_equals((string)($_GET['key']??''),(string)($config['cron_key']??''))){http_response_code(403);exit('Forbidden');}
require __DIR__ . '/../app/platform_plus.php';
$expired=platform_license_expiry_sweep();
$reminders=platform_expiry_reminders();
echo "SkyNoc cron completed. Expired: {$expired}; reminders: {$reminders}\n";