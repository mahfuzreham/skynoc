<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin','manager','staff']);
$msg = null; $error = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!can('reseller.manage', $u)) throw new RuntimeException('You do not have permission to review deposits.');
        $id = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['approved','rejected'], true) ? $_POST['status'] : 'rejected';
        $note = trim((string)($_POST['review_note'] ?? '')) ?: null;
        $db->beginTransaction();
        $q = $db->prepare('SELECT * FROM deposit_requests WHERE id=? FOR UPDATE'); $q->execute([$id]); $d = $q->fetch();
        if (!$d || $d['status'] !== 'pending') throw new RuntimeException('Deposit request is no longer pending.');
        if ($status === 'approved') {
            $q = $db->prepare('SELECT status FROM resellers WHERE id=? FOR UPDATE'); $q->execute([$d['reseller_id']]); $rs = $q->fetchColumn();
            if ($rs === false) throw new RuntimeException('Reseller not found.');
            $type = ($rs === 'pending') ? 'activation_deposit' : 'deposit';
            $db->prepare('UPDATE deposit_requests SET status="approved",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
            wallet_credit((int)$d['reseller_id'],(float)$d['amount'],$type,'DEPOSIT-'.$id,'Approved reseller deposit #'.$id,null,$u['id']);
            if ($rs === 'pending') $db->prepare('UPDATE resellers SET status="active" WHERE id=?')->execute([$d['reseller_id']]);
            notify_reseller((int)$d['reseller_id'],'wallet','Deposit approved','Your deposit #'.$id.' has been approved and credited to your wallet.');
        } else {
            $db->prepare('UPDATE deposit_requests SET status="rejected",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
            notify_reseller((int)$d['reseller_id'],'wallet','Deposit rejected','Your deposit #'.$id.' was rejected.'.($note ? ' Note: '.$note : ''));
        }
        $db->commit(); audit('deposit_'.$status,'deposit_requests',$id); $msg = 'Deposit review completed.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = 'The deposit action could not be completed.'; error_log('SkyNoc deposits error: '.$e->getMessage());
}
$deposits = $db->query('SELECT d.*,r.name reseller_name,r.email reseller_email FROM deposit_requests d JOIN resellers r ON r.id=d.reseller_id ORDER BY d.id DESC LIMIT 200')->fetchAll();
$pending = count(array_filter($deposits, fn($d)=>$d['status']==='pending'));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Admin • Deposits</title><link rel="stylesheet" href="/admin-page-ui.css"></head><body>
<header class="nav"><strong>SkyNoc Admin</strong><span><a href="/admin">Dashboard</a> &nbsp; <a href="/admin/packages">Packages</a> &nbsp; <a href="/admin/settings">Settings</a> &nbsp; <a href="/logout">Sign out</a></span></header>
<main class="wrap"><h1>Wallet Deposits</h1><p class="muted">Review reseller wallet funding requests from a dedicated admin page.</p>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>
<section class="card"><h2>Deposit requests <span class="pill"><?=$pending?> pending</span></h2><p class="muted">Approval credits the reseller wallet. Pending reseller accounts are activated after an approved activation deposit.</p><div class="table"><table><thead><tr><th>ID</th><th>Reseller</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Review</th></tr></thead><tbody><?php if(!$deposits):?><tr><td colspan="7">No deposit requests.</td></tr><?php endif;?><?php foreach($deposits as $d):?><tr><td>#<?=$d['id']?><div class="muted"><?=e($d['created_at'] ?? '')?></div></td><td><strong><?=e($d['reseller_name'])?></strong><div class="muted"><?=e($d['reseller_email'])?></div></td><td><strong>$<?=number_format((float)$d['amount'],2)?></strong></td><td><?=e($d['method'])?></td><td><code><?=e($d['reference'] ?: '-')?></code></td><td><span class="pill"><?=e($d['status'])?></span></td><td><?php if($d['status']==='pending' && can('reseller.manage',$u)):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$d['id']?>"><div class="inline"><select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select><input name="review_note" placeholder="Review note"><button>Save review</button></div></form><?php elseif($d['review_note']):?><span class="muted"><?=e($d['review_note'])?></span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section></main><footer class="page-footer">SkyNoc Admin · <a href="/admin">Return to dashboard</a></footer></body></html>