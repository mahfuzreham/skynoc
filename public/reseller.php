<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['reseller']);
$s = $db->prepare('SELECT id FROM resellers WHERE user_id=? LIMIT 1');
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
            if($amount < $minimumDeposit) throw new RuntimeException('Minimum deposit for '.(string)$paymentMethod['name'].' is 

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
            $price=(float)$package['price'];
            if($balance < $price) throw new RuntimeException('Insufficient wallet balance. Please deposit funds first.');
            $q=$db->prepare('INSERT INTO orders(reseller_id,package_id,domain,amount,status,source) VALUES(?,?,?,?,"pending","portal")');
            $q->execute([$rid,$packageId,$domain,$price]);
            $orderId=(int)$db->lastInsertId();
            wallet_debit($rid,$price,'purchase','ORDER-'.$orderId,'Package purchase: '.$package['name'],$orderId,$u['id']);
            $db->commit();
            telegram_notify("🛒 <b>NEW PACKAGE ORDER</b>\\nReseller: ".e($u['name'])."\\nOrder: ".$orderId."\\nPackage: ".e($package['name'])."\\nAmount: $".number_format($price,2)."\\nDomain: ".e($domain));
            $msg='Order submitted. Your wallet has been reserved for this order.';
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
                'id'=>$id,
                'reseller_id'=>$rid,
                'reseller_name'=>$u['name'],
                'reseller_email'=>$u['email'],
                'license_key'=>$l['license_key'],
                'current_domain'=>$l['domain'],
                'new_domain'=>trim($_POST['new_domain']),
                'reason'=>trim($_POST['reason']) ?: null,
                'status'=>'pending'
            ];
            skynoc_notify_reissue_request($notify);
            $msg='Reissue request submitted.';
        }

        if ($action === 'api_create') {
            if($resellerStatus!=='active') throw new RuntimeException('Activate your reseller account before creating API keys.');
            $plain='skynoc_'.bin2hex(random_bytes(24));
            $scopes='licenses:read,reissue:create,reissue:read,packages:read,orders:create,orders:read';
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
$s=$db->prepare('SELECT o.*,p.name package_name,l.license_key FROM orders o JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id WHERE o.reseller_id=? ORDER BY o.id DESC LIMIT 50'); $s->execute([$rid]); $orders=$s->fetchAll();
$s=$db->prepare('SELECT id,amount,method,reference,status,review_note,network,tx_hash,created_at FROM deposit_requests WHERE reseller_id=? ORDER BY id DESC LIMIT 20'); $s->execute([$rid]); $deposits=$s->fetchAll();
$ticketMessages=[];
foreach($tickets as $t){$q=$db->prepare('SELECT tm.id,tm.message,tm.created_at,u.name,u.role FROM ticket_messages tm LEFT JOIN users u ON u.id=tm.user_id WHERE tm.ticket_id=? ORDER BY tm.id ASC');$q->execute([$t['id']]);$ticketMessages[$t['id']]=$q->fetchAll();}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Reseller</title>
<style>*{box-sizing:border-box}body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8fb;margin:0;color:#0f172a}.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px}.nav a{color:#fff;text-decoration:none}.wrap{max-width:1200px;margin:25px auto;padding:0 16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:#fff;padding:20px;border-radius:16px;margin-bottom:18px;border:1px solid #e5e7eb;box-shadow:0 4px 20px #0f172a0a}table{width:100%;min-width:700px;border-collapse:collapse;background:#fff}td,th{padding:12px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px}.table-wrap{overflow-x:auto;border-radius:14px;margin-bottom:18px}input,textarea,select,button{padding:12px;width:100%;margin:5px 0 9px;border:1px solid #dbe2ea;border-radius:10px;font:inherit}button{background:#111827;color:#fff;border:0;font-weight:700}.msg{background:#ecfdf5;color:#166534;padding:12px;border-radius:10px;margin-bottom:15px}.err{background:#fef2f2;color:#991b1b;padding:12px;border-radius:10px;margin-bottom:15px}.secret{background:#fffbeb;color:#92400e;padding:14px;border-radius:10px;word-break:break-all}.notice{padding:12px;border-radius:10px;background:#eff6ff;margin:8px 0}.unread{border-left:4px solid #111827}@media(max-width:700px){.nav{align-items:flex-start;flex-direction:column}.wrap{padding:0 12px}.card{padding:16px}}
</style></head><body>
<div class="nav"><b>SkyNoc Reseller Portal</b><span><?=e($u['name'])?> · <a href="/reseller/levels">🏆 Level</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Reseller Panel</h1>
<div class="card level-card"><h3>🏆 <?=e($level['name'])?> · Level <?=$level['assigned_level']?></h3><div style="font-size:20px;font-weight:850"><?=$level['active']?> Active Licenses</div><?php if($level['assigned_level']<5):?><p><b><?=$level['needed']?> more</b> active license<?=$level['needed']===1?'':'s'?> to reach Level <?=$level['next_level']?> — <?=e($level['next_name'])?>.</p><div style="height:10px;background:#e9eef5;border-radius:99px;overflow:hidden"><div style="height:100%;width:<?=$level['progress']?>%;background:#111827;border-radius:99px"></div></div><?php else:?><p>You reached the <b>Top Reseller</b> level.</p><?php endif;?><p><a href="/reseller/levels">View full level progress →</a></p></div><div class="grid"><div class="card"><h3>Wallet Balance</h3><div style="font-size:32px;font-weight:900">$<?=number_format($wallet,2)?></div><small>Available balance for package purchases</small></div><div class="card"><h3>Account Status</h3><div style="font-size:20px;font-weight:800"><?=e(strtoupper($resellerStatus))?></div><?php if($resellerStatus!=='active'):?><p>Your account needs a minimum <b>$15.00 activation deposit</b>. The approved deposit remains in your wallet.</p><?php endif;?></div></div>
<?php if($resellerStatus!=='active'):?><div class="card"><h3>Activate Reseller Account</h3><p>Deposit at least <b>$15.00</b>. Admin will verify the payment and credit the full approved amount to your wallet.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deposit_request"><input name="amount" type="number" step="0.01" min="15" value="15.00" required><select name="method" required><option value="">Payment method</option><?php foreach($paymentMethods as $pm): if(!(int)$pm['enabled']) continue; ?><option value="<?=e($pm['code'])?>"><?=e($pm['name'])?> — min $<?=number_format(max((float)$pm['min_deposit'], $resellerStatus==='pending'?15:0),2)?></option><?php endforeach;?></select><input name="reference" placeholder="Payment reference / USDT TXID"><textarea name="note" placeholder="Optional note"></textarea><button>Submit Deposit Request</button></form></div><?php else:?><div class="card"><h3>Add Funds</h3><p>Submit a deposit request. Approved funds are added to your wallet.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deposit_request"><input name="amount" type="number" step="0.01" min="0.01" placeholder="Amount USD" required><select name="method" required><option value="">Payment method</option><option value="USDT_BEP20">USDT (BEP20 / BSC)</option><option value="bKash">bKash</option><option value="Binance / Crypto">Binance / Crypto</option><option value="Bank Transfer">Bank Transfer</option><option value="Manual">Manual</option></select><input name="reference" placeholder="Payment/reference ID"><button>Submit Deposit</button></form></div><?php endif;?>
<?php if($resellerStatus==='active'):?><div class="card"><h3>Buy a License Package</h3><p>Package price is deducted from your wallet when the order is submitted.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="order_create"><select name="package_id" required><option value="">Choose package</option><?php foreach($packages as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?> — $<?=number_format((float)$p['price'],2)?> / <?=e($p['billing_period'])?></option><?php endforeach;?></select><input name="domain" placeholder="WHMCS installation domain" required><button>Buy with Wallet</button></form></div><?php endif;?>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>

<h2>Available Packages</h2><div class="table-wrap"><table><tr><th>Package</th><th>Description</th><th>Price</th><th>Clients</th><th>Billing</th></tr><?php foreach($packages as $p):?><tr><td><?=e($p['name'])?></td><td><?=e($p['description'] ?? '')?></td><td>$<?=number_format((float)$p['price'],2)?></td><td><?=e((string)($p['client_limit'] ?? '-'))?></td><td><?=e($p['billing_period'])?></td></tr><?php endforeach;?></table></div><h2>My Licenses</h2><div class="table-wrap"><table><tr><th>License</th><th>Domain</th><th>Status</th><th>Expires</th></tr><?php foreach($licenses as $l):?><tr><td><?=e($l['license_key'])?></td><td><?=e($l['domain'])?></td><td><?=e($l['status'])?></td><td><?=e($l['expires_at'])?></td></tr><?php endforeach;?></table></div>

<div class="grid"><div class="card"><h3>Request Reissue</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reissue"><select name="license_id" required><?php foreach($licenses as $l):?><option value="<?=$l['id']?>"><?=e($l['license_key'].' — '.$l['domain'])?></option><?php endforeach;?></select><input name="new_domain" placeholder="New domain" required><textarea name="reason" placeholder="Reason"></textarea><button>Submit Reissue</button></form></div>
<div class="card"><h3>Open Support Ticket</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_create"><input name="subject" placeholder="Subject" required><select name="priority"><option>normal</option><option>low</option><option>high</option><option>urgent</option></select><textarea name="message" placeholder="Describe your issue" required></textarea><button>Create Ticket</button></form></div></div>

<h2>Orders</h2><div class="table-wrap"><table><tr><th>ID</th><th>Package</th><th>Domain</th><th>Amount</th><th>Status</th><th>Source</th><th>Created</th></tr><?php foreach($orders as $o):?><tr><td>#<?=$o['id']?></td><td><?=e($o['package_name'])?></td><td><?=e($o['domain'])?></td><td>$<?=number_format((float)$o['amount'],2)?></td><td><?=e($o['status'])?></td><td><?=e($o['source'])?></td><td><?=e($o['created_at'])?></td></tr><?php endforeach;?></table></div><h2>Deposit History</h2><div class="table-wrap"><table><tr><th>ID</th><th>Amount</th><th>Method</th><th>Network</th><th>TXID/Reference</th><th>Status</th><th>Created</th></tr><?php foreach($deposits as $d):?><tr><td>#<?=$d['id']?></td><td>$<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['method'])?></td><td><?=e($d['network'] ?? '-')?></td><td style="max-width:260px;word-break:break-all"><?=e($d['tx_hash'] ?: ($d['reference'] ?? '-'))?></td><td><?=e($d['status'])?></td><td><?=e($d['created_at'])?></td></tr><?php endforeach;?></table></div><h2>Reissue History</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Current</th><th>New</th><th>Status</th><th>Created</th></tr><?php foreach($reissues as $rr):?><tr><td><?=$rr['id']?></td><td><?=e($rr['license_key'])?></td><td><?=e($rr['current_domain'])?></td><td><?=e($rr['new_domain'])?></td><td><?=e($rr['status'])?></td><td><?=e($rr['created_at'])?></td></tr><?php endforeach;?></table></div>

<?php if($newApiKey):?><div class="secret"><b>New API key — copy it now:</b><br><?=e($newApiKey)?></div><?php endif;?><div class="card"><h3>Generate API Key</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_create"><input name="name" placeholder="Key name"><input name="expires_at" type="datetime-local"><button>Generate API Key</button></form></div><h2>API Credentials</h2><div class="table-wrap"><table><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Status</th><th>Last Used</th><th>Expires</th><th>Action</th></tr><?php foreach($apiKeys as $k):?><tr><td><?=e($k['name'])?></td><td><?=e($k['key_prefix'])?>…</td><td><?=e($k['scopes'])?></td><td><?=e($k['status'])?></td><td><?=e($k['last_used_at'])?></td><td><?=e($k['expires_at'])?></td><td><?php if($k['status']==='active'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_revoke"><input type="hidden" name="id" value="<?=$k['id']?>"><button>Revoke</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
<p>API keys are only shown in full once, when an admin generates them. Never share them publicly.</p>

<h2>Notifications</h2><div class="card"><?php foreach($notifications as $n):?><div class="notice <?=$n['read_at']?'':'unread'?>"><b><?=e($n['title'])?></b><br><?=nl2br(e($n['message']))?><br><small><?=e($n['created_at'])?></small><?php if(!$n['read_at']):?><form method="post" style="margin-top:7px"><?=csrf_field()?><input type="hidden" name="action" value="notification_read"><input type="hidden" name="id" value="<?=$n['id']?>"><button>Mark read</button></form><?php endif;?></div><?php endforeach;?></div>

<h2>Support</h2><?php foreach($tickets as $t):?><div class="card"><h3>#<?=$t['id']?> — <?=e($t['subject'])?></h3><p>Status: <b><?=e($t['status'])?></b> · Priority: <?=e($t['priority'])?></p><?php foreach($ticketMessages[$t['id']] as $tm):?><div class="notice"><b><?=e($tm['name'] ?: 'User')?></b> (<?=e($tm['role'] ?: 'user')?>)<br><?=nl2br(e($tm['message']))?><br><small><?=e($tm['created_at'])?></small></div><?php endforeach;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?=$t['id']?>"><textarea name="message" placeholder="Reply" required></textarea><button>Reply</button></form></div><?php endforeach;?>
</div></body></html>.number_format($minimumDeposit,2).'.');

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
            $price=(float)$package['price'];
            if($balance < $price) throw new RuntimeException('Insufficient wallet balance. Please deposit funds first.');
            $q=$db->prepare('INSERT INTO orders(reseller_id,package_id,domain,amount,status,source) VALUES(?,?,?,?,"pending","portal")');
            $q->execute([$rid,$packageId,$domain,$price]);
            $orderId=(int)$db->lastInsertId();
            wallet_debit($rid,$price,'purchase','ORDER-'.$orderId,'Package purchase: '.$package['name'],$orderId,$u['id']);
            $db->commit();
            telegram_notify("🛒 <b>NEW PACKAGE ORDER</b>\\nReseller: ".e($u['name'])."\\nOrder: ".$orderId."\\nPackage: ".e($package['name'])."\\nAmount: $".number_format($price,2)."\\nDomain: ".e($domain));
            $msg='Order submitted. Your wallet has been reserved for this order.';
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
                'id'=>$id,
                'reseller_id'=>$rid,
                'reseller_name'=>$u['name'],
                'reseller_email'=>$u['email'],
                'license_key'=>$l['license_key'],
                'current_domain'=>$l['domain'],
                'new_domain'=>trim($_POST['new_domain']),
                'reason'=>trim($_POST['reason']) ?: null,
                'status'=>'pending'
            ];
            skynoc_notify_reissue_request($notify);
            $msg='Reissue request submitted.';
        }

        if ($action === 'api_create') {
            if($resellerStatus!=='active') throw new RuntimeException('Activate your reseller account before creating API keys.');
            $plain='skynoc_'.bin2hex(random_bytes(24));
            $scopes='licenses:read,reissue:create,reissue:read,packages:read,orders:create,orders:read';
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
$s=$db->prepare('SELECT o.*,p.name package_name,l.license_key FROM orders o JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id WHERE o.reseller_id=? ORDER BY o.id DESC LIMIT 50'); $s->execute([$rid]); $orders=$s->fetchAll();
$s=$db->prepare('SELECT id,amount,method,reference,status,review_note,network,tx_hash,created_at FROM deposit_requests WHERE reseller_id=? ORDER BY id DESC LIMIT 20'); $s->execute([$rid]); $deposits=$s->fetchAll();
$ticketMessages=[];
foreach($tickets as $t){$q=$db->prepare('SELECT tm.id,tm.message,tm.created_at,u.name,u.role FROM ticket_messages tm LEFT JOIN users u ON u.id=tm.user_id WHERE tm.ticket_id=? ORDER BY tm.id ASC');$q->execute([$t['id']]);$ticketMessages[$t['id']]=$q->fetchAll();}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Reseller</title>
<style>*{box-sizing:border-box}body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8fb;margin:0;color:#0f172a}.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px}.nav a{color:#fff;text-decoration:none}.wrap{max-width:1200px;margin:25px auto;padding:0 16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:#fff;padding:20px;border-radius:16px;margin-bottom:18px;border:1px solid #e5e7eb;box-shadow:0 4px 20px #0f172a0a}table{width:100%;min-width:700px;border-collapse:collapse;background:#fff}td,th{padding:12px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px}.table-wrap{overflow-x:auto;border-radius:14px;margin-bottom:18px}input,textarea,select,button{padding:12px;width:100%;margin:5px 0 9px;border:1px solid #dbe2ea;border-radius:10px;font:inherit}button{background:#111827;color:#fff;border:0;font-weight:700}.msg{background:#ecfdf5;color:#166534;padding:12px;border-radius:10px;margin-bottom:15px}.err{background:#fef2f2;color:#991b1b;padding:12px;border-radius:10px;margin-bottom:15px}.secret{background:#fffbeb;color:#92400e;padding:14px;border-radius:10px;word-break:break-all}.notice{padding:12px;border-radius:10px;background:#eff6ff;margin:8px 0}.unread{border-left:4px solid #111827}@media(max-width:700px){.nav{align-items:flex-start;flex-direction:column}.wrap{padding:0 12px}.card{padding:16px}}
</style></head><body>
<div class="nav"><b>SkyNoc Reseller Portal</b><span><?=e($u['name'])?> · <a href="/reseller/levels">🏆 Level</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Reseller Panel</h1>
<div class="card level-card"><h3>🏆 <?=e($level['name'])?> · Level <?=$level['assigned_level']?></h3><div style="font-size:20px;font-weight:850"><?=$level['active']?> Active Licenses</div><?php if($level['assigned_level']<5):?><p><b><?=$level['needed']?> more</b> active license<?=$level['needed']===1?'':'s'?> to reach Level <?=$level['next_level']?> — <?=e($level['next_name'])?>.</p><div style="height:10px;background:#e9eef5;border-radius:99px;overflow:hidden"><div style="height:100%;width:<?=$level['progress']?>%;background:#111827;border-radius:99px"></div></div><?php else:?><p>You reached the <b>Top Reseller</b> level.</p><?php endif;?><p><a href="/reseller/levels">View full level progress →</a></p></div><div class="grid"><div class="card"><h3>Wallet Balance</h3><div style="font-size:32px;font-weight:900">$<?=number_format($wallet,2)?></div><small>Available balance for package purchases</small></div><div class="card"><h3>Account Status</h3><div style="font-size:20px;font-weight:800"><?=e(strtoupper($resellerStatus))?></div><?php if($resellerStatus!=='active'):?><p>Your account needs a minimum <b>$15.00 activation deposit</b>. The approved deposit remains in your wallet.</p><?php endif;?></div></div>
<?php if($resellerStatus!=='active'):?><div class="card"><h3>Activate Reseller Account</h3><p>Deposit at least <b>$15.00</b>. Admin will verify the payment and credit the full approved amount to your wallet.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deposit_request"><input name="amount" type="number" step="0.01" min="15" value="15.00" required><select name="method" required><option value="">Payment method</option><option value="USDT_BEP20">USDT (BEP20 / BSC)</option><option value="bKash">bKash</option><option value="Binance / Crypto">Binance / Crypto</option><option value="Bank Transfer">Bank Transfer</option><option value="Manual">Manual</option></select><input name="reference" placeholder="Payment reference / USDT TXID"><textarea name="note" placeholder="Optional note"></textarea><button>Submit Deposit Request</button></form></div><?php else:?><div class="card"><h3>Add Funds</h3><p>Submit a deposit request. Approved funds are added to your wallet.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deposit_request"><input name="amount" type="number" step="0.01" min="1" placeholder="Amount USD" required><select name="method" required><option value="">Payment method</option><option value="USDT_BEP20">USDT (BEP20 / BSC)</option><option value="bKash">bKash</option><option value="Binance / Crypto">Binance / Crypto</option><option value="Bank Transfer">Bank Transfer</option><option value="Manual">Manual</option></select><input name="reference" placeholder="Payment/reference ID"><button>Submit Deposit</button></form></div><?php endif;?>
<?php if($resellerStatus==='active'):?><div class="card"><h3>Buy a License Package</h3><p>Package price is deducted from your wallet when the order is submitted.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="order_create"><select name="package_id" required><option value="">Choose package</option><?php foreach($packages as $p):?><option value="<?=$p['id']?>"><?=e($p['name'])?> — $<?=number_format((float)$p['price'],2)?> / <?=e($p['billing_period'])?></option><?php endforeach;?></select><input name="domain" placeholder="WHMCS installation domain" required><button>Buy with Wallet</button></form></div><?php endif;?>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>

<h2>Available Packages</h2><div class="table-wrap"><table><tr><th>Package</th><th>Description</th><th>Price</th><th>Clients</th><th>Billing</th></tr><?php foreach($packages as $p):?><tr><td><?=e($p['name'])?></td><td><?=e($p['description'] ?? '')?></td><td>$<?=number_format((float)$p['price'],2)?></td><td><?=e((string)($p['client_limit'] ?? '-'))?></td><td><?=e($p['billing_period'])?></td></tr><?php endforeach;?></table></div><h2>My Licenses</h2><div class="table-wrap"><table><tr><th>License</th><th>Domain</th><th>Status</th><th>Expires</th></tr><?php foreach($licenses as $l):?><tr><td><?=e($l['license_key'])?></td><td><?=e($l['domain'])?></td><td><?=e($l['status'])?></td><td><?=e($l['expires_at'])?></td></tr><?php endforeach;?></table></div>

<div class="grid"><div class="card"><h3>Request Reissue</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reissue"><select name="license_id" required><?php foreach($licenses as $l):?><option value="<?=$l['id']?>"><?=e($l['license_key'].' — '.$l['domain'])?></option><?php endforeach;?></select><input name="new_domain" placeholder="New domain" required><textarea name="reason" placeholder="Reason"></textarea><button>Submit Reissue</button></form></div>
<div class="card"><h3>Open Support Ticket</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_create"><input name="subject" placeholder="Subject" required><select name="priority"><option>normal</option><option>low</option><option>high</option><option>urgent</option></select><textarea name="message" placeholder="Describe your issue" required></textarea><button>Create Ticket</button></form></div></div>

<h2>Orders</h2><div class="table-wrap"><table><tr><th>ID</th><th>Package</th><th>Domain</th><th>Amount</th><th>Status</th><th>Source</th><th>Created</th></tr><?php foreach($orders as $o):?><tr><td>#<?=$o['id']?></td><td><?=e($o['package_name'])?></td><td><?=e($o['domain'])?></td><td>$<?=number_format((float)$o['amount'],2)?></td><td><?=e($o['status'])?></td><td><?=e($o['source'])?></td><td><?=e($o['created_at'])?></td></tr><?php endforeach;?></table></div><h2>Deposit History</h2><div class="table-wrap"><table><tr><th>ID</th><th>Amount</th><th>Method</th><th>Network</th><th>TXID/Reference</th><th>Status</th><th>Created</th></tr><?php foreach($deposits as $d):?><tr><td>#<?=$d['id']?></td><td>$<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['method'])?></td><td><?=e($d['network'] ?? '-')?></td><td style="max-width:260px;word-break:break-all"><?=e($d['tx_hash'] ?: ($d['reference'] ?? '-'))?></td><td><?=e($d['status'])?></td><td><?=e($d['created_at'])?></td></tr><?php endforeach;?></table></div><h2>Reissue History</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Current</th><th>New</th><th>Status</th><th>Created</th></tr><?php foreach($reissues as $rr):?><tr><td><?=$rr['id']?></td><td><?=e($rr['license_key'])?></td><td><?=e($rr['current_domain'])?></td><td><?=e($rr['new_domain'])?></td><td><?=e($rr['status'])?></td><td><?=e($rr['created_at'])?></td></tr><?php endforeach;?></table></div>

<?php if($newApiKey):?><div class="secret"><b>New API key — copy it now:</b><br><?=e($newApiKey)?></div><?php endif;?><div class="card"><h3>Generate API Key</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_create"><input name="name" placeholder="Key name"><input name="expires_at" type="datetime-local"><button>Generate API Key</button></form></div><h2>API Credentials</h2><div class="table-wrap"><table><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Status</th><th>Last Used</th><th>Expires</th><th>Action</th></tr><?php foreach($apiKeys as $k):?><tr><td><?=e($k['name'])?></td><td><?=e($k['key_prefix'])?>…</td><td><?=e($k['scopes'])?></td><td><?=e($k['status'])?></td><td><?=e($k['last_used_at'])?></td><td><?=e($k['expires_at'])?></td><td><?php if($k['status']==='active'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_revoke"><input type="hidden" name="id" value="<?=$k['id']?>"><button>Revoke</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
<p>API keys are only shown in full once, when an admin generates them. Never share them publicly.</p>

<h2>Notifications</h2><div class="card"><?php foreach($notifications as $n):?><div class="notice <?=$n['read_at']?'':'unread'?>"><b><?=e($n['title'])?></b><br><?=nl2br(e($n['message']))?><br><small><?=e($n['created_at'])?></small><?php if(!$n['read_at']):?><form method="post" style="margin-top:7px"><?=csrf_field()?><input type="hidden" name="action" value="notification_read"><input type="hidden" name="id" value="<?=$n['id']?>"><button>Mark read</button></form><?php endif;?></div><?php endforeach;?></div>

<h2>Support</h2><?php foreach($tickets as $t):?><div class="card"><h3>#<?=$t['id']?> — <?=e($t['subject'])?></h3><p>Status: <b><?=e($t['status'])?></b> · Priority: <?=e($t['priority'])?></p><?php foreach($ticketMessages[$t['id']] as $tm):?><div class="notice"><b><?=e($tm['name'] ?: 'User')?></b> (<?=e($tm['role'] ?: 'user')?>)<br><?=nl2br(e($tm['message']))?><br><small><?=e($tm['created_at'])?></small></div><?php endforeach;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?=$t['id']?>"><textarea name="message" placeholder="Reply" required></textarea><button>Reply</button></form></div><?php endforeach;?>
</div></body></html>