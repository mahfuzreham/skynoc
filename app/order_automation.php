<?php
declare(strict_types=1);

/** Automatically fulfills paid reseller orders from available inventory; otherwise leaves them pending for manual license assignment. */
function skynoc_auto_fulfill_orders(int $limit=25): int
{
    global $db;
    $done=0;
    $q=$db->prepare("SELECT o.id,o.reseller_id,o.package_id,o.domain,p.name,p.billing_period FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.status='pending' ORDER BY o.id ASC LIMIT ?");
    $q->bindValue(1,max(1,min(100,$limit)),PDO::PARAM_INT); $q->execute(); $orders=$q->fetchAll();
    foreach($orders as $order){
        try{
            $db->beginTransaction();
            $lock=$db->prepare("SELECT id,reseller_id,package_id,domain,status,amount FROM orders WHERE id=? FOR UPDATE"); $lock->execute([(int)$order['id']]); $o=$lock->fetch();
            if(!$o || $o['status']!=='pending'){ if($db->inTransaction())$db->rollBack(); continue; }
            $ls=$db->query("SELECT id,license_key FROM licenses WHERE status='available' AND reseller_id IS NULL ORDER BY id ASC LIMIT 1 FOR UPDATE"); $license=$ls->fetch();
            if(!$license){
                $db->rollBack();
                if (function_exists('telegram_notify')) telegram_notify("⏳ <b>LICENSE INVENTORY EMPTY</b>\nOrder: #".(int)$order['id']."\nPackage: ".e($order['name'])."\nDomain: ".e($order['domain'])."\nStatus: Pending manual license assignment");
                if (function_exists('notify_reseller')) notify_reseller((int)$order['reseller_id'],'order','Order pending manual license assignment','Order #'.$order['id'].' has been received, but license inventory is currently empty. Admin will assign a license manually.');
                continue;
            }
            $expires=null;
            if($order['billing_period']==='monthly') $expires=(new DateTimeImmutable('now'))->modify('+1 month')->format('Y-m-d H:i:s');
            elseif($order['billing_period']==='annual') $expires=(new DateTimeImmutable('now'))->modify('+1 year')->format('Y-m-d H:i:s');
            $up=$db->prepare("UPDATE licenses SET reseller_id=?,domain=?,status='active',purchase_date=CURDATE(),expires_at=?,package_id=?,updated_at=NOW() WHERE id=? AND status='available' AND reseller_id IS NULL");
            $up->execute([(int)$order['reseller_id'],$order['domain'],$expires,(int)$order['package_id'],(int)$license['id']]);
            if($up->rowCount()!==1) throw new RuntimeException('License became unavailable during fulfillment.');
            $doneOrder=$db->prepare("UPDATE orders SET license_id=?,status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'");
            $doneOrder->execute([(int)$license['id'],(int)$order['id']]);
            if($doneOrder->rowCount()!==1) throw new RuntimeException('Order status changed during fulfillment.');
            $db->prepare('INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)')->execute([(int)$license['id'],'auto_provision',null,$order['domain'],'Automatically assigned to reseller order #'.$order['id']]);
            $db->commit();
            platform_invoice_for_order((int)$order['id']);
            notify_reseller((int)$order['reseller_id'],'order','License provisioned','Order #'.$order['id'].' for '.$order['name'].' has been completed automatically. License: '.$license['license_key'].' Domain: '.$order['domain']);
            if (function_exists('telegram_notify')) telegram_notify("✅ <b>ORDER AUTO-COMPLETED</b>\nOrder: #".(int)$order['id']."\nPackage: ".e($order['name'])."\nDomain: ".e($order['domain'])."\nLicense: <code>".e($license['license_key'])."</code>");
            $done++;
        }catch(Throwable $e){ if($db->inTransaction())$db->rollBack(); error_log('SkyNoc auto fulfillment order #'.(int)$order['id'].': '.$e->getMessage()); }
    }
    if (function_exists('skynoc_hostname_renewals')) {
        try { skynoc_hostname_renewals(50); } catch (Throwable $e) { error_log('SkyNoc hostname renewal sweep: '.$e->getMessage()); }
    }
    return $done;
}
