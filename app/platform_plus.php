<?php
declare(strict_types=1);

function platform_coupon(string $code, float $baseAmount): ?array {
    global $db;
    $code = strtoupper(trim($code));
    if ($code === '' || $baseAmount <= 0) return null;
    $s=$db->prepare("SELECT * FROM coupons WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) AND (max_uses IS NULL OR used_count<max_uses) LIMIT 1 FOR UPDATE");
    $s->execute([$code]); $c=$s->fetch();
    if (!$c) return null;
    $discount = $c['type']==='fixed' ? (float)$c['value'] : $baseAmount*((float)$c['value']/100);
    $discount=max(0.0,min($baseAmount,$discount));
    return ['id'=>(int)$c['id'],'code'=>$c['code'],'type'=>$c['type'],'value'=>(float)$c['value'],'discount'=>round($discount,2)];
}

function platform_redeem_coupon(int $couponId,int $resellerId,int $orderId,float $discount): void {
    global $db;
    if ($couponId<=0 || $discount<=0) return;
    $db->prepare("INSERT INTO coupon_redemptions(coupon_id,reseller_id,order_id,amount) VALUES(?,?,?,?)")->execute([$couponId,$resellerId,$orderId,$discount]);
    $db->prepare("UPDATE coupons SET used_count=used_count+1 WHERE id=?")->execute([$couponId]);
}

function platform_invoice_for_order(int $orderId): ?int {
    global $db;
    $s=$db->prepare("SELECT i.id FROM invoices i WHERE i.order_id=? LIMIT 1"); $s->execute([$orderId]);
    $existing=$s->fetchColumn(); if($existing) return (int)$existing;
    $s=$db->prepare("SELECT o.*,p.name package_name,r.name reseller_name FROM orders o JOIN packages p ON p.id=o.package_id JOIN resellers r ON r.id=o.reseller_id WHERE o.id=? LIMIT 1");
    $s->execute([$orderId]); $o=$s->fetch(); if(!$o) return null;
    $number='SN-'.date('Ym').'-'.str_pad((string)$orderId,7,'0',STR_PAD_LEFT);
    $db->beginTransaction();
    try {
        $subtotal=(float)$o['amount']+(float)($o['coupon_discount']??0);
        $discount=(float)($o['coupon_discount']??0);
        $q=$db->prepare("INSERT INTO invoices(invoice_number,reseller_id,order_id,subtotal,discount,total,status,paid_at) VALUES(?,?,?,?,?,?, 'paid',NOW())");
        $q->execute([$number,$o['reseller_id'],$orderId,$subtotal,$discount,(float)$o['amount']]);
        $iid=(int)$db->lastInsertId();
        $db->prepare("INSERT INTO invoice_items(invoice_id,description,quantity,unit_price,amount) VALUES(?,?,?,?,?)")->execute([$iid,'SkyNoc '.$o['package_name'],1,$subtotal,$subtotal]);
        $db->commit(); return $iid;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); return null; }
}

function platform_license_expiry_sweep(): int {
    global $db;
    $s=$db->query("SELECT id,reseller_id,license_key,domain,expires_at FROM licenses WHERE status='active' AND expires_at IS NOT NULL AND expires_at<NOW()");
    $rows=$s->fetchAll(); $count=0;
    foreach($rows as $l){
        $db->beginTransaction();
        try{
            $q=$db->prepare("UPDATE licenses SET status='expired' WHERE id=? AND status='active'"); $q->execute([$l['id']]);
            if($q->rowCount()){
                $db->prepare("INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)")->execute([$l['id'],'expired',$l['domain'],$l['domain'],'Automatic expiry sweep']);
                notify_reseller((int)$l['reseller_id'],'license_expired','License expired','License '.$l['license_key'].' for '.($l['domain']?:'no domain').' has expired.');
                $count++;
            }
            $db->commit();
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();}
    }
    return $count;
}

function platform_expiry_reminders(): int {
    global $db; $count=0;
    foreach([30,7,1] as $days){
        $s=$db->prepare("SELECT id,reseller_id,license_key,domain,expires_at FROM licenses WHERE status='active' AND expires_at BETWEEN DATE_ADD(NOW(),INTERVAL ? DAY) - INTERVAL 1 HOUR AND DATE_ADD(NOW(),INTERVAL ? DAY) + INTERVAL 1 HOUR");
        $s->execute([$days,$days]);
        foreach($s->fetchAll() as $l){
            notify_reseller((int)$l['reseller_id'],'license_expiry_reminder','License expiry reminder','License '.$l['license_key'].' expires on '.$l['expires_at'].' (about '.$days.' day(s) remaining).');
            $count++;
        }
    } return $count;
}

function platform_invoice_number(int $id): string { return 'SN-'.date('Ym').'-'.str_pad((string)$id,7,'0',STR_PAD_LEFT); }