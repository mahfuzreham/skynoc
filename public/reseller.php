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

                    $q=$db->prepare('INSERT INTO deposit_requests
                        (reseller_id,amount,method,reference,note,status,network,tx_hash,block_number,from_address,to_address,token_contract,token_amount,verified_at)
                        VALUES(?,?,?,?,?,"approved",?,?,?,?,?,?,?,NOW())');
                    $q->execute([
                        $rid,$amount,'USDT_BEP20',$verified['tx_hash'],$note,
                        'BSC / BEP20',$verified['tx_hash'],$verified['block_number'],
                        $verified['from_address'],$verified['to_address'],$verified['token_contract'],
                        $verified['token_amount']
                    ]);
                    $depositId=(int)$db->lastInsertId();

                    wallet_credit($rid,$amount,$type,'DEPOSIT-'.$depositId,'Verified USDT BEP20 deposit #'.$depositId,null,null);

                    if($lockedStatus==='pending'){
                        $db->prepare('UPDATE resellers SET status="active" WHERE id=?')->execute([$rid]);
                    }
                    $db->commit();
                } catch(Throwable $e) {
                    if($db->inTransaction()) $db->rollBack();
                    if($e instanceof PDOException && $e->getCode()==='23000') {
                        throw new RuntimeException('This TXID has already been used.');
                    }
                    throw $e;
                }

                $newBalance=reseller_wallet($rid);
                skynoc_deposit_telegram_send(
                    "💎 <b>USDT BEP20 DEPOSIT VERIFIED</b>\n\n"
                    . "👤 Reseller: ".e($u['name'])." (#".$rid.")\n"
                    . "🆔 Deposit: #".$depositId."\n"
                    . "💰 Amount: ".number_format($amount,2)." USDT\n"
                    . "🌐 Network: BSC / BEP20\n"
                    . "🔗 TXID: <code>".e($verified['tx_hash'])."</code>\n"
                    . "📤 From: <code>".e($verified['from_address'])."</code>\n"
                    . "📥 To: <code>".e($verified['to_address'])."</code>\n"
                    . "🔐 Confirmations: ".(int)$verified['confirmations']."\n"
                    . "✅ Status: VERIFIED\n"
                    . "💳 Wallet Balance: $".number_format($newBalance,2)
                );
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
            $packageId=(int)$_POST['package_id'];
            $domain=trim((string)($_POST['domain'] ?? ''));
            if($domain==='') throw new RuntimeException('WHMCS installation domain is required.');
            $db->beginTransaction();
            $q=$db->prepare('SELECT id,name,price,active FROM packages WHERE id=? AND active=1 FOR UPDATE');
            $q->execute([$packageId]);
            $package=$q->fetch();
            if(!$package) throw new RuntimeException('Package is not available.');
            $q=$db->prepare('SELECT wallet_balance FROM resellers WHERE id=? FOR UPDATE');
            $q->execute([$rid]);
            $balance=(float)$q->fetchColumn();
            $basePrice=reseller_package_price((float)$package['price'],reseller_level($rid)['assigned_level']);
            $coupon=platform_coupon((string)($_POST['coupon_code']??''),$basePrice);
            $couponDiscount=$coupon['discount']??0.0;
            $price=round(max(0,$basePrice-$couponDiscount),2);
            if($balance < $price) throw new RuntimeException('Insufficient wallet balance. Please deposit funds first.');
            $q=$db->prepare('INSERT INTO orders(reseller_id,package_id,domain,amount,status,source,coupon_code,coupon_discount) VALUES(?,?,?,?,"pending","portal",?,?)');
            $q->execute([$rid,$packageId,$domain,$price,$coupon['code']??null,$couponDiscount]);
            $orderId=(int)$db->lastInsertId();
            if($coupon) platform_redeem_coupon((int)$coupon['id'],$rid,$orderId,$couponDiscount);
            wallet_debit($rid,$price,'purchase','ORDER-'.$orderId,'Package purchase: '.$package['name'],$orderId,$u['id']);
            $db->commit();
            platform_invoice_for_order($orderId);
            telegram_notify("🛒 <b>NEW PACKAGE ORDER</b>\\nReseller: ".e($u['name'])."\\nOrder: ".$orderId."\\nPackage: ".e($package['name'])."\\nAmount: $".number_format($price,2)."\\nDomain: ".e($domain));
            $msg='Order submitted. Your wallet has been reserved for this order.'.($coupon ? ' Coupon '.$coupon['code'].' applied.' : '');
        }

        if ($action === 'reissue') {
            $s = $db->prepare('SELECT id,license_key,domain,status,expires_at FROM licenses WHERE id=? AND reseller_id=?');
            $s->execute([(int)$_POST['license_id'],$rid]);
            $l = $s->fetch();
            if (!$l || !trim($_POST['new_domain'])) throw new RuntimeException('License not found or new domain is empty.');
            $q = $db->prepare('INSERT INTO reissue_requests(license_id,reseller_id,current_domain,new_domain,reason,status) VALUES(?,?,?,?,?,"pending")');
            $q->execute([$l['id'],$rid,$l['domain'],trim($_POST['new_domain']),trim($_POST['reason']) ?: null]);
            $id=(int)$db->lastInsertId();
            $notify = [
                'id'=>$id,'reseller_id'=>$rid,'reseller_name'=>$u['name'],'reseller_email'=>$u['email'],
                'license_key'=>$l['license_key'],'current_domain'=>$l['domain'],'new_domain'=>trim($_POST['new_domain']),
                'reason'=>trim($_POST['reason']) ?: null,'status'=>'pending'
            ];
            skynoc_notify_reissue_request($notify);
            $msg='Reissue request submitted.';
        }

        if ($action === 'api_create') {
            if($resellerStatus!=='active') throw new RuntimeException('Activate your reseller account before creating API keys.');
            $plain='skynoc_'.bin2hex(random_bytes(24));
            $scopes='licenses:read,licenses:manage,reissue:create,reissue:read,packages:read,orders:create,orders:read';
            $s=$db->prepare('INSERT INTO api_keys(reseller_id,name,key_prefix,key_hash,scopes,expires_at) VALUES(?,?,?,?,?,?)');
            $s->execute([$rid,trim($_POST['name']) ?: 'Reseller API Key',substr($plain,0,15),hash('sha256',$plain),$scopes,$_POST['expires_at'] ?: null]);
            $newApiKey=$plain;
            audit('reseller_api_key_created','api_keys',(int)$db->lastInsertId());
        }

        if ($action === 'api_revoke') {
            $id=(int)$_POST['id'];
            $db->prepare('UPDATE api_keys SET status="revoked" WHERE id=? AND reseller_id=?')->execute([$id,$rid]);
            audit('reseller_api_key_revoked','api_keys',$id);
            $msg='API key revoked.';
        }

        if ($action === 'ticket_create') {
            $subject=trim($_POST['subject']);
            $message=trim($_POST['message']);
            $priority=in_array($_POST['priority'] ?? '',['low','normal','high','urgent'],true)?$_POST['priority']:'normal';
            if($subject===''||$message==='') throw new RuntimeException('Subject and message are required.');
            $db->beginTransaction();
            $s=$db->prepare('INSERT INTO tickets(reseller_id,subject,status,priority) VALUES(? ,? ,"open",?)');
            $s->execute([$rid,$subject,$priority]);
            $tid=(int)$db->lastInsertId();
            $db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$tid,$u['id'],$message]);
            $db->commit();
            telegram_notify("🎫 <b>NEW SUPPORT TICKET</b>\nReseller: ".e($u['name'])."\nTicket: ".$tid."\nSubject: ".e($subject));
            $msg='Support ticket created.';
        }

        if ($action === 'ticket_reply') {
            $tid=(int)$_POST['ticket_id'];
            $message=trim($_POST['message']);
            if($message==='') throw new RuntimeException('Reply cannot be empty.');
            $s=$db->prepare('SELECT id,status,subject FROM tickets WHERE id=? AND reseller_id=?');
            $s->execute([$tid,$rid]);
            $ticket=$s->fetch();
            if(!$ticket) throw new RuntimeException('Ticket not found.');
            $db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message) VALUES(?,?,?)')->execute([$tid,$u['id'],$message]);
            $db->prepare('UPDATE tickets SET status="open" WHERE id=?')->execute([$tid]);
            $msg='Reply sent.';
        }

        if ($action === 'notification_read') {
            $id=(int)$_POST['id'];
            $db->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND reseller_id=?')->execute([$id,$rid]);
        }
    }
} catch(Throwable $e) {
    if($db->inTransaction()) $db->rollBack();
    $error=$e->getMessage();
}

