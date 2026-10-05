<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$update=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($update)){http_response_code(400);exit('Invalid update');}

$cfg=skynoc_telegram_settings();
$token=skynoc_telegram_bot_token();
$adminIds=skynoc_telegram_admin_chat_ids();

function tg_admin_allowed(string $chatId,array $adminIds): bool{return in_array($chatId,$adminIds,true);}
function tg_send_admin(string $chatId,string $text,?array $buttons=null): void{global $token;skynoc_telegram_send($token,$chatId,$text,$buttons);}
function tg_complete_manual_order(int $orderId,string $licenseKey,string $chatId): void{
    global $db;
    try{
        $db->beginTransaction();
        $q=$db->prepare("SELECT o.id,o.reseller_id,o.package_id,o.domain,o.status,o.amount,p.name package_name,p.billing_period FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.id=? FOR UPDATE");
        $q->execute([$orderId]);$o=$q->fetch();
        if(!$o||$o['status']!=='pending')throw new RuntimeException('Order not found or is no longer pending.');
        $q=$db->prepare("SELECT id,license_key,status,reseller_id FROM licenses WHERE license_key=? LIMIT 1 FOR UPDATE");
        $q->execute([$licenseKey]);$l=$q->fetch();
        if(!$l)throw new RuntimeException('License key was not found.');
        if($l['status']!=='available'||$l['reseller_id']!==null)throw new RuntimeException('License is not available for assignment.');
        $expires=null;
        if($o['billing_period']==='monthly')$expires=(new DateTimeImmutable('now'))->modify('+1 month')->format('Y-m-d H:i:s');
        elseif($o['billing_period']==='annual')$expires=(new DateTimeImmutable('now'))->modify('+1 year')->format('Y-m-d H:i:s');
        $db->prepare("UPDATE licenses SET reseller_id=?,domain=?,status='active',purchase_date=CURDATE(),expires_at=?,package_id=?,updated_at=NOW() WHERE id=? AND status='available' AND reseller_id IS NULL")->execute([(int)$o['reseller_id'],$o['domain'],$expires,(int)$o['package_id'],(int)$l['id']]);
        if($db->prepare('SELECT ROW_COUNT()')->execute()===false){}
        $db->prepare("UPDATE orders SET license_id=?,status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'")->execute([(int)$l['id'],$orderId]);
        if($db->rowCount??false){}
        $db->prepare('INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)')->execute([(int)$l['id'],'telegram_assign',null,$o['domain'],'Assigned from Telegram to order #'.$orderId]);
        $db->commit();
        platform_invoice_for_order($orderId);
        notify_reseller((int)$o['reseller_id'],'order','License provisioned','Order #'.$orderId.' completed. License: '.$licenseKey);
        tg_send_admin($chatId,"✅ <b>ORDER COMPLETED</b>\n\n🆔 Order: #{$orderId}\n🔑 License: <code>".e($licenseKey)."</code>\n🌐 Domain: ".e($o['domain']));
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();tg_send_admin($chatId,'❌ <b>Assignment failed</b>\n\n'.e($e->getMessage()));}
}

if(isset($update['callback_query'])){
    $cb=$update['callback_query'];
    $chatId=(string)($cb['message']['chat']['id']??'');
    $callbackId=(string)($cb['id']??'');
    if(!tg_admin_allowed($chatId,$adminIds)){skynoc_telegram_answer_callback($token,$callbackId,'Not authorized.');exit('Forbidden');}
    if(!skynoc_telegram_event_once('callback:'.$callbackId)){skynoc_telegram_answer_callback($token,$callbackId,'Already processed.');exit('OK');}
    $data=(string)($cb['data']??'');
    $parts=explode(':',$data);
    if(count($parts)!==3||$parts[0]!=='order'){skynoc_telegram_answer_callback($token,$callbackId,'Unsupported action.');exit('OK');}
    $orderId=(int)$parts[1];$action=$parts[2];
    if($action==='assign'){
        skynoc_telegram_answer_callback($token,$callbackId,'Send /assign ORDER_ID LICENSE_KEY');
        tg_send_admin($chatId,"🔑 <b>Assign License</b>\n\nSend this command:\n<code>/assign {$orderId} YOUR-LICENSE-KEY</code>");
    }elseif($action==='view'){
        $q=$db->prepare('SELECT o.*,p.name package_name,r.name reseller_name,r.email reseller_email,l.license_key FROM orders o JOIN packages p ON p.id=o.package_id JOIN resellers r ON r.id=o.reseller_id LEFT JOIN licenses l ON l.id=o.license_id WHERE o.id=? LIMIT 1');$q->execute([$orderId]);$o=$q->fetch();
        if(!$o){tg_send_admin($chatId,'❌ Order not found.');}else{tg_send_admin($chatId,"🔎 <b>ORDER #{$orderId}</b>\n\n👤 Reseller: ".e($o['reseller_name'])."\n📧 Email: ".e($o['reseller_email'])."\n📦 Package: ".e($o['package_name'])."\n💰 Amount: $".number_format((float)$o['amount'],2).'\n🌐 Domain: '.e($o['domain'])."\n📌 Status: <b>".e(strtoupper($o['status'])).'</b>'.($o['license_key']?"\n🔑 License: <code>".e($o['license_key']).'</code>':'').'\n');}
    }elseif($action==='reject'){
        try{$db->beginTransaction();$q=$db->prepare("SELECT id,reseller_id,amount,status FROM orders WHERE id=? FOR UPDATE");$q->execute([$orderId]);$o=$q->fetch();if(!$o||$o['status']!=='pending')throw new RuntimeException('Order is not pending.');$db->prepare("UPDATE orders SET status='rejected',updated_at=NOW() WHERE id=? AND status='pending'")->execute([$orderId]);wallet_credit((int)$o['reseller_id'],(float)$o['amount'],'refund','REFUND-ORDER-'.$orderId,'Refund for rejected order #'.$orderId,$orderId,null);$db->commit();notify_reseller((int)$o['reseller_id'],'order','Order rejected','Order #'.$orderId.' was rejected and $'.number_format((float)$o['amount'],2).' was returned to your wallet.');tg_send_admin($chatId,"❌ <b>ORDER REJECTED</b>\n\nOrder #{$orderId} rejected and wallet refunded.");}catch(Throwable $e){if($db->inTransaction())$db->rollBack();tg_send_admin($chatId,'❌ <b>Reject failed</b>\n\n'.e($e->getMessage()));}
    }
    skynoc_telegram_answer_callback($token,$callbackId,'Done');
    exit('OK');
}

if(isset($update['message'])){
    $m=$update['message'];$chatId=(string)($m['chat']['id']??'');$text=trim((string)($m['text']??''));
    if(!tg_admin_allowed($chatId,$adminIds)){http_response_code(403);exit('Forbidden');}
    if(preg_match('/^\/assign\s+(\d+)\s+([^\s]+)$/i',$text,$mm)){
        if(!skynoc_telegram_event_once('command:assign:'.(string)($m['message_id']??'').':'.$mm[1])){exit('OK');}
        tg_complete_manual_order((int)$mm[1],trim($mm[2]),$chatId);
        exit('OK');
    }
}
http_response_code(200);echo 'OK';
