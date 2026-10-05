<?php
require __DIR__ . '/../app/bootstrap.php';
if(PHP_SAPI!=='cli' && !hash_equals((string)($_GET['key']??''),(string)($config['cron_key']??''))){http_response_code(403);exit('Forbidden');}
require __DIR__ . '/../app/platform_plus.php';
$fulfilled=skynoc_auto_fulfill_orders(25);
$billing=platform_monthly_billing(100);
$expired=platform_license_expiry_sweep();
$reminders=platform_expiry_reminders();

/* Notify Telegram admins once for each license expiry event. */
if($expired>0){
    $rows=$db->query("SELECT id,reseller_id,license_key,domain,expires_at FROM licenses WHERE status='expired' AND expires_at IS NOT NULL AND expires_at BETWEEN DATE_SUB(NOW(),INTERVAL 10 MINUTE) AND NOW() ORDER BY id DESC LIMIT 100")->fetchAll();
    foreach($rows as $l){
        $eventKey='license-expired:'.(int)$l['id'].':'.date('Y-m-d',strtotime((string)$l['expires_at']));
        if(!skynoc_telegram_event_once($eventKey)) continue;
        skynoc_license_telegram_send(
            "⚠️ <b>LICENSE EXPIRED</b>\n\n".
            "🆔 License ID: #".(int)$l['id']."\n".
            "🔑 License: <code>".e((string)$l['license_key'])."</code>\n".
            "👤 Reseller ID: #".(int)$l['reseller_id']."\n".
            "🌐 Domain: ".e((string)($l['domain']?:'-'))."\n".
            "⏰ Expired: ".e((string)$l['expires_at'])."\n\n".
            "Use <code>/reissue ID</code> for reissue requests or renew from the admin panel."
        );
    }
}

echo "SkyNoc cron completed. Fulfilled: {$fulfilled}; Renewed: {$billing['processed']}; Suspended: {$billing['suspended']}; Initialized: {$billing['initialized']}; Billing errors: {$billing['failed']}; Expired: {$expired}; reminders: {$reminders}\n";
