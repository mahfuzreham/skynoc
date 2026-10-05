<?php
require __DIR__ . '/../app/bootstrap.php';
if(PHP_SAPI!=='cli' && !hash_equals((string)($_GET['key']??''),(string)($config['cron_key']??''))){http_response_code(403);exit('Forbidden');}
require __DIR__ . '/../app/platform_plus.php';
$fulfilled=skynoc_auto_fulfill_orders(25);
$billing=platform_monthly_billing(100);
$expired=platform_license_expiry_sweep();
$reminders=platform_expiry_reminders();
echo "SkyNoc cron completed. Fulfilled: {$fulfilled}; Renewed: {$billing['processed']}; Suspended: {$billing['suspended']}; Initialized: {$billing['initialized']}; Billing errors: {$billing['failed']}; Expired: {$expired}; reminders: {$reminders}\n";
