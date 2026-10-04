<?php
declare(strict_types=1);

function skynoc_auto_fulfill_orders(int $limit=25): int {
    global $db;
    $done=0;
    $q=$db->prepare("SELECT o.id,o.reseller_id,o.package_id,o.domain,p.name,p.billing_period FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.status='pending' ORDER BY o.id ASC LIMIT ?");
    $q->bindValue(1,max(1,min(100,$limit)),PDO::PARAM_INT);
    $q->execute();
    $orders=$q->fetchAll();

    foreach($orders as $order){
        try{
            $db->beginTransaction();
            $lock=$db->prepare("SELECT id,reseller_id,package_id,domain,status,amount FROM orders WHERE id=? FOR UPDATE");
            $lock->execute([(int)$order['id']]);
            $o=$lock->fetch();
            if(!$o||$o['status']!=='pending'){
                if($db->inTransaction())$db->rollBack();
                continue;
            }

            // Never assign a license belonging to another package. Legacy unassigned
            // inventory (package_id NULL) remains usable as a fallback.
            $ls=$db->prepare("SELECT id,license_key,package_id FROM licenses WHERE status='available' AND reseller_id IS NULL AND (package_id=? OR package_id IS NULL) ORDER BY (package_id IS NULL),id ASC LIMIT 1 FOR UPDATE");
            $ls->execute([(int)$order['package_id']]);
            $license=$ls->fetch();

            if(!$license){
                $db->rollBack();
                $message='Order #'.$order['id'].' is waiting for a '.$order['name'].' license to become available.';
                if(function_exists('notify_reseller')) notify_reseller((int)$order['reseller_id'],'order','Order pending',$message);
                if(function_exists('reseller_notify_all')) reseller_notify_all((int)$order['reseller_id'],'SkyNoc order pending',$message);
                if(function_exists('telegram_notify')) telegram_notify("⏳ <b>LICENSE INVENTORY EMPTY</b>\nOrder: #".(int)$order['id']."\nPackage: ".e($order['name'])."\nDomain: ".e($order['domain']));
                if(function_exists('discord_notify')) discord_notify('⏳ ORDER PENDING','Order #'.(int)$order['id'].' | '.$order['name'].' | '.$order['domain'].' | Inventory empty','warning');
                continue;
            }

            $expires=null;
            if($order['billing_period']==='monthly')$expires=(new DateTimeImmutable('now'))->modify('+1 month')->format('Y-m-d H:i:s');
            elseif($order['billing_period']==='annual')$expires=(new DateTimeImmutable('now'))->modify('+1 year')->format('Y-m-d H:i:s');

            $up=$db->prepare("UPDATE licenses SET reseller_id=?,domain=?,status='active',purchase_date=CURDATE(),expires_at=?,package_id=?,updated_at=NOW() WHERE id=? AND status='available' AND reseller_id IS NULL");
            $up->execute([(int)$order['reseller_id'],$order['domain'],$expires,(int)$order['package_id'],(int)$license['id']]);
            if($up->rowCount()!==1)throw new RuntimeException('License became unavailable.');

            $doneOrder=$db->prepare("UPDATE orders SET license_id=?,status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=? AND status='pending'");
            $doneOrder->execute([(int)$license['id'],(int)$order['id']]);
            if($doneOrder->rowCount()!==1)throw new RuntimeException('Order status changed.');

            $db->prepare('INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)')->execute([(int)$license['id'],'auto_provision',null,$order['domain'],'Automatically assigned to order #'.$order['id']]);
            $db->commit();

            platform_invoice_for_order((int)$order['id']);
            $message='Order #'.$order['id'].' completed successfully. License: '.$license['license_key'];
            if(function_exists('notify_reseller')) notify_reseller((int)$order['reseller_id'],'order','License provisioned',$message);
            if(function_exists('reseller_notify_all')) reseller_notify_all((int)$order['reseller_id'],'SkyNoc order completed',$message);
            if(function_exists('telegram_notify')) telegram_notify("✅ <b>ORDER COMPLETED</b>\nOrder: #".(int)$order['id']."\nLicense: <code>".e($license['license_key'])."</code>");
            if(function_exists('discord_notify')) discord_notify('✅ ORDER COMPLETED','Order #'.(int)$order['id'].' | '.$order['name'].' | '.$order['domain'].' | '.$license['license_key'],'success');
            $done++;
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            error_log('SkyNoc auto fulfillment order #'.(int)$order['id'].': '.$e->getMessage());
        }
    }

    if(function_exists('skynoc_hostname_renewals')){
        try{skynoc_hostname_renewals(50);}catch(Throwable $e){error_log('SkyNoc hostname renewal sweep: '.$e->getMessage());}
    }
    return $done;
}
