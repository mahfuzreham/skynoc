<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin','manager']);
$msg = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $id = (int)($_POST['reseller_id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid reseller.');
        $db->beginTransaction();
        $q=$db->prepare('SELECT r.id,r.user_id,r.name,r.email,r.status,u.status user_status FROM resellers r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=? FOR UPDATE');
        $q->execute([$id]); $r=$q->fetch();
        if(!$r) throw new RuntimeException('Reseller not found.');
        if($r['status']==='disabled') throw new RuntimeException('Disabled reseller cannot be activated.');
        $db->prepare("UPDATE resellers SET status='active' WHERE id=?")->execute([$id]);
        if($r['user_id']) $db->prepare("UPDATE users SET status='active' WHERE id=? AND role='reseller'")->execute([(int)$r['user_id']]);
        $db->commit(); audit('reseller_manually_activated','resellers',$id,'Admin manually activated reseller account');
        $msg='Reseller #'.$id.' is now active.';
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); $error=$e->getMessage(); }
}
$pending=$db->query("SELECT r.id,r.name,r.email,r.status,r.created_at,r.wallet_balance,u.status user_status FROM resellers r LEFT JOIN users u ON u.id=r.user_id WHERE r.status='pending' OR u.status='suspended' ORDER BY r.id DESC")->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manual Reseller Activation — SkyNoc</title><link rel="stylesheet" href="/admin-page-ui.css"><style>body{background:#f5f7fb}.wrap{max-width:1100px;margin:auto;padding:24px}.hero{background:linear-gradient(135deg,#101828,#344054,#465fff);color:#fff;border-radius:20px;padding:25px;margin-bottom:18px}.hero h1{margin:6px 0}.hero p{margin:0;color:#dbe4ff}.card{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:20px}.msg,.err{padding:12px 14px;border-radius:10px;margin-bottom:14px}.msg{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.table-wrap{overflow:auto}.table{width:100%;min-width:760px;border-collapse:collapse}.table th,.table td{padding:11px;border-bottom:1px solid #eaecf0;text-align:left;font-size:13px}.table th{font-size:11px;color:#667085;text-transform:uppercase}.btn{border:0;border-radius:9px;padding:9px 13px;background:#465fff;color:#fff;font-weight:800;cursor:pointer}.status{font-size:11px;font-weight:800}.top{padding:14px 20px;display:flex;justify-content:space-between}.top a{margin-left:12px}</style></head><body><div class="top"><strong>SkyNoc / Manual Activation</strong><span><a href="/admin/dashboard">Dashboard</a><a href="/admin/reseller-manage">Resellers</a><a href="/logout">Logout</a></span></div><div class="wrap"><div class="hero"><div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.8">ACCOUNT CONTROL</div><h1>Manual Reseller Activation</h1><p>Activate pending reseller accounts manually. This does not add wallet funds; it only changes account access to active.</p></div><?php if($msg): ?><div class="msg">✓ <?=e($msg)?></div><?php endif; ?><?php if($error): ?><div class="err">! <?=e($error)?></div><?php endif; ?><div class="card"><h2>Pending / Restricted Accounts</h2><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Reseller</th><th>Status</th><th>User Status</th><th>Wallet</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach($pending as $r): ?><tr><td>#<?=e((string)$r['id'])?></td><td><b><?=e($r['name'])?></b><br><small><?=e($r['email'])?></small></td><td><?=e($r['status'])?></td><td><?=e($r['user_status'] ?? '-')?></td><td>$<?=number_format((float)$r['wallet_balance'],2)?></td><td><?=e($r['created_at'])?></td><td><form method="post" onsubmit="return confirm('Activate this reseller account?')"><?=csrf_field()?><input type="hidden" name="reseller_id" value="<?=e((string)$r['id'])?>"><button class="btn" type="submit">Activate Account</button></form></td></tr><?php endforeach; ?><?php if(!$pending): ?><tr><td colspan="7">No pending reseller accounts.</td></tr><?php endif; ?></tbody></table></div></div></div></body></html>
