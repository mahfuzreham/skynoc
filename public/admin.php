<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin','manager','staff']);
$msg = null;
$error = null;
$newKey = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $a = $_POST['action'] ?? '';

        if ($a === 'deposit_review' && can('reseller.manage', $u)) {
            $id=(int)$_POST['id'];
            $status=in_array($_POST['status'] ?? '',['approved','rejected'],true)?$_POST['status']:'rejected';
            $note=trim((string)($_POST['review_note'] ?? '')) ?: null;
            $db->beginTransaction();
            $q=$db->prepare('SELECT * FROM deposit_requests WHERE id=? FOR UPDATE'); $q->execute([$id]); $d=$q->fetch();
            if(!$d || $d['status']!=='pending') throw new RuntimeException('Deposit request is no longer pending.');
            if($status==='approved'){
                $q=$db->prepare('SELECT status FROM resellers WHERE id=? FOR UPDATE'); $q->execute([$d['reseller_id']]); $rs=$q->fetchColumn();
                if($rs===false) throw new RuntimeException('Reseller not found.');
                $type=($rs==='pending') ? 'activation_deposit' : 'deposit';
                $db->prepare('UPDATE deposit_requests SET status="approved",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
                wallet_credit((int)$d['reseller_id'],(float)$d['amount'],$type,'DEPOSIT-'.$id,'Approved reseller deposit #'.$id,null,$u['id']);
                if($rs==='pending') $db->prepare('UPDATE resellers SET status="active" WHERE id=?')->execute([$d['reseller_id']]);
                notify_reseller((int)$d['reseller_id'],'wallet','Deposit approved','Your deposit #'.$id.' has been approved and credited to your wallet.');
            } else {
                $db->prepare('UPDATE deposit_requests SET status="rejected",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
                notify_reseller((int)$d['reseller_id'],'wallet','Deposit rejected','Your deposit #'.$id.' was rejected.'.($note?' Note: '.$note:''));
            }
            $db->commit(); audit('deposit_'.$status,'deposit_requests',$id);
            $msg='Deposit review completed.';
        }

        if ($a === 'provider' && can('provider.manage', $u)) {
            $s = $db->prepare('INSERT INTO provider_accounts(provider_type,provider_name,account_email,account_label,internal_notes) VALUES(?,?,?,?,?)');
            $s->execute([
                $_POST['provider_type'] ?: 'MANUAL_PARTNER',
                trim($_POST['provider_name']),
                trim($_POST['account_email']) ?: null,
                trim($_POST['account_label']) ?: null,
                trim($_POST['internal_notes']) ?: null
            ]);
            audit('provider_created','provider_accounts',(int)$db->lastInsertId());
            $msg = 'Provider added.';
        }

        if ($a === 'reseller' && can('reseller.manage', $u)) {
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $pw = (string)$_POST['password'];
            if (strlen($pw) < 10) throw new RuntimeException('Password must be at least 10 characters.');
            $db->beginTransaction();
            $s = $db->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,"reseller","active")');
            $s->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
            $uid = (int)$db->lastInsertId();
            $s = $db->prepare('INSERT INTO resellers(user_id,name,email) VALUES(?,?,?)');
            $s->execute([$uid,$name,$email]);
            $rid = (int)$db->lastInsertId();
            $db->commit();
            audit('reseller_created','resellers',$rid);
            $msg = 'Reseller created.';
        }

        if ($a === 'staff' && can('staff.manage', $u)) {
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $pw = (string)$_POST['password'];
            $role = in_array($_POST['role'] ?? '', ['admin','manager','staff'], true) ? $_POST['role'] : 'staff';
            if (strlen($pw) < 10) throw new RuntimeException('Staff password must be at least 10 characters.');
            $permissions = [];
            foreach (['provider.manage','license.manage','reseller.manage','reissue.manage','api.manage','staff.manage','ticket.manage'] as $perm) {
                $permissions[$perm] = isset($_POST['perm'][$perm]);
            }
            $s = $db->prepare('INSERT INTO users(name,email,password_hash,role,status,permissions) VALUES(?,?,?,?,"active",?)');
            $s->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT),$role,json_encode($permissions)]);
            $id = (int)$db->lastInsertId();
            audit('staff_created','users',$id,$role);
            $msg = 'Staff account created.';
        }

        if ($a === 'staff_status' && can('staff.manage', $u)) {
            $id = (int)$_POST['id'];
            if ($id === (int)$u['id']) throw new RuntimeException('You cannot change your own status here.');
            $status = in_array($_POST['status'] ?? '', ['active','suspended','disabled'], true) ? $_POST['status'] : 'active';
            $db->prepare('UPDATE users SET status=? WHERE id=? AND role IN ("admin","manager","staff")')->execute([$status,$id]);
            audit('staff_status_changed','users',$id,$status);
            $msg = 'Staff status updated.';
        }

        if ($a === 'license' && can('license.manage', $u)) {
            $s = $db->prepare('INSERT INTO licenses(provider_account_id,license_key,domain,reseller_id,status,purchase_date,cost,expires_at) VALUES(?,?,?,?,?,?,?,?)');
            $s->execute([
                $_POST['provider_account_id'] !== '' ? (int)$_POST['provider_account_id'] : null,
                trim($_POST['license_key']),
                trim($_POST['domain']) ?: null,
                $_POST['reseller_id'] !== '' ? (int)$_POST['reseller_id'] : null,
                $_POST['status'] ?: 'available',
                $_POST['purchase_date'] ?: null,
                $_POST['cost'] !== '' ? $_POST['cost'] : null,
                $_POST['expires_at'] ?: null
            ]);
            $id = (int)$db->lastInsertId();
            audit('license_created','licenses',$id);
            $msg = 'License added.';
        }

        if ($a === 'license_status' && can('license.manage', $u)) {
            $id = (int)$_POST['id'];
            $status = in_array($_POST['status'] ?? '', ['available','active','suspended','expired','cancelled'], true) ? $_POST['status'] : 'available';
            $s = $db->prepare('SELECT status FROM licenses WHERE id=?');
            $s->execute([$id]);
            $old = $s->fetchColumn();
            $db->prepare('UPDATE licenses SET status=? WHERE id=?')->execute([$status,$id]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,$u['id'],'status_changed',($old ?: '-') . ' -> ' . $status]);
            audit('license_status_changed','licenses',$id,$status);
            $msg = 'License status updated.';
        }

        if ($a === 'reassign_license' && can('license.manage', $u)) {
            $id = (int)$_POST['id'];
            $rid = $_POST['reseller_id'] === '' ? null : (int)$_POST['reseller_id'];
            $s = $db->prepare('SELECT reseller_id FROM licenses WHERE id=?');
            $s->execute([$id]);
            $oldRid = $s->fetchColumn();
            $db->prepare('UPDATE licenses SET reseller_id=? WHERE id=?')->execute([$rid,$id]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,$u['id'],'reseller_changed','Old: '.($oldRid ?: 'unassigned').' New: '.($rid ?: 'unassigned')]);
            audit('license_reseller_changed','licenses',$id,(string)($rid ?: 'unassigned'));
            $msg = 'License assignment updated.';
        }

        if ($a === 'reissue' && can('reissue.manage', $u)) {
            $id = (int)$_POST['id'];
            $status = $_POST['status'] ?? 'review';
            if (!in_array($status, ['pending','review','manual_reissue','completed','rejected'], true)) $status = 'review';
            $note = trim($_POST['admin_note']);
            $s = $db->prepare('SELECT rr.*,l.license_key,r.name reseller_name,r.email reseller_email
                                      FROM reissue_requests rr
                                      JOIN licenses l ON l.id=rr.license_id
                                      JOIN resellers r ON r.id=rr.reseller_id
                                      WHERE rr.id=?');
            $s->execute([$id]);
            $rr = $s->fetch();
            if (!$rr) throw new RuntimeException('Reissue request not found.');
            $db->beginTransaction();
            $db->prepare('UPDATE reissue_requests SET status=?,handled_by=?,admin_note=? WHERE id=?')->execute([$status,$u['id'],$note ?: null,$id]);
            if ($status === 'completed') {
                $db->prepare('UPDATE licenses SET domain=?,status="active" WHERE id=?')->execute([$rr['new_domain'],$rr['license_id']]);
                $db->prepare('INSERT INTO license_history(license_id,user_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?,?)')->execute([$rr['license_id'],$u['id'],'reissue_completed',$rr['current_domain'],$rr['new_domain'],$note ?: null]);
            }
            notify_reseller((int)$rr['reseller_id'],'reissue','Reissue '.$status,'License '.$rr['license_key'].' reissue request is now '.strtoupper($status).'.'.($note ? ' Note: '.$note : ''));
            $db->commit();
            audit('reissue_'.$status,'reissue_requests',$id);
            skynoc_license_telegram_send("📌 <b>REISSUE UPDATED</b>\nLicense: ".e($rr['license_key'])."\nStatus: ".e(strtoupper($status)));
            $msg = 'Reissue updated.';
        }

        if ($a === 'api_key' && can('api.manage', $u)) {
            $rid = (int)$_POST['reseller_id'];
            $plain = 'skynoc_' . bin2hex(random_bytes(24));
            $scopes = [];
            foreach (['licenses:read','licenses:manage','reissue:create','reissue:read','packages:read','orders:create','orders:read'] as $scope) if (isset($_POST['scope'][$scope])) $scopes[] = $scope;
            if (!$scopes) $scopes = ['licenses:read'];
            $s = $db->prepare('INSERT INTO api_keys(reseller_id,name,key_prefix,key_hash,scopes,expires_at) VALUES(?,?,?,?,?,?)');
            $s->execute([$rid,trim($_POST['name']) ?: 'API Key',substr($plain,0,15),hash('sha256',$plain),implode(',',$scopes),$_POST['expires_at'] ?: null]);
            $newKey = $plain;
            audit('api_key_created','api_keys',(int)$db->lastInsertId());
        }

        if ($a === 'api_revoke' && can('api.manage', $u)) {
            $id = (int)$_POST['id'];
            $db->prepare('UPDATE api_keys SET status="revoked" WHERE id=?')->execute([$id]);
            audit('api_key_revoked','api_keys',$id);
            $msg = 'API key revoked.';
        }

        if ($a === 'ticket_status' && can('ticket.manage', $u)) {
            $id = (int)$_POST['id'];
            $status = in_array($_POST['status'] ?? '', ['open','pending','closed'], true) ? $_POST['status'] : 'open';
            $db->prepare('UPDATE tickets SET status=? WHERE id=?')->execute([$status,$id]);
            $s=$db->prepare('SELECT reseller_id,subject FROM tickets WHERE id=?');$s->execute([$id]);$t=$s->fetch();
            if($t) notify_reseller((int)$t['reseller_id'],'ticket','Ticket updated',$t['subject'].' is now '.$status.'.');
            audit('ticket_status_changed','tickets',$id,$status);
            $msg = 'Ticket updated.';
        }

        if ($a === 'telegram' && $u['role'] === 'owner') {
            $licenseToken = trim((string)($_POST['license_bot_token'] ?? ''));
            $licenseChat = trim((string)($_POST['license_admin_chat_id'] ?? ''));
            $depositToken = trim((string)($_POST['deposit_bot_token'] ?? ''));
            $depositChat = trim((string)($_POST['deposit_admin_chat_id'] ?? ''));
            if ($licenseToken === '' || $licenseChat === '') {
                throw new RuntimeException('License control bot token and admin chat ID are required.');
            }
            $s = $db->prepare('INSERT INTO telegram_settings(id,bot_token,admin_chat_id,license_bot_token,license_admin_chat_id,deposit_bot_token,deposit_admin_chat_id)
                VALUES(1,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                bot_token=VALUES(bot_token),
                admin_chat_id=VALUES(admin_chat_id),
                license_bot_token=VALUES(license_bot_token),
                license_admin_chat_id=VALUES(license_admin_chat_id),
                deposit_bot_token=VALUES(deposit_bot_token),
                deposit_admin_chat_id=VALUES(deposit_admin_chat_id)');
            $s->execute([$licenseToken,$licenseChat,$licenseToken,$licenseChat,$depositToken ?: null,$depositChat ?: null]);
            $msg = 'Telegram bot settings saved.';
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getCode() === '23000' ? 'This email or value already exists.' : 'The request could not be completed. Please check the server error log.';
    error_log('SkyNoc admin error: ' . $e->getMessage());
}

$packages = $db->query('SELECT id,name,slug,description,price,client_limit,billing_period,active,sort_order FROM packages ORDER BY sort_order,id DESC')->fetchAll();
$deposits = $db->query('SELECT d.*,r.name reseller_name,r.email reseller_email FROM deposit_requests d JOIN resellers r ON r.id=d.reseller_id ORDER BY d.id DESC LIMIT 100')->fetchAll();
$orders = $db->query('SELECT o.*,r.name reseller_name,p.name package_name,l.license_key FROM orders o JOIN resellers r ON r.id=o.reseller_id JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id ORDER BY o.id DESC LIMIT 100')->fetchAll();
$providers = $db->query('SELECT id,provider_name,account_email,account_label,status FROM provider_accounts ORDER BY id DESC')->fetchAll();
$resellers = $db->query('SELECT id,name,email,status FROM resellers ORDER BY id DESC')->fetchAll();
$licenses = $db->query('SELECT l.*,r.name reseller_name,p.provider_name FROM licenses l LEFT JOIN resellers r ON r.id=l.reseller_id LEFT JOIN provider_accounts p ON p.id=l.provider_account_id ORDER BY l.id DESC LIMIT 100')->fetchAll();
$reissues = $db->query('SELECT rr.*,l.license_key,r.name reseller_name FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id JOIN resellers r ON r.id=rr.reseller_id ORDER BY rr.id DESC LIMIT 50')->fetchAll();
$staff = $db->query('SELECT id,name,email,role,status,permissions,created_at FROM users WHERE role IN ("admin","manager","staff") ORDER BY id DESC')->fetchAll();
$apiKeys = $db->query('SELECT ak.id,ak.name,ak.key_prefix,ak.scopes,ak.status,ak.last_used_at,ak.expires_at,r.name reseller_name FROM api_keys ak JOIN resellers r ON r.id=ak.reseller_id ORDER BY ak.id DESC LIMIT 100')->fetchAll();
$tickets = $db->query('SELECT t.*,r.name reseller_name FROM tickets t LEFT JOIN resellers r ON r.id=t.reseller_id ORDER BY t.id DESC LIMIT 50')->fetchAll();
$telegramSettings = $db->query('SELECT * FROM telegram_settings WHERE id=1 LIMIT 1')->fetch() ?: [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Admin</title>
<style>*{box-sizing:border-box}body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;background:#f6f8fb;color:#0f172a}.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px}.nav a{color:#fff}.wrap{max-width:1400px;margin:24px auto;padding:0 16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:#fff;padding:20px;border-radius:16px;box-shadow:0 4px 20px #0f172a0a;border:1px solid #e5e7eb;margin-bottom:18px}input,select,textarea,button{width:100%;padding:11px;margin:5px 0 9px;border:1px solid #dbe2ea;border-radius:10px;font:inherit}button{background:#111827;color:#fff;border:0;font-weight:700;cursor:pointer}.msg{background:#ecfdf5;color:#166534;padding:12px;border-radius:10px;margin-bottom:15px}.err{background:#fef2f2;color:#991b1b;padding:12px;border-radius:10px;margin-bottom:15px}.secret{background:#fffbeb;color:#92400e;padding:14px;border-radius:10px;word-break:break-all}.table-wrap{overflow-x:auto;border-radius:14px;margin-bottom:24px}table{width:100%;min-width:850px;border-collapse:collapse;background:#fff}td,th{padding:11px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px}.checks label{display:block;margin:7px 0}.inline{display:flex;gap:8px;align-items:center}.inline>*{width:auto;margin:0}@media(max-width:700px){.nav{align-items:flex-start;flex-direction:column;position:sticky;top:0;z-index:20}.nav span{display:flex;flex-wrap:wrap;gap:8px;line-height:1.5}.nav a{margin:0}.wrap{padding:0 10px;margin:14px auto}.card{padding:14px;border-radius:14px}.grid{grid-template-columns:1fr}h1{font-size:25px}h2{font-size:20px}.table-wrap{margin-left:-2px;margin-right:-2px}td,th{padding:9px;font-size:12px}.inline{flex-direction:column;align-items:stretch}.inline>*{width:100%}input,select,textarea,button{min-height:44px}}
</style></head><body>
<div class="nav"><b>SkyNoc Admin</b><span><?=e($u['name'])?> · <?=e($u['role'])?> · <a href="/dashboard">Dashboard</a> · <a href="/admin/reseller-levels">🏆 Reseller Levels</a> · <a href="/admin/settings">⚙️ Settings</a> · <a href="/admin/reports">📊 Reports</a> · <a href="/admin/coupons">🎟️ Coupons</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Management</h1>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>
<?php if($newKey):?><div class="secret"><b>New API key — copy it now. It will not be shown again:</b><br><?=e($newKey)?></div><?php endif;?>
<h2>Reseller Deposits</h2><div class="table-wrap"><table><tr><th>ID</th><th>Reseller</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Action</th></tr><?php foreach($deposits as $d):?><tr><td>#<?=$d['id']?></td><td><?=e($d['reseller_name'])?><br><small><?=e($d['reseller_email'])?></small></td><td>$<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['method'])?></td><td><?=e($d['reference'] ?: '-')?></td><td><?=e($d['status'])?></td><td><?php if($d['status']==='pending' && can('reseller.manage',$u)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="deposit_review"><input type="hidden" name="id" value="<?=$d['id']?>"><select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select><input name="review_note" placeholder="Review note"><button>Save</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
<h2>License Packages</h2><div class="grid"><div class="card"><h3>Add Package</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="package_create"><input name="name" placeholder="Professional" required><input name="slug" placeholder="professional" required><textarea name="description" placeholder="Package description"></textarea><input name="price" type="number" step="0.01" min="0.01" placeholder="Price USD" required><input name="client_limit" type="number" min="1" placeholder="Client limit"><select name="billing_period"><option>monthly</option><option>annual</option><option>one_time</option></select><input name="sort_order" type="number" value="0"><button>Create Package</button></form></div><div class="card"><h3>Current Packages</h3><?php foreach($packages as $p):?><div style="padding:9px 0;border-bottom:1px solid #eef2f7"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="package_update"><input type="hidden" name="id" value="<?=$p['id']?>"><input name="name" value="<?=e($p['name'])?>" required><input name="price" type="number" step="0.01" min="0.01" value="<?=e((string)$p['price'])?>" required><input name="client_limit" type="number" min="1" value="<?=e((string)($p['client_limit'] ?? ''))?>" placeholder="Client limit"><select name="billing_period"><option <?=$p['billing_period']=='monthly'?'selected':''>monthly</option><option <?=$p['billing_period']=='annual'?'selected':''>annual</option><option <?=$p['billing_period']=='one_time'?'selected':''>one_time</option></select><textarea name="description" placeholder="Description"><?=e($p['description'] ?? '')?></textarea><button>Save Package</button></form><form method="post" class="inline" style="margin-top:5px"><?=csrf_field()?><input type="hidden" name="action" value="package_status"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="active" value="<?=$p['active']?0:1?>"><button><?=$p['active']?'Disable':'Enable'?></button></form></div><form method="post" class="inline" style="margin-top:5px"><?=csrf_field()?><input type="hidden" name="action" value="package_status"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="active" value="<?=$p['active']?0:1?>"><button><?=$p['active']?'Disable':'Enable'?></button></form></div><?php endforeach;?></div></div>
<h2>Reseller Orders</h2><div class="table-wrap"><table><tr><th>ID</th><th>Reseller</th><th>Package</th><th>Domain</th><th>Amount</th><th>Status</th><th>Fulfill</th></tr><?php foreach($orders as $o):?><tr><td>#<?=$o['id']?></td><td><?=e($o['reseller_name'])?></td><td><?=e($o['package_name'])?></td><td><?=e($o['domain'])?></td><td>$<?=number_format((float)$o['amount'],2)?></td><td><?=e($o['status'])?></td><td><?php if(in_array($o['status'],['pending','processing'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="order_review"><input type="hidden" name="id" value="<?=$o['id']?>"><select name="status"><option value="processing">Processing</option><option value="completed">Completed</option><option value="rejected">Reject + Refund</option></select><select name="license_id"><option value="">Select available license</option><?php foreach($licenses as $li): if($li['status']==='available' && $li['reseller_id']===null):?><option value="<?=$li['id']?>"><?=e($li['license_key'])?></option><?php endif; endforeach;?></select><input name="notes" placeholder="Order note"><button>Update Order</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
<div class="grid">
<?php if(can('provider.manage',$u)):?><div class="card"><h3>Add Provider</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="provider"><input name="provider_name" placeholder="Provider name" required><input name="account_email" type="email" placeholder="Internal account email"><input name="account_label" placeholder="Internal label"><select name="provider_type"><option>MANUAL_PARTNER</option><option>WHMCS_API</option></select><textarea name="internal_notes" placeholder="Internal notes"></textarea><button>Add Provider</button></form></div><?php endif;?>
<?php if(can('reseller.manage',$u)):?><div class="card"><h3>Create Reseller</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reseller"><input name="name" placeholder="Reseller name" required><input name="email" type="email" placeholder="Login email" required><input name="password" type="password" placeholder="Temporary password (10+ chars)" required><button>Create Reseller</button></form></div><?php endif;?>
<?php if(can('staff.manage',$u)):?><div class="card"><h3>Create Staff</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="staff"><input name="name" placeholder="Name" required><input name="email" type="email" placeholder="Login email" required><input name="password" type="password" placeholder="Password (10+ chars)" required><select name="role"><option>staff</option><option>manager</option><option>admin</option></select><div class="checks"><?php foreach(['provider.manage','license.manage','reseller.manage','reissue.manage','api.manage','staff.manage','ticket.manage'] as $perm):?><label><input type="checkbox" name="perm[<?=e($perm)?>]" style="width:auto"> <?=e($perm)?></label><?php endforeach;?></div><button>Create Staff</button></form></div><?php endif;?>
<?php if(can('license.manage',$u)):?><div class="card"><h3>Add License</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="license"><input name="license_key" placeholder="License key" required><input name="domain" placeholder="Domain"><select name="provider_account_id"><option value="">Provider</option><?php foreach($providers as $p):?><option value="<?=$p['id']?>"><?=e($p['provider_name'].' — '.$p['account_label'])?></option><?php endforeach;?></select><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select><select name="status"><option>available</option><option>active</option><option>suspended</option><option>expired</option><option>cancelled</option></select><input name="purchase_date" type="date"><input name="cost" type="number" step="0.01" placeholder="Cost"><input name="expires_at" type="datetime-local"><button>Add License</button></form></div><?php endif;?>
<?php if(can('api.manage',$u)):?><div class="card"><h3>Create API Key</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_key"><select name="reseller_id" required><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select><input name="name" placeholder="Key name"><input name="expires_at" type="datetime-local"><div class="checks"><label><input type="checkbox" name="scope[licenses:read]" checked style="width:auto"> licenses:read</label><label><input type="checkbox" name="scope[licenses:manage]" checked style="width:auto"> licenses:manage</label><label><input type="checkbox" name="scope[reissue:create]" checked style="width:auto"> reissue:create</label><label><input type="checkbox" name="scope[reissue:read]" checked style="width:auto"> reissue:read</label></div><button>Generate API Key</button></form></div><?php endif;?>
<?php if($u['role']==='owner'):?>
<div class="card"><h3>Telegram Bot Settings</h3>
<p><b>License Control Bot</b> sends reissue/license alerts and provides Telegram admin controls.</p>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="telegram">
<input name="license_bot_token" value="<?=e((string)($telegramSettings['license_bot_token'] ?? $telegramSettings['bot_token'] ?? ''))?>" placeholder="License Control Bot Token" required>
<input name="license_admin_chat_id" value="<?=e((string)($telegramSettings['license_admin_chat_id'] ?? $telegramSettings['admin_chat_id'] ?? ''))?>" placeholder="License Admin Chat ID" required>
<hr>
<p><b>USDT Deposit Bot</b> is a separate bot used only for verified USDT BEP20 deposit notifications.</p>
<input name="deposit_bot_token" value="<?=e((string)($telegramSettings['deposit_bot_token'] ?? ''))?>" placeholder="USDT Deposit Bot Token">
<input name="deposit_admin_chat_id" value="<?=e((string)($telegramSettings['deposit_admin_chat_id'] ?? ''))?>" placeholder="USDT Deposit Admin Chat ID">
<button>Save Telegram Settings</button>
</form>
<small>Control webhook: /telegram/control</small>
</div>
<?php endif;?>
</div>

<h2>Licenses</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Domain</th><th>Provider</th><th>Reseller</th><th>Status</th><th>Controls</th></tr><?php foreach($licenses as $l):?><tr><td><?=$l['id']?></td><td><?=e($l['license_key'])?></td><td><?=e($l['domain'])?></td><td><?=e($l['provider_name'])?></td><td><?=e($l['reseller_name'])?></td><td><?=e($l['status'])?></td><td><?php if(can('license.manage',$u)):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="license_status"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="status"><?php foreach(['available','active','suspended','expired','cancelled'] as $st):?><option <?=$l['status']===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select><button>Save</button></form><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="reassign_license"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>" <?=$l['reseller_id']==$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select><button>Assign</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>

<h2>Reissue Requests</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Reseller</th><th>Current → New</th><th>Status</th><th>Action</th></tr><?php foreach($reissues as $r):?><tr><td><?=$r['id']?></td><td><?=e($r['license_key'])?></td><td><?=e($r['reseller_name'])?></td><td><?=e($r['current_domain'])?> → <?=e($r['new_domain'])?></td><td><?=e($r['status'])?></td><td><?php if(can('reissue.manage',$u)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reissue"><input type="hidden" name="id" value="<?=$r['id']?>"><select name="status"><?php foreach(['review','manual_reissue','completed','rejected'] as $st):?><option><?=$st?></option><?php endforeach;?></select><input name="admin_note" placeholder="Admin note"><button>Update</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>

<?php if(can('staff.manage',$u)):?><h2>Staff</h2><div class="table-wrap"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Permissions</th><th>Action</th></tr><?php foreach($staff as $st):?><tr><td><?=e($st['name'])?></td><td><?=e($st['email'])?></td><td><?=e($st['role'])?></td><td><?=e($st['status'])?></td><td><?=e($st['permissions'] ?: 'role defaults')?></td><td><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="staff_status"><input type="hidden" name="id" value="<?=$st['id']?>"><select name="status"><option>active</option><option>suspended</option><option>disabled</option></select><button>Save</button></form></td></tr><?php endforeach;?></table></div><?php endif;?>

<?php if(can('api.manage',$u)):?><h2>API Keys</h2><div class="table-wrap"><table><tr><th>Reseller</th><th>Name</th><th>Prefix</th><th>Scopes</th><th>Status</th><th>Last Used</th><th>Action</th></tr><?php foreach($apiKeys as $k):?><tr><td><?=e($k['reseller_name'])?></td><td><?=e($k['name'])?></td><td><?=e($k['key_prefix'])?></td><td><?=e($k['scopes'])?></td><td><?=e($k['status'])?></td><td><?=e($k['last_used_at'])?></td><td><?php if($k['status']==='active'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_revoke"><input type="hidden" name="id" value="<?=$k['id']?>"><button>Revoke</button></form><?php endif;?></td></tr><?php endforeach;?></table></div><?php endif;?>

<?php if(can('ticket.manage',$u)):?><h2>Support Tickets</h2><div class="table-wrap"><table><tr><th>ID</th><th>Reseller</th><th>Subject</th><th>Priority</th><th>Status</th><th>Action</th></tr><?php foreach($tickets as $t):?><tr><td><?=$t['id']?></td><td><?=e($t['reseller_name'])?></td><td><?=e($t['subject'])?></td><td><?=e($t['priority'])?></td><td><?=e($t['status'])?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_status"><input type="hidden" name="id" value="<?=$t['id']?>"><select name="status"><option>open</option><option>pending</option><option>closed</option></select><button>Save</button></form></td></tr><?php endforeach;?></table></div><?php endif;?>
</div></body></html>.number_format((float)$d['amount'],2).' deposit has been approved and added to your wallet.');
            } else {
                $db->prepare('UPDATE deposit_requests SET status="rejected",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
                notify_reseller((int)$d['reseller_id'],'wallet','Deposit rejected','Your deposit request #'.$id.' was rejected.'.($note?' Note: '.$note:''));
            }
            $db->commit(); audit('deposit_'.$status,'deposit_requests',$id); $msg='Deposit request updated.';
        }

        if ($a === 'package_create' && can('license.manage', $u)) {
            $name=trim((string)$_POST['name']); $slug=strtolower(trim((string)$_POST['slug']));
            $price=(float)$_POST['price']; $limit=$_POST['client_limit']===''?null:(int)$_POST['client_limit'];
            $period=trim((string)$_POST['billing_period']) ?: 'monthly'; $description=trim((string)$_POST['description']) ?: null;
            if($name===''||$slug===''||$price<=0) throw new RuntimeException('Package name, slug and positive price are required.');
            $db->prepare('INSERT INTO packages(name,slug,description,price,client_limit,billing_period,active,sort_order) VALUES(?,?,?,?,?,?,1,?)')->execute([$name,$slug,$description,$price,$limit,$period,(int)($_POST['sort_order'] ?? 0)]);
            audit('package_created','packages',(int)$db->lastInsertId()); $msg='Package created.';
        }

        if ($a === 'package_update' && can('license.manage', $u)) {
            $id=(int)$_POST['id']; $name=trim((string)$_POST['name']); $price=(float)$_POST['price']; $limit=$_POST['client_limit']===''?null:(int)$_POST['client_limit']; $period=trim((string)$_POST['billing_period']) ?: 'monthly'; $description=trim((string)$_POST['description']) ?: null;
            if($id<=0||$name===''||$price<=0) throw new RuntimeException('Package name and positive price are required.');
            $db->prepare('UPDATE packages SET name=?,description=?,price=?,client_limit=?,billing_period=? WHERE id=?')->execute([$name,$description,$price,$limit,$period,$id]);
            audit('package_updated','packages',$id); $msg='Package updated.';
        }

        if ($a === 'package_status' && can('license.manage', $u)) {
            $id=(int)$_POST['id']; $active=(int)($_POST['active'])===1?1:0;
            $db->prepare('UPDATE packages SET active=? WHERE id=?')->execute([$active,$id]); audit('package_status_changed','packages',$id,(string)$active); $msg='Package status updated.';
        }

        if ($a === 'order_review' && can('license.manage', $u)) {
            $id=(int)$_POST['id']; $status=in_array($_POST['status'] ?? '',['processing','completed','rejected'],true)?$_POST['status']:'processing';
            $licenseId=(int)($_POST['license_id'] ?? 0); $note=trim((string)($_POST['notes'] ?? '')) ?: null;
            $db->beginTransaction();
            $q=$db->prepare('SELECT o.*,p.name package_name FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.id=? FOR UPDATE'); $q->execute([$id]); $o=$q->fetch();
            if(!$o || !in_array($o['status'],['pending','processing'],true)) throw new RuntimeException('Order is not available for review.');
            if($status==='completed'){
                if($licenseId<=0) throw new RuntimeException('Select a license to fulfill this order.');
                $q=$db->prepare('SELECT id,reseller_id,status,domain FROM licenses WHERE id=? FOR UPDATE'); $q->execute([$licenseId]); $lic=$q->fetch();
                if(!$lic || $lic['reseller_id']!==null || $lic['status']!=='available') throw new RuntimeException('Selected license is not available.');
                $db->prepare('UPDATE licenses SET reseller_id=?,domain=?,status="active",purchase_date=CURDATE(),package_id=? WHERE id=?')->execute([$o['reseller_id'],$o['domain'],$o['package_id'],$licenseId]);
                $db->prepare('UPDATE orders SET status="completed",license_id=?,notes=?,completed_at=NOW() WHERE id=?')->execute([$licenseId,$note,$id]);
                notify_reseller((int)$o['reseller_id'],'order','Order completed','Order #'.$id.' for '.$o['package_name'].' has been completed.');
            } elseif($status==='rejected'){
                $db->prepare('UPDATE orders SET status="rejected",notes=? WHERE id=?')->execute([$note,$id]);
                wallet_credit((int)$o['reseller_id'],(float)$o['amount'],'refund','ORDER-'.$id,'Refund for rejected order #'.$id,$id,$u['id']);
                notify_reseller((int)$o['reseller_id'],'order','Order rejected','Order #'.$id.' was rejected and the wallet amount was refunded.');
            } else {
                $db->prepare('UPDATE orders SET status="processing",notes=? WHERE id=?')->execute([$note,$id]);
                notify_reseller((int)$o['reseller_id'],'order','Order processing','Order #'.$id.' is now being processed.');
            }
            $db->commit(); if($status==='completed') platform_invoice_for_order($id); audit('order_'.$status,'orders',$id); $msg='Order updated.';
        }

        if ($a === 'provider' && can('provider.manage', $u)) {
            $s = $db->prepare('INSERT INTO provider_accounts(provider_type,provider_name,account_email,account_label,internal_notes) VALUES(?,?,?,?,?)');
            $s->execute([
                $_POST['provider_type'] ?: 'MANUAL_PARTNER',
                trim($_POST['provider_name']),
                trim($_POST['account_email']) ?: null,
                trim($_POST['account_label']) ?: null,
                trim($_POST['internal_notes']) ?: null
            ]);
            audit('provider_created','provider_accounts',(int)$db->lastInsertId());
            $msg = 'Provider added.';
        }

        if ($a === 'reseller' && can('reseller.manage', $u)) {
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $pw = (string)$_POST['password'];
            if (strlen($pw) < 10) throw new RuntimeException('Password must be at least 10 characters.');
            $db->beginTransaction();
            $s = $db->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,"reseller","active")');
            $s->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
            $uid = (int)$db->lastInsertId();
            $s = $db->prepare('INSERT INTO resellers(user_id,name,email) VALUES(?,?,?)');
            $s->execute([$uid,$name,$email]);
            $rid = (int)$db->lastInsertId();
            $db->commit();
            audit('reseller_created','resellers',$rid);
            $msg = 'Reseller created.';
        }

        if ($a === 'staff' && can('staff.manage', $u)) {
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $pw = (string)$_POST['password'];
            $role = in_array($_POST['role'] ?? '', ['admin','manager','staff'], true) ? $_POST['role'] : 'staff';
            if (strlen($pw) < 10) throw new RuntimeException('Staff password must be at least 10 characters.');
            $permissions = [];
            foreach (['provider.manage','license.manage','reseller.manage','reissue.manage','api.manage','staff.manage','ticket.manage'] as $perm) {
                $permissions[$perm] = isset($_POST['perm'][$perm]);
            }
            $s = $db->prepare('INSERT INTO users(name,email,password_hash,role,status,permissions) VALUES(?,?,?,?,"active",?)');
            $s->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT),$role,json_encode($permissions)]);
            $id = (int)$db->lastInsertId();
            audit('staff_created','users',$id,$role);
            $msg = 'Staff account created.';
        }

        if ($a === 'staff_status' && can('staff.manage', $u)) {
            $id = (int)$_POST['id'];
            if ($id === (int)$u['id']) throw new RuntimeException('You cannot change your own status here.');
            $status = in_array($_POST['status'] ?? '', ['active','suspended','disabled'], true) ? $_POST['status'] : 'active';
            $db->prepare('UPDATE users SET status=? WHERE id=? AND role IN ("admin","manager","staff")')->execute([$status,$id]);
            audit('staff_status_changed','users',$id,$status);
            $msg = 'Staff status updated.';
        }

        if ($a === 'license' && can('license.manage', $u)) {
            $s = $db->prepare('INSERT INTO licenses(provider_account_id,license_key,domain,reseller_id,status,purchase_date,cost,expires_at) VALUES(?,?,?,?,?,?,?,?)');
            $s->execute([
                $_POST['provider_account_id'] !== '' ? (int)$_POST['provider_account_id'] : null,
                trim($_POST['license_key']),
                trim($_POST['domain']) ?: null,
                $_POST['reseller_id'] !== '' ? (int)$_POST['reseller_id'] : null,
                $_POST['status'] ?: 'available',
                $_POST['purchase_date'] ?: null,
                $_POST['cost'] !== '' ? $_POST['cost'] : null,
                $_POST['expires_at'] ?: null
            ]);
            $id = (int)$db->lastInsertId();
            audit('license_created','licenses',$id);
            $msg = 'License added.';
        }

        if ($a === 'license_status' && can('license.manage', $u)) {
            $id = (int)$_POST['id'];
            $status = in_array($_POST['status'] ?? '', ['available','active','suspended','expired','cancelled'], true) ? $_POST['status'] : 'available';
            $s = $db->prepare('SELECT status FROM licenses WHERE id=?');
            $s->execute([$id]);
            $old = $s->fetchColumn();
            $db->prepare('UPDATE licenses SET status=? WHERE id=?')->execute([$status,$id]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,$u['id'],'status_changed',($old ?: '-') . ' -> ' . $status]);
            audit('license_status_changed','licenses',$id,$status);
            $msg = 'License status updated.';
        }

        if ($a === 'reassign_license' && can('license.manage', $u)) {
            $id = (int)$_POST['id'];
            $rid = $_POST['reseller_id'] === '' ? null : (int)$_POST['reseller_id'];
            $s = $db->prepare('SELECT reseller_id FROM licenses WHERE id=?');
            $s->execute([$id]);
            $oldRid = $s->fetchColumn();
            $db->prepare('UPDATE licenses SET reseller_id=? WHERE id=?')->execute([$rid,$id]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,$u['id'],'reseller_changed','Old: '.($oldRid ?: 'unassigned').' New: '.($rid ?: 'unassigned')]);
            audit('license_reseller_changed','licenses',$id,(string)($rid ?: 'unassigned'));
            $msg = 'License assignment updated.';
        }

        if ($a === 'reissue' && can('reissue.manage', $u)) {
            $id = (int)$_POST['id'];
            $status = $_POST['status'] ?? 'review';
            if (!in_array($status, ['pending','review','manual_reissue','completed','rejected'], true)) $status = 'review';
            $note = trim($_POST['admin_note']);
            $s = $db->prepare('SELECT rr.*,l.license_key FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id WHERE rr.id=?');
            $s->execute([$id]);
            $rr = $s->fetch();
            if (!$rr) throw new RuntimeException('Reissue request not found.');
            $db->beginTransaction();
            $db->prepare('UPDATE reissue_requests SET status=?,handled_by=?,admin_note=? WHERE id=?')->execute([$status,$u['id'],$note ?: null,$id]);
            if ($status === 'completed') {
                $db->prepare('UPDATE licenses SET domain=?,status="active" WHERE id=?')->execute([$rr['new_domain'],$rr['license_id']]);
                $db->prepare('INSERT INTO license_history(license_id,user_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?,?)')->execute([$rr['license_id'],$u['id'],'reissue_completed',$rr['current_domain'],$rr['new_domain'],$note ?: null]);
            }
            notify_reseller((int)$rr['reseller_id'],'reissue','Reissue '.$status,'License '.$rr['license_key'].' reissue request is now '.strtoupper($status).'.'.($note ? ' Note: '.$note : ''));
            $db->commit();
            audit('reissue_'.$status,'reissue_requests',$id);
            telegram_notify("📌 <b>REISSUE UPDATED</b>\nLicense: ".e($rr['license_key'])."\nStatus: ".e(strtoupper($status)));
            $msg = 'Reissue updated.';
        }

        if ($a === 'api_key' && can('api.manage', $u)) {
            $rid = (int)$_POST['reseller_id'];
            $plain = 'skynoc_' . bin2hex(random_bytes(24));
            $scopes = [];
            foreach (['licenses:read','reissue:create','reissue:read'] as $scope) if (isset($_POST['scope'][$scope])) $scopes[] = $scope;
            if (!$scopes) $scopes = ['licenses:read'];
            $s = $db->prepare('INSERT INTO api_keys(reseller_id,name,key_prefix,key_hash,scopes,expires_at) VALUES(?,?,?,?,?,?)');
            $s->execute([$rid,trim($_POST['name']) ?: 'API Key',substr($plain,0,15),hash('sha256',$plain),implode(',',$scopes),$_POST['expires_at'] ?: null]);
            $newKey = $plain;
            audit('api_key_created','api_keys',(int)$db->lastInsertId());
        }

        if ($a === 'api_revoke' && can('api.manage', $u)) {
            $id = (int)$_POST['id'];
            $db->prepare('UPDATE api_keys SET status="revoked" WHERE id=?')->execute([$id]);
            audit('api_key_revoked','api_keys',$id);
            $msg = 'API key revoked.';
        }

        if ($a === 'ticket_status' && can('ticket.manage', $u)) {
            $id = (int)$_POST['id'];
            $status = in_array($_POST['status'] ?? '', ['open','pending','closed'], true) ? $_POST['status'] : 'open';
            $db->prepare('UPDATE tickets SET status=? WHERE id=?')->execute([$status,$id]);
            $s=$db->prepare('SELECT reseller_id,subject FROM tickets WHERE id=?');$s->execute([$id]);$t=$s->fetch();
            if($t) notify_reseller((int)$t['reseller_id'],'ticket','Ticket updated',$t['subject'].' is now '.$status.'.');
            audit('ticket_status_changed','tickets',$id,$status);
            $msg = 'Ticket updated.';
        }

        if ($a === 'telegram' && $u['role'] === 'owner') {
            $s = $db->prepare('INSERT INTO telegram_settings(id,bot_token,admin_chat_id) VALUES(1,?,?) ON DUPLICATE KEY UPDATE bot_token=VALUES(bot_token),admin_chat_id=VALUES(admin_chat_id)');
            $s->execute([trim($_POST['bot_token']),trim($_POST['admin_chat_id'])]);
            $msg = 'Telegram settings saved.';
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getCode() === '23000' ? 'This email or value already exists.' : 'The request could not be completed. Please check the server error log.';
    error_log('SkyNoc admin error: ' . $e->getMessage());
}

