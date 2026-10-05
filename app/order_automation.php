<?php
declare(strict_types=1);

function skynoc_auto_fulfill_order(int $orderId): ?array {
    global $db;
    $ownTx=!$db->inTransaction();
    try {
        if($ownTx)$db->beginTransaction();
        $lock=$db->prepare("SELECT o.id,o.reseller_id,o.package_id,o.domain,o.status,o.amount,p.name,p.billing_period FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.id=? FOR UPDATE");
        $lock->execute([$orderId]);
        $order=$lock->fetch();
        if(!$order || $order['status']!=='pending'){
            if($ownTx && $db->inTransaction())$db->commit();
            return null;
        }

        // Assign only inventory matching this package. Legacy inventory with no package
        // remains a safe fallback for older licenses.
        $ls=$db->prepare("SELECT id,license_key,package_id FROM licenses WHERE status='available' AND reseller_id IS NULL AND (package_id=? OR package_id IS NULL) ORDER BY (package_id IS NULL),id ASC LIMIT 1 FOR UPDATE");
        $ls->execute([(int)$order['package_id']]);
        $license=$ls->fetch();
        if(!$license){
            if($ownTx && $db->inTransaction())$db->commit();
            return ['status'=>'pending','order_id'=>(int)$order['id'],'reseller_id'=>(int)$order['reseller_id'],'package_name'=>(string)$order['name'],'domain'=>(string)$order['domain']];
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

        $db->prepare('INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)')->execute([(int)$license['id'],'auto_provision',null,$order['domain'],'Automatically assigned to funded order #'.$order['id']]);
        if($ownTx)$db->commit();

        $result=['status'=>'completed','order_id'=>(int)$order['id'],'reseller_id'=>(int)$order['reseller_id'],'package_name'=>(string)$order['name'],'domain'=>(string)$order['domain'],'license_id'=>(int)$license['id'],'license_key'=>(string)$license['license_key']];
        if(function_exists('notify_reseller')) notify_reseller((int)$order['reseller_id'],'order','License provisioned','Order #'.$order['id'].' completed successfully. License: '.$license['license_key']);
        if(function_exists('reseller_notify_all')) reseller_notify_all((int)$order['reseller_id'],'SkyNoc order completed','Order #'.$order['id'].' completed successfully. License: '.$license['license_key']);
        if(function_exists('telegram_notify')) telegram_notify("✅ <b>ORDER COMPLETED</b>\n\n🆔 Order: #".(int)$order['id']."\n📦 Package: ".e($order['name'])."\n🌐 Domain: ".e($order['domain'])."\n🔑 License: <code>".e($license['license_key'])."</code>");
        if(function_exists('discord_notify')) discord_notify('✅ ORDER COMPLETED','Order #'.(int)$order['id'].' | '.$order['name'].' | '.$order['domain'].' | '.$license['license_key'],'success');
        return $result;
    }catch(Throwable $e){
        if($ownTx && $db->inTransaction())$db->rollBack();
        error_log('SkyNoc instant auto fulfillment order #'.$orderId.': '.$e->getMessage());
        return null;
    }
}

function skynoc_auto_fulfill_orders(int $limit=25): int {
    global $db;
    $done=0;
    $q=$db->prepare("SELECT o.id FROM orders o WHERE o.status='pending' ORDER BY o.id ASC LIMIT ?");
    $q->bindValue(1,max(1,min(100,$limit)),PDO::PARAM_INT);
    $q->execute();
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $orderId){
        $result=skynoc_auto_fulfill_order((int)$orderId);
        if(($result['status']??'')==='completed')$done++;
        elseif(($result['status']??'')==='pending'){
            $message='Order #'.(int)$orderId.' is waiting for a license to become available.';
            if(function_exists('notify_reseller')) notify_reseller((int)$result['reseller_id'],'order','Order pending',$message);
            if(function_exists('reseller_notify_all')) reseller_notify_all((int)$result['reseller_id'],'SkyNoc order pending',$message);
            if(function_exists('telegram_notify')) telegram_notify("⏳ <b>LICENSE INVENTORY EMPTY</b>\n\n🆔 Order: #".(int)$orderId."\n📦 Package: ".e($result['package_name'])."\n🌐 Domain: ".e($result['domain']),function_exists('skynoc_order_telegram_buttons')?skynoc_order_telegram_buttons((int)$orderId):null);
            if(function_exists('discord_notify')) discord_notify('⏳ ORDER PENDING','Order #'.(int)$orderId.' | '.$result['package_name'].' | '.$result['domain'].' | Inventory empty','warning');
        }
    }
    if(function_exists('skynoc_hostname_renewals')){
        try{skynoc_hostname_renewals(50);}catch(Throwable $e){error_log('SkyNoc hostname renewal sweep: '.$e->getMessage());}
    }
    return $done;
}
