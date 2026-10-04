<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/order_manual.php';
$cfg=skynoc_telegram_settings();
$token=trim((string)($cfg['license_bot_token']??$cfg['bot_token']??''));
$adminChat=trim((string)($cfg['license_admin_chat_id']??$cfg['admin_chat_id']??''));
if($token===''||$adminChat===''){http_response_code(503);exit('Telegram order bot is not configured.');}
$u=json_decode(file_get_contents('php://input')?:'',true);
if(!is_array($u)){http_response_code(400);exit('Invalid update.');}
$chat=(string)($u['message']['chat']['id']??'');
if($chat===''||!hash_equals($adminChat,$chat)){http_response_code(403);exit('Forbidden.');}
function so_send(string $token,string $chat,string $text):void{skynoc_telegram_send($token,$chat,$text);}
$owner=$db->query("SELECT id FROM users WHERE role='owner' AND status='active' ORDER BY id ASC LIMIT 1")->fetch();
if(!$owner){http_response_code(500);exit('Owner unavailable.');}
$text=trim((string)($u['message']['text']??''));
if($text==='/start'||$text==='/help'){so_send($token,$chat,"🛡 <b>SkyNoc Order Control</b>\n\n/orders — show pending orders\n/order 123 — show order\n/order 123 LICENSEKEY — assign provider key and complete");exit('OK');}
if($text==='/orders'){
 $rows=$db->query("SELECT o.id,o.domain,o.status,p.name package_name,r.name reseller_name FROM orders o JOIN packages p ON p.id=o.package_id JOIN resellers r ON r.id=o.reseller_id WHERE o.status IN ('pending','processing') ORDER BY o.id ASC LIMIT 20")->fetchAll();
 if(!$rows){so_send($token,$chat,'✅ No pending orders.');exit('OK');}
 foreach($rows as $o){so_send($token,$chat,"🛒 <b>ORDER #".(int)$o['id']."</b>\nPackage: ".e($o['package_name'])."\nReseller: ".e($o['reseller_name'])."\nDomain: ".e($o['domain'])."\nStatus: ".e(strtoupper($o['status']))."\n\n<code>/order ".(int)$o['id']." LICENSEKEY</code>");}
 exit('OK');
}
if(preg_match('/^\/order\s+(\d+)\s*(.*)$/',$text,$m)){
 $id=(int)$m[1];$key=trim($m[2]);
 $q=$db->prepare('SELECT o.*,p.name package_name,r.name reseller_name FROM orders o JOIN packages p ON p.id=o.package_id JOIN resellers r ON r.id=o.reseller_id WHERE o.id=?');$q->execute([$id]);$o=$q->fetch();
 if(!$o){so_send($token,$chat,'❌ Order not found.');exit('OK');}
 if(!in_array($o['status'],['pending','processing'],true)){so_send($token,$chat,'⚠️ Order #'.$id.' is already '.strtoupper($o['status']).'.');exit('OK');}
 if($key===''){so_send($token,$chat,"🛒 <b>ORDER #{$id}</b>\nPackage: ".e($o['package_name'])."\nReseller: ".e($o['reseller_name'])."\nDomain: ".e($o['domain'])."\nStatus: ".e(strtoupper($o['status']))."\n\nSend <code>/order {$id} LICENSEKEY</code>");exit('OK');}
 try{$r=skynoc_manual_order($db,$id,$key,(int)$owner['id']);so_send($token,$chat,"✅ <b>ORDER COMPLETED</b>\nOrder: #{$id}\nLicense: <code>".e($r['key'])."</code>");}catch(Throwable $e){so_send($token,$chat,'❌ <b>ORDER FAILED</b>\n'.e($e->getMessage()));}
 exit('OK');
}
so_send($token,$chat,'Use /orders or /order ID LICENSEKEY.');echo 'OK';
