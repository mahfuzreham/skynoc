<?php
declare(strict_types=1);

function skynoc_renew_license(int $licenseId, int $months, float $amount, int $adminId): array {
 global $db;
 if($licenseId<1||!in_array($months,[1,12],true)||$amount<=0) throw new RuntimeException('License, renewal period and positive amount are required.');
 $db->beginTransaction();
 try{
  $q=$db->prepare('SELECT l.*,r.name reseller_name,r.email reseller_email FROM licenses l JOIN resellers r ON r.id=l.reseller_id WHERE l.id=? FOR UPDATE');$q->execute([$licenseId]);$l=$q->fetch();
  if(!$l||!$l['reseller_id']) throw new RuntimeException('Active reseller license not found.');
  if(!in_array($l['status'],['active','expired'],true)) throw new RuntimeException('License cannot be renewed in its current status.');
  $base=($l['expires_at']&&strtotime((string)$l['expires_at'])>time())?new DateTimeImmutable((string)$l['expires_at']):new DateTimeImmutable('now');
  $new=$base->modify('+'.$months.' month')->format('Y-m-d H:i:s');
  wallet_debit((int)$l['reseller_id'],$amount,'purchase','RENEW-'.$licenseId.'-'.time(),'License renewal #'.$licenseId,null,$adminId);
  $db->prepare('UPDATE licenses SET status="active",expires_at=?,updated_at=NOW() WHERE id=?')->execute([$new,$licenseId]);
  $db->prepare('INSERT INTO license_history(license_id,user_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?,?)')->execute([$licenseId,$adminId,'renewed',$l['domain'],$l['domain'],'Renewed '.$months.' month(s) for $'.number_format($amount,2)]);
  $db->commit();
  $msg='License '.$l['license_key'].' renewed until '.$new.'.';
  notify_reseller((int)$l['reseller_id'],'renewal','Renewal completed',$msg);
  if(function_exists('telegram_notify')) telegram_notify("🔄 <b>RENEWAL COMPLETED</b>\nLicense: <code>".e($l['license_key'])."</code>\nReseller: ".e($l['reseller_name'])."\nAmount: $".number_format($amount,2)."\nExpires: ".e($new));
  if(function_exists('discord_notify')) discord_notify('🔄 RENEWAL COMPLETED','License '.$l['license_key'].' | Reseller '.$l['reseller_name'].' | $'.number_format($amount,2).' | Expires '.$new,'success');
  return ['license_id'=>$licenseId,'license_key'=>$l['license_key'],'expires_at'=>$new];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