$wallet = reseller_wallet($rid);
$s=$db->prepare('SELECT id,license_key,domain,status,expires_at FROM licenses WHERE reseller_id=? ORDER BY id DESC');
$s->execute([$rid]); $licenses=$s->fetchAll();
$s=$db->prepare('SELECT rr.*,l.license_key FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id WHERE rr.reseller_id=? ORDER BY rr.id DESC LIMIT 50');
$s->execute([$rid]); $reissues=$s->fetchAll();
$s=$db->prepare('SELECT id,name,key_prefix,scopes,status,last_used_at,expires_at,created_at FROM api_keys WHERE reseller_id=? ORDER BY id DESC');
$s->execute([$rid]); $apiKeys=$s->fetchAll();
$s=$db->prepare('SELECT id,title,message,read_at,created_at FROM notifications WHERE reseller_id=? ORDER BY id DESC LIMIT 20');
$s->execute([$rid]); $notifications=$s->fetchAll();
$s=$db->prepare('SELECT id,subject,status,priority,created_at,updated_at FROM tickets WHERE reseller_id=? ORDER BY id DESC LIMIT 30');
$s->execute([$rid]); $tickets=$s->fetchAll();
$s=$db->query('SELECT id,name,description,price,client_limit,billing_period FROM packages WHERE active=1 ORDER BY sort_order,id'); $packages=$s->fetchAll();
$s=$db->prepare('SELECT o.*,(SELECT id FROM invoices i WHERE i.order_id=o.id LIMIT 1) invoice_id,p.name package_name,l.license_key FROM orders o JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id WHERE o.reseller_id=? ORDER BY o.id DESC LIMIT 50'); $s->execute([$rid]); $orders=$s->fetchAll();
$s=$db->prepare('SELECT id,amount,method,reference,status,review_note,network,tx_hash,created_at FROM deposit_requests WHERE reseller_id=? ORDER BY id DESC LIMIT 20'); $s->execute([$rid]); $deposits=$s->fetchAll();
$ticketMessages=[];
foreach($tickets as $t){$q=$db->prepare('SELECT tm.id,tm.message,tm.created_at,u.name,u.role FROM ticket_messages tm LEFT JOIN users u ON u.id=tm.user_id WHERE tm.ticket_id=? ORDER BY tm.id ASC');$q->execute([$t['id']]);$ticketMessages[$t['id']]=$q->fetchAll();}
$unreadCount=0; foreach($notifications as $n){ if(empty($n['read_at'])) $unreadCount++; }
$activeLicenses=0; foreach($licenses as $l){ if($l['status']==='active') $activeLicenses++; }
$openTickets=0; foreach($tickets as $t){ if($t['status']!=='closed') $openTickets++; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SkyNoc Reseller Dashboard</title>
<style>
:root{--brand:#465fff;--brand-dark:#3646d9;--navy:#101828;--ink:#101828;--muted:#667085;--line:#e4e7ec;--bg:#f8fafc;--card:#fff;--success:#12b76a;--warning:#f79009;--danger:#f04438;--shadow:0 2px 8px rgba(16,24,40,.04),0 12px 28px rgba(16,24,40,.05)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px}.app{min-height:100vh}.sidebar{position:fixed;left:0;top:0;bottom:0;width:252px;background:#101828;color:#c9d1df;z-index:50;border-right:1px solid #1d2939;overflow:auto}.brand{height:86px;display:flex;align-items:center;gap:12px;padding:18px 22px;border-bottom:1px solid #1d2939;color:#fff}.brand-mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#6172ff,#3c4de8);display:grid;place-items:center;font-size:21px;font-weight:900;box-shadow:0 10px 24px rgba(70,95,255,.25)}.brand strong{font-size:18px;display:block}.brand small{display:block;color:#98a2b3;margin-top:2px}.nav-title{padding:24px 22px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.11em;color:#667085;font-weight:800}.side-nav{padding:0 12px 22px}.side-link{display:flex;align-items:center;gap:11px;color:#98a2b3;text-decoration:none;padding:11px 12px;border-radius:9px;margin:3px 0;font-weight:650}.side-link:hover,.side-link.active{background:#1d2b58;color:#fff}.side-link.active{box-shadow:inset 3px 0 0 var(--brand)}.side-link svg{width:17px;height:17px;flex:0 0 17px}.main{margin-left:252px;min-height:100vh}.topbar{height:86px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 34px;position:sticky;top:0;z-index:30}.top-title{font-size:20px;font-weight:750}.top-actions{display:flex;align-items:center;gap:10px}.top-actions a{color:#344054;text-decoration:none}.pill{border:1px solid var(--line);background:#fff;border-radius:9px;padding:10px 13px;font-weight:650}.mobile-menu{display:none;border:0;background:transparent;font-size:23px;width:auto;margin:0;padding:4px}.content{max-width:1440px;margin:0 auto;padding:30px 34px 60px}.eyebrow{color:var(--brand);font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.hero{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}.hero h1{font-size:31px;letter-spacing:-.03em;margin:5px 0 6px}.hero p{color:var(--muted);margin:0;max-width:720px}.hero-actions{display:flex;gap:9px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid transparent;border-radius:9px;padding:10px 14px;background:var(--brand);color:#fff;text-decoration:none;font-weight:750;cursor:pointer;width:auto;margin:0}.btn:hover{background:var(--brand-dark)}.btn-secondary{background:#fff;color:#344054;border-color:var(--line)}.btn-danger{background:#fff;color:#b42318;border-color:#fecdca}.btn-sm{padding:7px 10px;font-size:12px}.flash{padding:13px 15px;border-radius:10px;margin:0 0 20px;border:1px solid}.flash.success{background:#ecfdf3;color:#067647;border-color:#abefc6}.flash.error{background:#fef3f2;color:#b42318;border-color:#fecdca}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:26px}.stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:var(--shadow);position:relative;overflow:hidden}.stat:after{content:"";position:absolute;width:90px;height:90px;border-radius:50%;background:#eef2ff;right:-34px;bottom:-42px}.stat-top{display:flex;justify-content:space-between;align-items:center;color:#667085;font-weight:650}.stat-icon{width:38px;height:38px;border-radius:10px;background:#eef2ff;color:var(--brand);display:grid;place-items:center;font-weight:800}.stat-value{font-size:28px;font-weight:850;margin-top:14px;letter-spacing:-.03em}.stat-note{color:#98a2b3;margin-top:4px;font-size:12px}.section{margin-top:30px;scroll-margin-top:105px}.section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 13px}.section-head h2{margin:0;font-size:20px;letter-spacing:-.02em}.section-head p{margin:4px 0 0;color:var(--muted)}.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:20px;margin-bottom:16px}.card-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:15px}.card-head h3{margin:0;font-size:16px}.muted{color:var(--muted)}.level-card{background:linear-gradient(135deg,#fff,#f7f8ff)}.level-main{display:flex;justify-content:space-between;gap:20px;align-items:center}.level-name{font-size:19px;font-weight:800}.level-meta{color:var(--muted);margin-top:5px}.progress{height:8px;background:#eaecf0;border-radius:99px;overflow:hidden;margin-top:15px}.progress>span{display:block;height:100%;background:var(--brand);border-radius:99px}.badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:800;text-transform:capitalize}.badge-success{background:#ecfdf3;color:#067647}.badge-warning{background:#fffaeb;color:#b54708}.badge-danger{background:#fef3f2;color:#b42318}.badge-neutral{background:#f2f4f7;color:#475467}.badge-brand{background:#eef2ff;color:#3538cd}.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.field.full{grid-column:1/-1}.field label{display:block;font-size:12px;font-weight:750;color:#344054;margin:0 0 6px}.field input,.field select,.field textarea,input,select,textarea{width:100%;border:1px solid #d0d5dd;background:#fff;border-radius:8px;padding:10px 12px;font:inherit;color:#101828;outline:none}.field input:focus,.field select:focus,.field textarea:focus,input:focus,select:focus,textarea:focus{border-color:#98a2b3;box-shadow:0 0 0 3px #eef2ff}.field textarea{min-height:95px;resize:vertical}.form-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:5px}.package-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.package{border:1px solid var(--line);border-radius:12px;padding:17px;background:#fff}.package h3{margin:0 0 5px}.package p{color:var(--muted);min-height:38px}.price{font-size:24px;font-weight:850}.old{color:#98a2b3;text-decoration:line-through;font-size:12px;margin-right:5px}.package-meta{display:flex;justify-content:space-between;color:#667085;font-size:12px;margin-top:12px}.table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px;background:#fff}.table{width:100%;border-collapse:collapse;min-width:760px}.table th{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#667085;background:#f9fafb}.table th,.table td{padding:12px 14px;border-bottom:1px solid #eef2f6;text-align:left;vertical-align:middle}.table tr:last-child td{border-bottom:0}.strong{font-weight:750}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}.empty{text-align:center;color:#98a2b3;padding:28px!important}.notice{border:1px solid var(--line);border-radius:11px;padding:13px;background:#fff;margin:9px 0}.notice.unread{border-left:3px solid var(--brand);background:#f8f9ff}.notice-title{font-weight:750}.notice small{color:#98a2b3}.ticket-message{padding:12px;background:#f8fafc;border-radius:9px;margin:7px 0}.secret{background:#fffaeb;border:1px solid #fedf89;color:#93370d;border-radius:10px;padding:14px;word-break:break-all}.empty-state{text-align:center;padding:30px;color:#98a2b3}.footer{padding:25px 0;color:#98a2b3;font-size:12px;text-align:center}.mobile-overlay{display:none;position:fixed;inset:0;background:rgba(16,24,40,.5);z-index:40}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,1fr)}.package-grid{grid-template-columns:1fr 1fr}.grid-3{grid-template-columns:1fr 1fr}}
@media(max-width:820px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}.mobile-overlay.open{display:block}.main{margin-left:0}.mobile-menu{display:inline-block}.topbar{padding:0 18px;height:72px}.content{padding:23px 18px 45px}.hero{align-items:flex-start;flex-direction:column}.hero h1{font-size:26px}.grid-2,.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.package-grid,.grid-3{grid-template-columns:1fr}.top-actions .hide-mobile{display:none}}
@media(max-width:560px){.stats{grid-template-columns:1fr}.stat-value{font-size:25px}.content{padding:20px 13px 40px}.hero-actions{width:100%}.hero-actions .btn{flex:1}.level-main{align-items:flex-start;flex-direction:column}.card{padding:15px}}
</style>
</head>
<body>
<div class="app">
<aside class="sidebar" id="sidebar">
  <div class="brand"><div class="brand-mark">S</div><div><strong>SkyNoc</strong><small>Reseller Platform</small></div></div>
  <div class="nav-title">Workspace</div>
  <nav class="side-nav">
    <a class="side-link active" href="#overview"><span>▦</span>Dashboard</a>
    <a class="side-link" href="#packages"><span>◇</span>Packages</a>
    <a class="side-link" href="#orders"><span>≡</span>Orders</a>
    <a class="side-link" href="#licenses"><span>▥</span>My Licenses</a>
    <a class="side-link" href="#wallet"><span>◫</span>Wallet & Deposits</a>
    <a class="side-link" href="#reissue"><span>↗</span>Reissue</a>
  </nav>
  <div class="nav-title">Account</div>
  <nav class="side-nav">
    <a class="side-link" href="/reseller/levels"><span>★</span>Reseller Levels</a>
    <a class="side-link" href="/reseller/white-label"><span>◇</span>White-label</a>
    <a class="side-link" href="#api"><span>⌁</span>API Access</a>
    <a class="side-link" href="#notifications"><span>◉</span>Notifications<?php if($unreadCount):?><span class="badge badge-brand" style="margin-left:auto"><?=$unreadCount?></span><?php endif;?></a>
    <a class="side-link" href="#support"><span>?</span>Support</a>
    <a class="side-link" href="/security"><span>⌾</span>Security</a>
  </nav>
  <div class="nav-title">Session</div>
  <nav class="side-nav"><a class="side-link" href="/logout"><span>↪</span>Sign out</a></nav>
</aside>
<div class="mobile-overlay" id="overlay"></div>
<main class="main">
<header class="topbar"><div style="display:flex;align-items:center;gap:12px"><button class="mobile-menu" id="menuBtn" aria-label="Menu">☰</button><div><div class="top-title">Reseller Dashboard</div></div></div><div class="top-actions"><a class="pill hide-mobile" href="/reseller/levels">Level <?=$level['assigned_level']?> · <?=e($level['name'])?></a><a class="pill" href="/security">Security</a><a class="pill hide-mobile" href="/logout">Sign out</a></div></header>
<div class="content" id="overview">
  <div class="hero"><div><div class="eyebrow">SkyNoc Reseller Portal</div><h1>Welcome back, <?=e($u['name'])?></h1><p>Manage your WHMCS license business, wallet, orders, reissues and API access from one place.</p></div><div class="hero-actions"><a class="btn btn-secondary" href="/reseller/levels">View levels</a><?php if($resellerStatus==='active'):?><a class="btn" href="#packages">Buy license</a><?php endif;?></div></div>
  <?php if($msg):?><div class="flash success">✓ <?=e($msg)?></div><?php endif;?>
  <?php if($error):?><div class="flash error">! <?=e($error)?></div><?php endif;?>

  <div class="stats">
    <div class="stat"><div class="stat-top"><span>Wallet balance</span><span class="stat-icon">$</span></div><div class="stat-value">$<?=number_format($wallet,2)?></div><div class="stat-note">Available for package purchases</div></div>
    <div class="stat"><div class="stat-top"><span>Active licenses</span><span class="stat-icon">✓</span></div><div class="stat-value"><?=$activeLicenses?></div><div class="stat-note">Your current license inventory</div></div>
    <div class="stat"><div class="stat-top"><span>Reseller level</span><span class="stat-icon">★</span></div><div class="stat-value">L<?=$level['assigned_level']?></div><div class="stat-note"><?=e($level['name'])?></div></div>
    <div class="stat"><div class="stat-top"><span>Open support</span><span class="stat-icon">?</span></div><div class="stat-value"><?=$openTickets?></div><div class="stat-note"><?=$unreadCount?> unread notification<?=$unreadCount===1?'':'s'?></div></div>
  </div>

  <section class="section" id="level"><div class="card level-card"><div class="level-main"><div><div class="eyebrow">Reseller level</div><div class="level-name">Level <?=$level['assigned_level']?> · <?=e($level['name'])?></div><div class="level-meta"><?=$level['active']?> active license<?=$level['active']===1?'':'s'?> · <?=number_format(reseller_level_discount($level['assigned_level']),2)?>% package discount</div></div><a class="btn btn-secondary" href="/reseller/levels">Level details</a></div><?php if($level['assigned_level']<5):?><div class="progress"><span style="width:<?=max(0,min(100,(float)$level['progress']))?>%"></span></div><div class="level-meta"><b><?=$level['needed']?></b> more active license<?=$level['needed']===1?'':'s'?> to reach Level <?=$level['next_level']?> · <?=e($level['next_name'])?></div><?php else:?><div class="level-meta" style="margin-top:15px">You reached the Top Reseller level.</div><?php endif;?></div></section>

  <section class="section" id="packages"><div class="section-head"><div><h2>License packages</h2><p>Admin-created packages available for manual reseller ordering.</p></div><span class="badge badge-brand"><?=count($packages)?> available</span></div>
    <div class="package-grid">
      <?php foreach($packages as $p): $discounted=reseller_package_price((float)$p['price'],$level['assigned_level']); ?>
      <div class="package"><div class="card-head"><h3><?=e($p['name'])?></h3><span class="badge badge-success">Available</span></div><p><?=e($p['description'] ?: 'WHMCS license package')?></p><div><span class="old">$<?=number_format((float)$p['price'],2)?></span><span class="price">$<?=number_format($discounted,2)?></span></div><div class="package-meta"><span><?=e((string)($p['client_limit'] ?? 'Unlimited'))?> clients</span><span><?=e($p['billing_period'])?></span></div></div>
      <?php endforeach; if(!$packages):?><div class="card empty-state" style="grid-column:1/-1">No active packages are available right now.</div><?php endif;?>
    </div>
  </section>

  <?php if($resellerStatus==='active'):?><section class="section" id="buy"><div class="section-head"><div><h2>Place an order</h2><p>Your level discount is applied before the wallet is charged.</p></div></div><div class="card"><form method="post"><div class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="order_create"><div class="field"><label>License package</label><select name="package_id" required><option value="">Choose a package</option><?php foreach($packages as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?> · $<?=number_format(reseller_package_price((float)$p['price'],$level['assigned_level']),2)?> / <?=e($p['billing_period'])?></option><?php endforeach;?></select></div><div class="field"><label>WHMCS installation domain</label><input name="domain" placeholder="billing.example.com" required></div><div class="field"><label>Coupon code <span class="muted">(optional)</span></label><input name="coupon_code" placeholder="Enter coupon"></div><div class="field" style="display:flex;align-items:flex-end"><button class="btn" type="submit">Buy with wallet →</button></div></div></form></div></section><?php endif;?>

  <section class="section" id="wallet"><div class="section-head"><div><h2>Wallet & deposits</h2><p>Fund your wallet for package purchases. Approved deposits are credited in full.</p></div><span class="badge <?=$resellerStatus==='active'?'badge-success':'badge-warning'?>"><?=e($resellerStatus)?></span></div>
    <?php if($resellerStatus!=='active'):?><div class="card"><div class="card-head"><div><h3>Activate reseller account</h3><div class="muted">Minimum activation deposit is $15.00 and the approved amount becomes wallet balance.</div></div><span class="badge badge-warning">Activation required</span></div><?php else:?><div class="card"><div class="card-head"><div><h3>Add funds</h3><div class="muted">Choose an enabled payment method below.</div></div><strong>$<?=number_format($wallet,2)?></strong></div><?php endif;?><form method="post"><div class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="deposit_request"><div class="field"><label>Amount</label><input name="amount" type="number" step="0.01" min="<?= $resellerStatus==='active' ? '0.01' : '15' ?>" value="<?= $resellerStatus==='active' ? '' : '15.00' ?>" placeholder="0.00" required></div><div class="field"><label>Payment method</label><select name="method" required><option value="">Select method</option><?php foreach($paymentMethods as $pm): if(!(int)$pm['enabled']) continue; $min=(float)$pm['min_deposit']; if($resellerStatus==='pending') $min=max($min,15); ?><option value="<?=e($pm['code'])?>"><?=e($pm['name'])?> · min $<?=number_format($min,2)?></option><?php endforeach;?></select></div><div class="field"><label>Reference / TXID</label><input name="reference" placeholder="USDT TXID or payment reference"></div><div class="field"><label>Note <span class="muted">(optional)</span></label><input name="note" placeholder="Payment note"></div><div class="field full"><div class="form-actions"><button class="btn" type="submit"><?= $resellerStatus==='active' ? 'Submit deposit' : 'Submit activation deposit' ?></button></div></div></div></form></div>
    <div class="section-head" style="margin-top:20px"><div><h2>Deposit history</h2></div></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Amount</th><th>Method</th><th>Network</th><th>Reference / TXID</th><th>Status</th><th>Date</th></tr></thead><tbody><?php if(!$deposits):?><tr><td colspan="7" class="empty">No deposits yet.</td></tr><?php endif;?><?php foreach($deposits as $d): $dc=$d['status']==='approved'?'badge-success':($d['status']==='pending'?'badge-warning':'badge-danger');?><tr><td>#<?=$d['id']?></td><td class="strong">$<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['method'])?></td><td><?=e($d['network'] ?: '-')?></td><td class="mono"><?=e($d['tx_hash'] ?: ($d['reference'] ?: '-'))?></td><td><span class="badge <?=$dc?>"><?=e($d['status'])?></span></td><td><?=e($d['created_at'])?></td></tr><?php endforeach;?></tbody></table></div>
  </section>

  <section class="section" id="orders"><div class="section-head"><div><h2>Orders</h2><p>Track package purchases and invoice status.</p></div><span class="badge badge-brand"><?=count($orders)?> records</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Order</th><th>Package</th><th>Domain</th><th>Amount</th><th>Status</th><th>Invoice</th><th>Created</th></tr></thead><tbody><?php if(!$orders):?><tr><td colspan="7" class="empty">No orders yet.</td></tr><?php endif;?><?php foreach($orders as $o):$oc=$o['status']==='completed'?'badge-success':($o['status']==='pending'||$o['status']==='processing'?'badge-warning':'badge-danger');?><tr><td class="strong">#<?=$o['id']?></td><td><?=e($o['package_name'])?></td><td><?=e($o['domain'])?></td><td>$<?=number_format((float)$o['amount'],2)?></td><td><span class="badge <?=$oc?>"><?=e($o['status'])?></span></td><td><?=!empty($o['invoice_id'])?'<a href="/invoice?id='.$o['invoice_id'].'" class="btn btn-secondary btn-sm">View invoice</a>':'-'?></td><td><?=e($o['created_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>

  <section class="section" id="licenses"><div class="section-head"><div><h2>My licenses</h2><p>Licenses assigned to your reseller account.</p></div><span class="badge badge-success"><?=$activeLicenses?> active</span></div><div class="table-wrap"><table class="table"><thead><tr><th>License</th><th>Domain</th><th>Status</th><th>Expires</th></tr></thead><tbody><?php if(!$licenses):?><tr><td colspan="4" class="empty">No licenses assigned yet.</td></tr><?php endif;?><?php foreach($licenses as $l):$lc=$l['status']==='active'?'badge-success':($l['status']==='suspended'?'badge-warning':'badge-neutral');?><tr><td class="mono strong"><?=e($l['license_key'])?></td><td><?=e($l['domain'] ?: '-')?></td><td><span class="badge <?=$lc?>"><?=e($l['status'])?></span></td><td><?=e($l['expires_at'] ?: '-')?></td></tr><?php endforeach;?></tbody></table></div></section>

  <section class="section" id="reissue"><div class="section-head"><div><h2>License reissue</h2><p>Request a domain change and track the review status.</p></div></div><div class="grid-2"><div class="card"><div class="card-head"><h3>Request reissue</h3></div><form method="post"><div class="field"><label>License</label><select name="license_id" required><?php foreach($licenses as $l):?><option value="<?=$l['id']?>"><?=e($l['license_key'].' — '.($l['domain'] ?: 'No domain'))?></option><?php endforeach;?></select></div><div class="field"><label>New domain</label><input name="new_domain" placeholder="new.example.com" required></div><div class="field" style="margin-top:12px"><label>Reason</label><textarea name="reason" placeholder="Why do you need this reissue?"></textarea></div><div class="form-actions"><input type="hidden" name="action" value="reissue"><?=csrf_field()?><button class="btn" type="submit">Submit reissue</button></div></form></div><div><div class="section-head"><div><h2>Reissue history</h2></div></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>License</th><th>New domain</th><th>Status</th></tr></thead><tbody><?php if(!$reissues):?><tr><td colspan="4" class="empty">No reissue requests.</td></tr><?php endif;?><?php foreach($reissues as $rr):$rc=$rr['status']==='completed'?'badge-success':($rr['status']==='rejected'?'badge-danger':'badge-warning');?><tr><td>#<?=$rr['id']?></td><td class="mono"><?=e($rr['license_key'])?></td><td><?=e($rr['new_domain'])?></td><td><span class="badge <?=$rc?>"><?=e($rr['status'])?></span></td></tr><?php endforeach;?></tbody></table></div></div></div></section>

  <section class="section" id="api"><div class="section-head"><div><h2>API access</h2><p>Connect your WHMCS module or automation to SkyNoc securely.</p></div><a class="btn btn-secondary" href="/api-docs">API documentation</a></div><?php if($newApiKey):?><div class="secret"><b>New API key — copy it now.</b><br><span class="mono"><?=e($newApiKey)?></span><div style="margin-top:6px;font-size:12px">For security, the full key will not be shown again.</div></div><?php endif;?><div class="grid-2"><div class="card"><div class="card-head"><h3>Generate API key</h3></div><form method="post"><div class="field"><label>Key name</label><input name="name" placeholder="WHMCS production"></div><div class="field" style="margin-top:10px"><label>Expiry <span class="muted">(optional)</span></label><input name="expires_at" type="datetime-local"></div><div class="form-actions"><input type="hidden" name="action" value="api_create"><?=csrf_field()?><button class="btn" type="submit">Generate key</button></div></form></div><div class="card"><div class="card-head"><h3>Security</h3></div><p class="muted">API keys are hashed at rest. Store the full key in a password manager or your WHMCS module configuration. Never post it in tickets or public repositories.</p><a class="btn btn-secondary" href="/security">Security settings</a></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Status</th><th>Last used</th><th>Expires</th><th>Action</th></tr></thead><tbody><?php if(!$apiKeys):?><tr><td colspan="7" class="empty">No API keys created.</td></tr><?php endif;?><?php foreach($apiKeys as $k):?><tr><td class="strong"><?=e($k['name'])?></td><td class="mono"><?=e($k['key_prefix'])?>…</td><td class="mono"><?=e($k['scopes'])?></td><td><span class="badge <?=$k['status']==='active'?'badge-success':'badge-danger'?>"><?=e($k['status'])?></span></td><td><?=e($k['last_used_at'] ?: '-')?></td><td><?=e($k['expires_at'] ?: '-')?></td><td><?php if($k['status']==='active'):?><form method="post" onsubmit="return confirm('Revoke this API key?');"><?=csrf_field()?><input type="hidden" name="action" value="api_revoke"><input type="hidden" name="id" value="<?=$k['id']?>"><button class="btn btn-danger btn-sm" type="submit">Revoke</button></form><?php else:?><span class="muted">—</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>

  <section class="section" id="notifications"><div class="section-head"><div><h2>Notifications</h2><p>Order, wallet and platform updates.</p></div><span class="badge badge-brand"><?=$unreadCount?> unread</span></div><div class="card"><?php if(!$notifications):?><div class="empty-state">No notifications.</div><?php endif;?><?php foreach($notifications as $n):?><div class="notice <?=$n['read_at']?'':'unread'?>"><div class="notice-title"><?=e($n['title'])?></div><div class="muted" style="margin-top:4px"><?=nl2br(e($n['message']))?></div><small><?=e($n['created_at'])?></small><?php if(!$n['read_at']):?><form method="post" style="margin-top:9px"><?=csrf_field()?><input type="hidden" name="action" value="notification_read"><input type="hidden" name="id" value="<?=$n['id']?>"><button class="btn btn-secondary btn-sm" type="submit">Mark as read</button></form><?php endif;?></div><?php endforeach;?></div></section>

  <section class="section" id="support"><div class="section-head"><div><h2>Support</h2><p>Create a ticket or continue an existing conversation.</p></div></div><div class="grid-2"><div class="card"><div class="card-head"><h3>Open support ticket</h3></div><form method="post"><div class="field"><label>Subject</label><input name="subject" placeholder="License / payment / technical issue" required></div><div class="form-grid" style="margin-top:10px"><div class="field"><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></div><div></div></div><div class="field" style="margin-top:10px"><label>Message</label><textarea name="message" placeholder="Describe your issue" required></textarea></div><div class="form-actions"><input type="hidden" name="action" value="ticket_create"><?=csrf_field()?><button class="btn" type="submit">Create ticket</button></div></form></div><div><?php if(!$tickets):?><div class="card empty-state">No support tickets yet.</div><?php endif;?><?php foreach($tickets as $t):$tc=$t['status']==='closed'?'badge-neutral':($t['status']==='pending'?'badge-warning':'badge-success');?><div class="card"><div class="card-head"><div><h3 style="margin:0">#<?=$t['id']?> · <?=e($t['subject'])?></h3><div class="muted" style="margin-top:4px"><?=e($t['created_at'])?></div></div><span class="badge <?=$tc?>"><?=e($t['status'])?></span></div><div class="muted">Priority: <b><?=e($t['priority'])?></b></div><?php foreach($ticketMessages[$t['id']] as $tm):?><div class="ticket-message"><b><?=e($tm['name'] ?: 'User')?></b> <span class="muted">· <?=e($tm['role'] ?: 'user')?> · <?=e($tm['created_at'])?></span><div style="margin-top:5px"><?=nl2br(e($tm['message']))?></div></div><?php endforeach;?><form method="post" style="margin-top:10px"><div class="field"><textarea name="message" placeholder="Reply to this ticket" required></textarea></div><?=csrf_field()?><input type="hidden" name="action" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?=$t['id']?>"><button class="btn btn-secondary" type="submit">Send reply</button></form></div><?php endforeach;?></div></div></section>

  <div class="footer">SkyNoc Reseller Platform · Secure reseller workspace</div>
</div>
</main>
</div>
<script>
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay'),menu=document.getElementById('menuBtn');
function toggleMenu(){sidebar.classList.toggle('open');overlay.classList.toggle('open')}
if(menu)menu.addEventListener('click',toggleMenu);if(overlay)overlay.addEventListener('click',toggleMenu);
document.querySelectorAll('.side-link').forEach(a=>a.addEventListener('click',()=>{if(window.innerWidth<=820){sidebar.classList.remove('open');overlay.classList.remove('open')}}));
const sections=[...document.querySelectorAll('.section[id],#overview')],links=[...document.querySelectorAll('.side-link[href^="#"]')];
window.addEventListener('scroll',()=>{let current='overview';for(const s of sections){if(window.scrollY+150>=s.offsetTop)current=s.id}links.forEach(l=>l.classList.toggle('active',l.getAttribute('href')==='#'+current))},{passive:true});
</script>
</body></html>