$providers = $db->query('SELECT id,provider_name,account_email,account_label,status FROM provider_accounts ORDER BY id DESC')->fetchAll();
$resellers = $db->query('SELECT id,name,email,status FROM resellers ORDER BY id DESC')->fetchAll();
$licenses = $db->query('SELECT l.*,r.name reseller_name,p.provider_name FROM licenses l LEFT JOIN resellers r ON r.id=l.reseller_id LEFT JOIN provider_accounts p ON p.id=l.provider_account_id ORDER BY l.id DESC LIMIT 100')->fetchAll();
$reissues = $db->query('SELECT rr.*,l.license_key,r.name reseller_name FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id JOIN resellers r ON r.id=rr.reseller_id ORDER BY rr.id DESC LIMIT 50')->fetchAll();
$staff = $db->query('SELECT id,name,email,role,status,permissions,created_at FROM users WHERE role IN ("admin","manager","staff") ORDER BY id DESC')->fetchAll();
$apiKeys = $db->query('SELECT ak.id,ak.name,ak.key_prefix,ak.scopes,ak.status,ak.last_used_at,ak.expires_at,r.name reseller_name FROM api_keys ak JOIN resellers r ON r.id=ak.reseller_id ORDER BY ak.id DESC LIMIT 100')->fetchAll();
$tickets = $db->query('SELECT t.*,r.name reseller_name FROM tickets t LEFT JOIN resellers r ON r.id=t.reseller_id ORDER BY t.id DESC LIMIT 50')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Admin</title>
<style>*{box-sizing:border-box}body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;margin:0;background:#f6f8fb;color:#0f172a}.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px}.nav a{color:#fff}.wrap{max-width:1400px;margin:24px auto;padding:0 16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:#fff;padding:20px;border-radius:16px;box-shadow:0 4px 20px #0f172a0a;border:1px solid #e5e7eb;margin-bottom:18px}input,select,textarea,button{width:100%;padding:11px;margin:5px 0 9px;border:1px solid #dbe2ea;border-radius:10px;font:inherit}button{background:#111827;color:#fff;border:0;font-weight:700;cursor:pointer}.msg{background:#ecfdf5;color:#166534;padding:12px;border-radius:10px;margin-bottom:15px}.err{background:#fef2f2;color:#991b1b;padding:12px;border-radius:10px;margin-bottom:15px}.secret{background:#fffbeb;color:#92400e;padding:14px;border-radius:10px;word-break:break-all}.table-wrap{overflow-x:auto;border-radius:14px;margin-bottom:24px}table{width:100%;min-width:850px;border-collapse:collapse;background:#fff}td,th{padding:11px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px}.checks label{display:block;margin:7px 0}.inline{display:flex;gap:8px;align-items:center}.inline>*{width:auto;margin:0}@media(max-width:700px){.nav{align-items:flex-start;flex-direction:column}.wrap{padding:0 12px}.card{padding:16px}}
</style></head><body>
<div class="nav"><b>SkyNoc Admin</b><span><?=e($u['name'])?> · <?=e($u['role'])?> · <a href="/dashboard">Dashboard</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Management</h1>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>
<?php if($newKey):?><div class="secret"><b>New API key — copy it now. It will not be shown again:</b><br><?=e($newKey)?></div><?php endif;?>
<div class="grid">
<?php if(can('provider.manage',$u)):?><div class="card"><h3>Add Provider</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="provider"><input name="provider_name" placeholder="Provider name" required><input name="account_email" type="email" placeholder="Internal account email"><input name="account_label" placeholder="Internal label"><select name="provider_type"><option>MANUAL_PARTNER</option><option>WHMCS_API</option></select><textarea name="internal_notes" placeholder="Internal notes"></textarea><button>Add Provider</button></form></div><?php endif;?>
<?php if(can('reseller.manage',$u)):?><div class="card"><h3>Create Reseller</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reseller"><input name="name" placeholder="Reseller name" required><input name="email" type="email" placeholder="Login email" required><input name="password" type="password" placeholder="Temporary password (10+ chars)" required><button>Create Reseller</button></form></div><?php endif;?>
<?php if(can('staff.manage',$u)):?><div class="card"><h3>Create Staff</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="staff"><input name="name" placeholder="Name" required><input name="email" type="email" placeholder="Login email" required><input name="password" type="password" placeholder="Password (10+ chars)" required><select name="role"><option>staff</option><option>manager</option><option>admin</option></select><div class="checks"><?php foreach(['provider.manage','license.manage','reseller.manage','reissue.manage','api.manage','staff.manage','ticket.manage'] as $perm):?><label><input type="checkbox" name="perm[<?=e($perm)?>]" style="width:auto"> <?=e($perm)?></label><?php endforeach;?></div><button>Create Staff</button></form></div><?php endif;?>
<?php if(can('license.manage',$u)):?><div class="card"><h3>Add License</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="license"><input name="license_key" placeholder="License key" required><input name="domain" placeholder="Domain"><select name="provider_account_id"><option value="">Provider</option><?php foreach($providers as $p):?><option value="<?=$p['id']?>"><?=e($p['provider_name'].' — '.$p['account_label'])?></option><?php endforeach;?></select><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select><select name="status"><option>available</option><option>active</option><option>suspended</option><option>expired</option><option>cancelled</option></select><input name="purchase_date" type="date"><input name="cost" type="number" step="0.01" placeholder="Cost"><input name="expires_at" type="datetime-local"><button>Add License</button></form></div><?php endif;?>
<?php if(can('api.manage',$u)):?><div class="card"><h3>Create API Key</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_key"><select name="reseller_id" required><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select><input name="name" placeholder="Key name"><input name="expires_at" type="datetime-local"><div class="checks"><label><input type="checkbox" name="scope[licenses:read]" checked style="width:auto"> licenses:read</label><label><input type="checkbox" name="scope[reissue:create]" checked style="width:auto"> reissue:create</label><label><input type="checkbox" name="scope[reissue:read]" checked style="width:auto"> reissue:read</label></div><button>Generate API Key</button></form></div><?php endif;?>
<?php if($u['role']==='owner'):?><div class="card"><h3>Telegram</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="telegram"><input name="bot_token" placeholder="Bot token" required><input name="admin_chat_id" placeholder="Admin chat ID" required><button>Save Telegram</button></form></div><?php endif;?>
</div>

