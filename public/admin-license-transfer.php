<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin']);
$msg = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $targetId = (int)($_POST['target_reseller_id'] ?? 0);
        if ($licenseId <= 0 || $targetId <= 0) throw new RuntimeException('Select a license and destination reseller.');

        $db->beginTransaction();
        $q = $db->prepare('SELECT id,license_key,domain,status,reseller_id FROM licenses WHERE id=? FOR UPDATE');
        $q->execute([$licenseId]);
        $license = $q->fetch();
        if (!$license) throw new RuntimeException('License not found.');
        if ($license['status'] === 'expired' || $license['status'] === 'cancelled') throw new RuntimeException('Expired or cancelled licenses cannot be transferred.');
        if ((int)($license['reseller_id'] ?? 0) === $targetId) throw new RuntimeException('License is already assigned to this reseller.');

        $q = $db->prepare('SELECT id,name,email,status FROM resellers WHERE id=? FOR UPDATE');
        $q->execute([$targetId]);
        $target = $q->fetch();
        if (!$target || $target['status'] !== 'active') throw new RuntimeException('Destination reseller is not active.');

        $oldId = $license['reseller_id'] !== null ? (int)$license['reseller_id'] : null;
        $db->prepare('UPDATE licenses SET reseller_id=? WHERE id=?')->execute([$targetId,$licenseId]);
        $note = 'Admin transfer: '.($oldId ? '#'.$oldId : 'unassigned').' -> #'.$targetId.' by '.$u['name'];
        $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')
            ->execute([$licenseId,$u['id'],'admin_license_transferred',$note]);
        $db->commit();

        if ($oldId) notify_reseller($oldId,'license','License transferred','License '.$license['license_key'].' was transferred to '.$target['name'].' by SkyNoc administration.');
        notify_reseller($targetId,'license','License assigned','License '.$license['license_key'].' was assigned to your reseller account by SkyNoc administration.');
        audit('admin_license_transferred','licenses',$licenseId,$note);
        $msg = 'License transferred successfully to '.$target['name'].'.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getMessage();
}

$licenses = $db->query("SELECT l.id,l.license_key,l.domain,l.status,l.reseller_id,r.name reseller_name FROM licenses l LEFT JOIN resellers r ON r.id=l.reseller_id WHERE l.status NOT IN ('expired','cancelled') ORDER BY l.id DESC LIMIT 500")->fetchAll();
$resellers = $db->query("SELECT id,name,email,status FROM resellers WHERE status='active' ORDER BY name,id")->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin License Transfer — SkyNoc</title>
<style>
:root{--brand:#465fff;--ink:#101828;--muted:#667085;--line:#e4e7ec;--bg:#f7f9fc;--success:#027a48;--danger:#b42318}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:1120px;margin:0 auto;padding:26px 18px 50px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.brand{font-size:19px;font-weight:800}.top a{color:var(--brand);text-decoration:none;font-size:13px;font-weight:700}.hero,.card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 26px rgba(16,24,40,.05)}.hero{padding:25px;margin-bottom:16px;background:linear-gradient(135deg,#111827,#1e3a8a);color:#fff}.hero h1{margin:0 0 7px;font-size:28px}.hero p{margin:0;color:#d0d5dd;font-size:13px;line-height:1.6}.card{padding:20px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end}label{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:#344054}select{width:100%;padding:11px;border:1px solid #d0d5dd;border-radius:9px;background:#fff}button{padding:11px 17px;border:0;border-radius:9px;background:var(--brand);color:#fff;font-weight:800;cursor:pointer}button:hover{background:#3646d9}.notice{padding:12px 14px;border-radius:10px;margin-bottom:15px;font-size:13px}.ok{background:#ecfdf3;border:1px solid #abefc6;color:var(--success)}.err{background:#fef3f2;border:1px solid #fecdca;color:var(--danger)}.table-wrap{overflow:auto}.table{width:100%;min-width:760px;border-collapse:collapse}.table th,.table td{text-align:left;padding:11px 10px;border-bottom:1px solid #f0f2f5;font-size:12px}.table th{font-size:10px;text-transform:uppercase;color:var(--muted)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.muted{color:var(--muted)}@media(max-width:700px){.wrap{padding:16px 12px}.top{align-items:flex-start;gap:10px;flex-direction:column}.hero h1{font-size:24px}.grid{grid-template-columns:1fr}.grid button{width:100%}}
</style></head><body><div class="wrap"><div class="top"><div class="brand">SkyNoc / Admin License Transfer</div><a href="/admin">← Back to Admin</a></div>
<div class="hero"><h1>Transfer License</h1><p>Move a WHMCS license between reseller accounts. The transfer does not change the license key, domain, package or wallet balance.</p></div>
<?php if($msg): ?><div class="notice ok"><?=e($msg)?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?=e($error)?></div><?php endif; ?>
<div class="card"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="transfer"><div class="grid"><div><label>License</label><select name="license_id" required><option value="">Select license</option><?php foreach($licenses as $l): ?><option value="<?=$l['id']?>"><?=e($l['license_key'])?> — <?=e($l['reseller_name'] ?: 'Unassigned')?> — <?=e($l['domain'] ?: 'No domain')?></option><?php endforeach; ?></select></div><div><label>Destination Reseller</label><select name="target_reseller_id" required><option value="">Select reseller</option><?php foreach($resellers as $r): ?><option value="<?=$r['id']?>"><?=e($r['name'])?> — <?=e($r['email'])?></option><?php endforeach; ?></select></div><button type="submit">Transfer License</button></div></form></div>
<div class="card"><strong>Recent licenses</strong><div class="table-wrap"><table class="table"><thead><tr><th>License</th><th>Domain</th><th>Status</th><th>Current Reseller</th></tr></thead><tbody><?php foreach($licenses as $l): ?><tr><td class="mono"><?=e($l['license_key'])?></td><td><?=e($l['domain'] ?: '—')?></td><td><?=e($l['status'])?></td><td><?=e($l['reseller_name'] ?: 'Unassigned')?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div></body></html>