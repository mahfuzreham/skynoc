<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/order_manual.php';
$cfg=skynoc_telegram_settings();
$token=trim((string)($cfg['license_bot_token']??$cfg['bot_token']??''));
$adminChat=trim((string)($cfg['license_admin_chat_id']??$cfg['admin_chat_id']??''));
$adminIds=skynoc_telegram_admin_chat_ids();
if($token===''||(!$adminChat&&!$adminIds)){http_response_code(503);exit('Telegram order bot is not configured.');}
$u=json_decode(file_get_contents('php://input')?:'',true);
if(!is_array($u)){http_response_code(400);exit('Invalid update.');}
$chat=(string)($u['message']['chat']['id']??'');
$allowed=$adminIds?:[$adminChat];
if($chat===''||!in_array($chat,$allowed,true)){http_response_code(403);exit('Forbidden.');}
function so_send(string $token,string $chat,string $text,?array $buttons=null):void{skynoc_telegram_send($token,$chat,$text,$buttons);}
$owner=$db->query("SELECT id FROM users WHERE role IN ('owner','admin') AND status='active' ORDER BY CASE role WHEN 'owner' THEN 0 ELSE 1 END,id ASC LIMIT 1")->fetch();
if(!$owner){http_response_code(500);exit('Admin unavailable.');}
$text=trim((string)($u['message']['text']??''));
if($text==='/start'||$text==='/help'){so_send($token,$chat,"🛡 <b>SkyNoc Order Control</b>\n\n/orders — show pending orders\n/order 123 — show order\n/order 123 LICENSEKEY — assign provider key and complete\n/addlicense KEY — add an available license to inventory\n/licenses — show available inventory\n\nAll license/order actions are protected by database checks so a repeated Telegram update cannot duplicate the same assignment.");exit('OK');}
if(preg_match('/^\/addlicense\s+(.+)$/i',$text,$m)){
 $key=trim($m[1]);
 if($key===''){so_send($token,$chat,'❌ License key is required.');exit('OK');}
 if(!skynoc_telegram_event_once('addlicense:'.hash('sha256',strtoupper($key)))){so_send($token,$chat,'ℹ️ This license-add request was already processed.');exit('OK');}
 try{$q=$db->prepare('SELECT id FROM licenses WHERE license_key=? LIMIT 1');$q->execute([$key]);if($q->fetchColumn())throw new RuntimeException('License key already exists.');$db->beginTransaction();$db->prepare("INSERT INTO licenses(provider_account_id,license_key,domain,reseller_id,status,purchase_date,cost,expires_at) VALUES(NULL,?,NULL,NULL,'available',CURDATE(),NULL,NULL)")->execute([$key]);$id=(int)$db->lastInsertId();$db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,(int)$owner['id'],'telegram_license_added','Added from Telegram admin order control']);$db->commit();so_send($token,$chat,'✅ <b>LICENSE ADDED</b>\n\n🔑 <code>'.e($key).'</code>\n📦 Status: Available\n🆔 Inventory ID: #'.$id);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();so_send($token,$chat,'❌ <b>LICENSE ADD FAILED</b>\n'.e($e->getMessage()));}exit('OK');
}
if($text==='/licenses'){$rows=$db->query("SELECT id,license_key,package_id FROM licenses WHERE status='available' AND reseller_id IS NULL ORDER BY id DESC LIMIT 30")->fetchAll();if(!$rows){so_send($token,$chat,'📦 No available licenses in inventory.');exit('OK');}$out='📦 <b>AVAILABLE LICENSE INVENTORY</b>\n\n';foreach($rows as $l)$out.='🆔 #'.(int)$l['id'].' · <code>'.e($l['license_key']).'</code>\n';so_send($token,$chat,$out);exit('OK');}
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
 $eventKey='order-assign:'.$id.':'.hash('sha256',$key);if(!skynoc_telegram_event_once($eventKey)){so_send($token,$chat,'ℹ️ This Telegram order assignment was already processed.');exit('OK');}
 try{$r=skynoc_manual_order($db,$id,$key,(int)$owner['id']);so_send($token,$chat,"✅ <b>ORDER COMPLETED</b>\nOrder: #{$id}\nLicense: <code>".e($r['key'])."</code>");}catch(Throwable $e){so_send($token,$chat,'❌ <b>ORDER FAILED</b>\n'.e($e->getMessage()));}
 exit('OK');
}
so_send($token,$chat,'Use /orders, /licenses, /addlicense KEY or /order ID LICENSEKEY.');echo 'OK';