<h2>Licenses</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Domain</th><th>Provider</th><th>Reseller</th><th>Status</th><th>Controls</th></tr><?php foreach($licenses as $l):?><tr><td><?=$l['id']?></td><td><?=e($l['license_key'])?></td><td><?=e($l['domain'])?></td><td><?=e($l['provider_name'])?></td><td><?=e($l['reseller_name'])?></td><td><?=e($l['status'])?></td><td><?php if(can('license.manage',$u)):?><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="license_status"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="status"><?php foreach(['available','active','suspended','expired','cancelled'] as $st):?><option <?=$l['status']===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select><button>Save</button></form><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="reassign_license"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>" <?=$l['reseller_id']==$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select><button>Assign</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>

<h2>Reissue Requests</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Reseller</th><th>Current → New</th><th>Status</th><th>Action</th></tr><?php foreach($reissues as $r):?><tr><td><?=$r['id']?></td><td><?=e($r['license_key'])?></td><td><?=e($r['reseller_name'])?></td><td><?=e($r['current_domain'])?> → <?=e($r['new_domain'])?></td><td><?=e($r['status'])?></td><td><?php if(can('reissue.manage',$u)):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reissue"><input type="hidden" name="id" value="<?=$r['id']?>"><select name="status"><?php foreach(['review','manual_reissue','completed','rejected'] as $st):?><option><?=$st?></option><?php endforeach;?></select><input name="admin_note" placeholder="Admin note"><button>Update</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>

