<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['reseller']);
$s = $db->prepare('SELECT id,status FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]);
$r = $s->fetch();
if (!$r) exit('Reseller profile not found.');
$rid = (int)$r['id'];
$wallet = reseller_wallet($rid);
$level = reseller_level($rid);
$resellerStatus = (string)$r['status'];
$paymentMethods = payment_methods();
$msg = null;
$error = null;
$newApiKey = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'deposit_request') {
            $amount=(float)($_POST['amount'] ?? 0);
            $method=trim((string)($_POST['method'] ?? ''));
            $reference=trim((string)($_POST['reference'] ?? '')) ?: null;
            $note=trim((string)($_POST['note'] ?? '')) ?: null;
            if($method==='') throw new RuntimeException('Please select a payment method.');
            $paymentMethod = payment_method($method);
            if(!$paymentMethod || !(int)$paymentMethod['enabled']) throw new RuntimeException('This payment method is currently unavailable.');
            $minimumDeposit = payment_method_min($method, $resellerStatus==='pending');
            if($amount < $minimumDeposit) throw new RuntimeException('Minimum deposit for '.(string)$paymentMethod['name'].' is $'.number_format($minimumDeposit,2).'.');
            if ($method === 'USDT_BEP20') {
                if (!$reference) throw new RuntimeException('USDT TXID is required.');
                $verified = verify_bsc_usdt_tx($reference, number_format($amount, 8, '.', ''));
                $q=$db->prepare('SELECT id FROM deposit_requests WHERE tx_hash=? LIMIT 1');
                $q->execute([$verified['tx_hash']]);
                if($q->fetchColumn()) throw new RuntimeException('This TXID has already been used.');

                $db->beginTransaction();
                try {
                    $q=$db->prepare('SELECT status FROM resellers WHERE id=? FOR UPDATE');
                    $q->execute([$rid]);
                    $lockedStatus=$q->fetchColumn();
                    if($lockedStatus===false) throw new RuntimeException('Reseller account not found.');
                    $type=($lockedStatus==='pending') ? 'activation_deposit' : 'deposit';
                    $q=$db->prepare('INSERT INTO deposit_requests (reseller_id,amount,method,reference,note,status,network,tx_hash,block_number,from_address,to_address,token_contract,token_amount,verified_at) VALUES(?,?,?,?,?,"approved",?,?,?,?,?,?,?,NOW())');
                    $q->execute([$rid,$amount,'USDT_BEP20',$verified['tx_hash'],$note,'BSC / BEP20',$verified['tx_hash'],$verified['block_number'],$verified['from_address'],$verified['to_address'],$verified['token_contract'],$verified['token_amount']]);
                    $depositId=(int)$db->lastInsertId();
                    wallet_credit($rid,$amount,$type,'DEPOSIT-'.$depositId,'Verified USDT BEP20 deposit #'.$depositId,null,null);
                    if($lockedStatus==='pending') $db->prepare('UPDATE resellers SET status="active" WHERE id=?')->execute([$rid]);
                    $db->commit();
                } catch(Throwable $e) {
                    if($db->inTransaction()) $db->rollBack();
                    if($e instanceof PDOException && $e->getCode()==='23000') throw new RuntimeException('This TXID has already been used.');
                    throw $e;
                }
                $newBalance=reseller_wallet($rid);
                skynoc_deposit_telegram_send("💎 <b>USDT BEP20 DEPOSIT VERIFIED</b>\n\n👤 Reseller: ".e($u['name'])." (#".$rid.")\n🆔 Deposit: #".$depositId."\n💰 Amount: ".number_format($amount,2)." USDT\n🌐 Network: BSC / BEP20\n🔗 TXID: <code>".e($verified['tx_hash'])."</code>\n📤 From: <code>".e($verified['from_address'])."</code>\n📥 To: <code>".e($verified['to_address'])."</code>\n🔐 Confirmations: ".(int)$verified['confirmations']."\n✅ Status: VERIFIED\n💳 Wallet Balance: $".number_format($newBalance,2));
                $msg='USDT deposit verified on BSC. The full amount has been added to your wallet.';
            } else {
                $q=$db->prepare('INSERT INTO deposit_requests(reseller_id,amount,method,reference,note) VALUES(?,?,?,?,?)');
                $q->execute([$rid,$amount,$method,$reference,$note]);
                telegram_notify("💰 <b>NEW RESELLER DEPOSIT</b>\nReseller: ".e($u['name'])."\nRequest: ".(int)$db->lastInsertId()."\nAmount: $".number_format($amount,2)."\nMethod: ".e($method)."\nReference: ".e($reference ?: '-'));
                $msg='Deposit request submitted. Admin approval is required before the balance is credited.';
            }
        }

        if ($action === 'order_create') {
            if($resellerStatus !== 'active') throw new RuntimeException('Activate your reseller account before purchasing a package.');
            $packageId=(int)$_POST['package_id']; $domain=trim((string)($_POST['domain'] ?? ''));
            if($domain==='') throw new RuntimeException('WHMCS installation domain is required.');
            $db->beginTransaction();
            $q=$db->prepare('SELECT id,name,price,active FROM packages WHERE id=? AND active=1 FOR UPDATE'); $q->execute([$packageId]); $package=$q->fetch();
            if(!$package) throw new RuntimeException('Package is not available.');
            $q=$db->prepare('SELECT wallet_balance FROM resellers WHERE id=? FOR UPDATE'); $q->execute([$rid]); $balance=(float)$q->fetchColumn();
            $basePrice=reseller_package_price((float)$package['price'],reseller_level($rid)['assigned_level']); $coupon=platform_coupon((string)($_POST['coupon_code']??''),$basePrice); $couponDiscount=$coupon['discount']??0.0; $price=round(max(0,$basePrice-$couponDiscount),2);
            if($balance < $price){$db->rollBack(); if(function_exists('reseller_notify_low_balance')) reseller_notify_low_balance($rid,$balance,$price); throw new RuntimeException('Insufficient wallet balance. Please deposit funds first.');}
            $q=$db->prepare('INSERT INTO orders(reseller_id,package_id,domain,amount,status,source,coupon_code,coupon_discount) VALUES(?,?,?,?,"pending","portal",?,?)'); $q->execute([$rid,$packageId,$domain,$price,$coupon['code']??null,$couponDiscount]);
            $orderId=(int)$db->lastInsertId(); if($coupon) platform_redeem_coupon((int)$coupon['id'],$rid,$orderId,$couponDiscount); wallet_debit($rid,$price,'purchase','ORDER-'.$orderId,'Package purchase: '.$package['name'],$orderId,$u['id']); $db->commit(); platform_invoice_for_order($orderId);
            telegram_notify("🛒 <b>NEW PACKAGE ORDER</b>\\nReseller: ".e($u['name'])."\\nOrder: ".$orderId."\\nPackage: ".e($package['name'])."\\nAmount: $".number_format($price,2)."\\nDomain: ".e($domain));
            $orderMessage='Order #'.$orderId.' submitted successfully. Your wallet has been debited $'.number_format($price,2).'.'.($coupon ? ' Coupon '.$coupon['code'].' applied.' : ''); notify_reseller($rid,'order','Order submitted',$orderMessage); $msg='Order submitted. Your wallet has been reserved for this order.'.($coupon ? ' Coupon '.$coupon['code'].' applied.' : '');
        }

        if ($action === 'reissue') {
            $s = $db->prepare('SELECT id,license_key,domain,status,expires_at FROM licenses WHERE id=? AND reseller_id=?'); $s->execute([(int)$_POST['license_id'],$rid]); $l = $s->fetch();
            if (!$l || !trim($_POST['new_domain'])) throw new RuntimeException('License not found or new domain is empty.');
            $q = $db->prepare('INSERT INTO reissue_requests(license_id,reseller_id,current_domain,new_domain,reason,status) VALUES(?,?,?,?,?,"pending")'); $q->execute([$l['id'],$rid,$l['domain'],trim($_POST['new_domain']),trim($_POST['reason']) ?: null]); $id=(int)$db->lastInsertId();
            skynoc_notify_reissue_request(['id'=>$id,'reseller_id'=>$rid,'reseller_name'=>$u['name'],'reseller_email'=>$u['email'],'license_key'=>$l['license_key'],'current_domain'=>$l['domain'],'new_domain'=>trim($_POST['new_domain']),'reason'=>trim($_POST['reason']) ?: null,'status'=>'pending']); $msg='Reissue request submitted.';
        }

        if ($action === 'api_create') {
            if($resellerStatus!=='active') throw new RuntimeException('Activate your reseller account before creating API keys.');
            $plain='skynoc_'.bin2hex(random_bytes(24));
            $scopes='licenses:read,licenses:manage,reissue:create,reissue:read,packages:read,orders:create,orders:read,billing:notify';
            $s=$db->prepare('INSERT INTO api_keys(reseller_id,name,key_prefix,key_hash,scopes,expires_at) VALUES(?,?,?,?,?,?)'); $s->execute([$rid,trim($_POST['name']) ?: 'Reseller API Key',substr($plain,0,15),hash('sha256',$plain),$scopes,$_POST['expires_at'] ?: null]); $newApiKey=$plain; audit('reseller_api_key_created','api_keys',(int)$db->lastInsertId());
        }
        if ($action === 'api_revoke') {$id=(int)$_POST['id']; $db->prepare('UPDATE api_keys SET status="revoked" WHERE id=? AND reseller_id=?')->execute([$id,$rid]); audit('reseller_api_key_revoked','api_keys',$id); $msg='API key revoked.';}
        if ($action === 'ticket_create') {$subject=trim($_POST['subject']);$message=trim($_POST['message']);$priority=in_array($_POST['priority'] ?? '',['low','normal','high','urgent'],true)?$_POST['priority']:'normal';if($subject===''||$message==='')throw new RuntimeException('Subject and message are required.');$db->beginTransaction();$s=$db->prepare('INSERT INTO tickets(reseller_id,subject,status,priority) VALUES(? ,? ,"open",?)');$s->execute([$rid,$subject,$priority]);$tid=(int)$db->lastInsertId();$db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$tid,$u['id'],$message]);$db->commit();telegram_notify("🎫 <b>NEW SUPPORT TICKET</b>\nReseller: ".e($u['name'])."\nTicket: ".$tid."\nSubject: ".e($subject));$msg='Support ticket created.';}
        if ($action === 'ticket_reply') {$tid=(int)$_POST['ticket_id'];$message=trim($_POST['message']);if($message==='')throw new RuntimeException('Reply cannot be empty.');$s=$db->prepare('SELECT id,status,subject FROM tickets WHERE id=? AND reseller_id=?');$s->execute([$tid,$rid]);$ticket=$s->fetch();if(!$ticket)throw new RuntimeException('Ticket not found.');$db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$tid,$u['id'],$message]);$db->prepare('UPDATE tickets SET status="open" WHERE id=?')->execute([$tid]);$msg='Reply sent.';}
        if ($action === 'notification_read') {$id=(int)$_POST['id'];$db->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND reseller_id=?')->execute([$id,$rid]);}
    }
} catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); $error=$e->getMessage(); }

