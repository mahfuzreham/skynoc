<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['reseller']);
$q = $db->prepare('SELECT id,status FROM resellers WHERE user_id=? LIMIT 1');
$q->execute([$u['id']]);
$source = $q->fetch();
if (!$source) exit('Reseller profile not found.');
$sourceId = (int)$source['id'];
$msg = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $licenseId = (int)($_POST['license_id'] ?? 0);
        $targetId = (int)($_POST['target_reseller_id'] ?? 0);
        if ($source['status'] !== 'active') throw new RuntimeException('Your reseller account must be active to transfer a license.');
        if ($licenseId <= 0 || $targetId <= 0 || $targetId === $sourceId) throw new RuntimeException('Select a valid destination reseller and license.');

        $db->beginTransaction();
        $q = $db->prepare('SELECT id,license_key,domain,status,reseller_id FROM licenses WHERE id=? FOR UPDATE');
        $q->execute([$licenseId]);
        $license = $q->fetch();
        if (!$license || (int)$license['reseller_id'] !== $sourceId) throw new RuntimeException('This license does not belong to your reseller account.');
        if ($license['status'] !== 'active') throw new RuntimeException('Only active licenses can be transferred.');

        $q = $db->prepare('SELECT id,name,email,status FROM resellers WHERE id=? FOR UPDATE');
        $q->execute([$targetId]);
        $target = $q->fetch();
        if (!$target || $target['status'] !== 'active') throw new RuntimeException('Destination reseller is not active.');

        $db->prepare('UPDATE licenses SET reseller_id=? WHERE id=?')->execute([$targetId,$licenseId]);
        $note = 'Reseller transfer: #'.$sourceId.' -> #'.$targetId;
        $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')
            ->execute([$licenseId,$u['id'],'reseller_transferred',$note]);
        $db->commit();

        notify_reseller($sourceId,'license','License transferred','License '.$license['license_key'].' was transferred to '.$target['name'].'.');
        notify_reseller($targetId,'license','License received','License '.$license['license_key'].' was transferred to your reseller account by '.$u['name'].'.');
        audit('reseller_license_transferred','licenses',$licenseId,$note);
        $msg = 'License transferred successfully to '.$target['name'].'.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getMessage();
}

$q = $db->prepare("SELECT id,license_key,domain,status,expires_at FROM licenses WHERE reseller_id=? AND status='active' ORDER BY id DESC");
$q->execute([$sourceId]);
$licenses = $q->fetchAll();
$targets = $db->prepare("SELECT id,name,email FROM resellers WHERE status='active' AND id<>? ORDER BY name,id");
$targets->execute([$sourceId]);
$targets = $targets->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Transfer License — SkyNoc</title>
<style>
:root{--brand:#465fff;--ink:#101828;--muted:#667085;--line:#e4e7ec;--bg:#f7f9fc;--success:#027a48;--danger:#b42318}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:920px;margin:0 auto;padding:28px 18px 50px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.brand{font-size:19px;font-weight:800}.top a{color:var(--brand);text-decoration:none;font-size:13px;font-weight:700}.hero,.card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 8px 26px rgba(16,24,40,.05)}.hero{padding:26px;margin-bottom:16px;background:linear-gradient(135deg,#111827,#1e3a8a);color:#fff}.hero h1{margin:0 0 7px;font-size:28px}.hero p{margin:0;color:#d0d5dd;line-height:1.6;font-size:13px}.card{padding:20px}.notice{padding:12px 14px;border-radius:10px;margin-bottom:15px;font-size:13px}.ok{background:#ecfdf3;border:1px solid #abefc6;color:var(--success)}.err{background:#fef3f2;border:1px solid #fecdca;color:var(--danger)}label{display:block;font-size:12px;font-weight:700;margin:0 0 6px;color:#344054}select{width:100%;padding:11px;border:1px solid #d0d5dd;border-radius:9px;background:#fff;margin-bottom:15px}button{width:100%;padding:12px;border:0;border-radius:9px;background:var(--brand);color:#fff;font-weight:800;cursor:pointer}button:hover{background:#3646d9}.hint{font-size:11px;color:var(--muted);margin:-7px 0 15px}.table{width:100%;border-collapse:collapse;margin-top:20px}.table th,.table td{text-align:left;padding:11px 10px;border-bottom:1px solid #f0f2f5;font-size:12px}.table th{font-size:10px;text-transform:uppercase;color:var(--muted)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.empty{text-align:center;padding:25px;color:var(--muted)}@media(max-width:600px){.wrap{padding:16px 12px}.hero h1{font-size:24px}.top{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="wrap"><div class="top"><div class="brand">SkyNoc / License Transfer</div><a href="/reseller">← Back to Reseller Dashboard</a></div>
<div class="hero"><h1>Transfer License</h1><p>Transfer an active WHMCS license from your reseller account to another active SkyNoc reseller. Wallet balances are not changed.</p></div>
<?php if($msg): ?><div class="notice ok"><?=e($msg)?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?=e($error)?></div><?php endif; ?>
<div class="card"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="transfer"><label>License</label><select name="license_id" required><option value="">Select active license</option><?php foreach($licenses as $l): ?><option value="<?=$l['id']?>"><?=e($l['license_key'])?> — <?=e($l['domain'] ?: 'No domain')?></option><?php endforeach; ?></select><div class="hint">Only active licenses owned by your reseller account are shown.</div><label>Transfer To</label><select name="target_reseller_id" required><option value="">Select destination reseller</option><?php foreach($targets as $t): ?><option value="<?=$t['id']?>"><?=e($t['name'])?> — <?=e($t['email'])?></option><?php endforeach; ?></select><div class="hint">The destination reseller must be active.</div><button type="submit">Transfer License</button></form>
<?php if(!$licenses): ?><div class="empty">You currently have no active licenses available for transfer.</div><?php endif; ?>
</div></div></body></html>