<?php if(can('staff.manage',$u)):?><h2>Staff</h2><div class="table-wrap"><table><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Permissions</th><th>Action</th></tr><?php foreach($staff as $st):?><tr><td><?=e($st['name'])?></td><td><?=e($st['email'])?></td><td><?=e($st['role'])?></td><td><?=e($st['status'])?></td><td><?=e($st['permissions'] ?: 'role defaults')?></td><td><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="staff_status"><input type="hidden" name="id" value="<?=$st['id']?>"><select name="status"><option>active</option><option>suspended</option><option>disabled</option></select><button>Save</button></form></td></tr><?php endforeach;?></table></div><?php endif;?>

<?php if(can('api.manage',$u)):?><h2>API Keys</h2><div class="table-wrap"><table><tr><th>Reseller</th><th>Name</th><th>Prefix</th><th>Scopes</th><th>Status</th><th>Last Used</th><th>Action</th></tr><?php foreach($apiKeys as $k):?><tr><td><?=e($k['reseller_name'])?></td><td><?=e($k['name'])?></td><td><?=e($k['key_prefix'])?></td><td><?=e($k['scopes'])?></td><td><?=e($k['status'])?></td><td><?=e($k['last_used_at'])?></td><td><?php if($k['status']==='active'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="api_revoke"><input type="hidden" name="id" value="<?=$k['id']?>"><button>Revoke</button></form><?php endif;?></td></tr><?php endforeach;?></table></div><?php endif;?>

<?php if(can('ticket.manage',$u)):?><h2>Support Tickets</h2><div class="table-wrap"><table><tr><th>ID</th><th>Reseller</th><th>Subject</th><th>Priority</th><th>Status</th><th>Action</th></tr><?php foreach($tickets as $t):?><tr><td><?=$t['id']?></td><td><?=e($t['reseller_name'])?></td><td><?=e($t['subject'])?></td><td><?=e($t['priority'])?></td><td><?=e($t['status'])?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_status"><input type="hidden" name="id" value="<?=$t['id']?>"><select name="status"><option>open</option><option>pending</option><option>closed</option></select><button>Save</button></form></td></tr><?php endforeach;?></table></div><?php endif;?>
</div></body></html>