$wallet = reseller_wallet($rid); $s=$db->prepare('SELECT id,license_key,domain,status,expires_at FROM licenses WHERE reseller_id=? ORDER BY id DESC');$s->execute([$rid]);$licenses=$s->fetchAll();
$s=$db->prepare('SELECT rr.*,l.license_key FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id WHERE rr.reseller_id=? ORDER BY rr.id DESC LIMIT 50');$s->execute([$rid]);$reissues=$s->fetchAll();
$s=$db->prepare('SELECT id,name,key_prefix,scopes,status,last_used_at,expires_at,created_at FROM api_keys WHERE reseller_id=? ORDER BY id DESC');$s->execute([$rid]);$apiKeys=$s->fetchAll();
$s=$db->prepare('SELECT id,title,message,read_at,created_at FROM notifications WHERE reseller_id=? ORDER BY id DESC LIMIT 20');$s->execute([$rid]);$notifications=$s->fetchAll();
$s=$db->prepare('SELECT id,subject,status,priority,created_at,updated_at FROM tickets WHERE reseller_id=? ORDER BY id DESC LIMIT 30');$s->execute([$rid]);$tickets=$s->fetchAll();
$s=$db->query('SELECT id,name,description,price,client_limit,billing_period FROM packages WHERE active=1 ORDER BY sort_order,id');$packages=$s->fetchAll();
$s=$db->prepare('SELECT o.*,(SELECT id FROM invoices i WHERE i.order_id=o.id LIMIT 1) invoice_id,p.name package_name,l.license_key FROM orders o JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id WHERE o.reseller_id=? ORDER BY o.id DESC LIMIT 50');$s->execute([$rid]);$orders=$s->fetchAll();
$s=$db->prepare('SELECT id,amount,method,reference,status,review_note,network,tx_hash,created_at FROM deposit_requests WHERE reseller_id=? ORDER BY id DESC LIMIT 20');$s->execute([$rid]);$deposits=$s->fetchAll();
$ticketMessages=[];foreach($tickets as $t){$q=$db->prepare('SELECT tm.id,tm.message,tm.created_at,u.name,u.role FROM ticket_messages tm LEFT JOIN users u ON u.id=tm.user_id WHERE tm.ticket_id=? ORDER BY tm.id ASC');$q->execute([$t['id']]);$ticketMessages[$t['id']]=$q->fetchAll();}
$unreadCount=0;foreach($notifications as $n){if(empty($n['read_at']))$unreadCount++;}
