<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['reseller']);
$s = $db->prepare('SELECT id FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]);
$r = $s->fetch();
if (!$r) exit('Reseller profile not found.');
$rid = (int)$r['id'];
$msg = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'reissue') {
            $s = $db->prepare('SELECT id,domain FROM licenses WHERE id=? AND reseller_id=?');
            $s->execute([(int)$_POST['license_id'],$rid]);
            $l = $s->fetch();
            if (!$l || !trim($_POST['new_domain'])) throw new RuntimeException('License not found or new domain is empty.');
            $q = $db->prepare('INSERT INTO reissue_requests(license_id,reseller_id,current_domain,new_domain,reason,status) VALUES(?,?,?,?,?,"pending")');
            $q->execute([$l['id'],$rid,$l['domain'],trim($_POST['new_domain']),trim($_POST['reason']) ?: null]);
            $id=(int)$db->lastInsertId();
            telegram_notify("🔔 <b>NEW REISSUE REQUEST</b>\nReseller: ".e($u['name'])."\nRequest: ".$id."\nLicense: ".e((string)$l['id'])."\nCurrent: ".e($l['domain'] ?? '-')."\nNew: ".e(trim($_POST['new_domain'])));
            $msg='Reissue request submitted.';
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

$ticketMessages=[];
foreach($tickets as $t){$q=$db->prepare('SELECT tm.id,tm.message,tm.created_at,u.name,u.role FROM ticket_messages tm LEFT JOIN users u ON u.id=tm.user_id WHERE tm.ticket_id=? ORDER BY tm.id ASC');$q->execute([$t['id']]);$ticketMessages[$t['id']]=$q->fetchAll();}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Reseller</title>
<style>*{box-sizing:border-box}body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8fb;margin:0;color:#0f172a}.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px}.nav a{color:#fff;text-decoration:none}.wrap{max-width:1200px;margin:25px auto;padding:0 16px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.card{background:#fff;padding:20px;border-radius:16px;margin-bottom:18px;border:1px solid #e5e7eb;box-shadow:0 4px 20px #0f172a0a}table{width:100%;min-width:700px;border-collapse:collapse;background:#fff}td,th{padding:12px;border-bottom:1px solid #eef2f7;text-align:left;font-size:13px}.table-wrap{overflow-x:auto;border-radius:14px;margin-bottom:18px}input,textarea,select,button{padding:12px;width:100%;margin:5px 0 9px;border:1px solid #dbe2ea;border-radius:10px;font:inherit}button{background:#111827;color:#fff;border:0;font-weight:700}.msg{background:#ecfdf5;color:#166534;padding:12px;border-radius:10px;margin-bottom:15px}.err{background:#fef2f2;color:#991b1b;padding:12px;border-radius:10px;margin-bottom:15px}.notice{padding:12px;border-radius:10px;background:#eff6ff;margin:8px 0}.unread{border-left:4px solid #111827}@media(max-width:700px){.nav{align-items:flex-start;flex-direction:column}.wrap{padding:0 12px}.card{padding:16px}}
</style></head><body>
<div class="nav"><b>SkyNoc Reseller Portal</b><span><?=e($u['name'])?> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Reseller Panel</h1>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>

<h2>My Licenses</h2><div class="table-wrap"><table><tr><th>License</th><th>Domain</th><th>Status</th><th>Expires</th></tr><?php foreach($licenses as $l):?><tr><td><?=e($l['license_key'])?></td><td><?=e($l['domain'])?></td><td><?=e($l['status'])?></td><td><?=e($l['expires_at'])?></td></tr><?php endforeach;?></table></div>

<div class="grid"><div class="card"><h3>Request Reissue</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reissue"><select name="license_id" required><?php foreach($licenses as $l):?><option value="<?=$l['id']?>"><?=e($l['license_key'].' — '.$l['domain'])?></option><?php endforeach;?></select><input name="new_domain" placeholder="New domain" required><textarea name="reason" placeholder="Reason"></textarea><button>Submit Reissue</button></form></div>
<div class="card"><h3>Open Support Ticket</h3><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_create"><input name="subject" placeholder="Subject" required><select name="priority"><option>normal</option><option>low</option><option>high</option><option>urgent</option></select><textarea name="message" placeholder="Describe your issue" required></textarea><button>Create Ticket</button></form></div></div>

<h2>Reissue History</h2><div class="table-wrap"><table><tr><th>ID</th><th>License</th><th>Current</th><th>New</th><th>Status</th><th>Created</th></tr><?php foreach($reissues as $rr):?><tr><td><?=$rr['id']?></td><td><?=e($rr['license_key'])?></td><td><?=e($rr['current_domain'])?></td><td><?=e($rr['new_domain'])?></td><td><?=e($rr['status'])?></td><td><?=e($rr['created_at'])?></td></tr><?php endforeach;?></table></div>

<h2>API Credentials</h2><div class="table-wrap"><table><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Status</th><th>Last Used</th><th>Expires</th></tr><?php foreach($apiKeys as $k):?><tr><td><?=e($k['name'])?></td><td><?=e($k['key_prefix')?>…</td><td><?=e($k['scopes'])?></td><td><?=e($k['status'])?></td><td><?=e($k['last_used_at'])?></td><td><?=e($k['expires_at'])?></td></tr><?php endforeach;?></table></div>
<p>API keys are only shown in full once, when an admin generates them. Never share them publicly.</p>

<h2>Notifications</h2><div class="card"><?php foreach($notifications as $n):?><div class="notice <?=$n['read_at']?'':'unread'?>"><b><?=e($n['title'])?></b><br><?=nl2br(e($n['message']))?><br><small><?=e($n['created_at'])?></small><?php if(!$n['read_at']):?><form method="post" style="margin-top:7px"><?=csrf_field()?><input type="hidden" name="action" value="notification_read"><input type="hidden" name="id" value="<?=$n['id']?>"><button>Mark read</button></form><?php endif;?></div><?php endforeach;?></div>

<h2>Support</h2><?php foreach($tickets as $t):?><div class="card"><h3>#<?=$t['id']?> — <?=e($t['subject'])?></h3><p>Status: <b><?=e($t['status'])?></b> · Priority: <?=e($t['priority'])?></p><?php foreach($ticketMessages[$t['id']] as $tm):?><div class="notice"><b><?=e($tm['name'] ?: 'User')?></b> (<?=e($tm['role'] ?: 'user')?>)<br><?=nl2br(e($tm['message']))?><br><small><?=e($tm['created_at'])?></small></div><?php endforeach;?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?=$t['id']?>"><textarea name="message" placeholder="Reply" required></textarea><button>Reply</button></form></div><?php endforeach;?>
</div></body></html>