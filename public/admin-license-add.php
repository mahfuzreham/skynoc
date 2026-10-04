<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin','manager','staff']);
if (!can('license.manage', $u)) { http_response_code(403); exit('Forbidden'); }

$msg = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $licenseKey = trim((string)($_POST['license_key'] ?? ''));
        $domain = trim((string)($_POST['domain'] ?? '')) ?: null;
        $resellerId = ($_POST['reseller_id'] ?? '') === '' ? null : (int)$_POST['reseller_id'];
        $providerId = ($_POST['provider_account_id'] ?? '') === '' ? null : (int)$_POST['provider_account_id'];
        $status = in_array($_POST['status'] ?? '', ['available','active'], true) ? $_POST['status'] : 'available';
        $purchaseDate = trim((string)($_POST['purchase_date'] ?? '')) ?: date('Y-m-d');
        $cost = ($_POST['cost'] ?? '') === '' ? null : (float)$_POST['cost'];
        $expiresAt = trim((string)($_POST['expires_at'] ?? '')) ?: null;

        if ($licenseKey === '') throw new RuntimeException('License key is required.');
        if ($status === 'active' && !$resellerId) throw new RuntimeException('Select a reseller when adding an Active license.');
        if ($cost !== null && $cost < 0) throw new RuntimeException('Cost cannot be negative.');

        $db->beginTransaction();
        $q = $db->prepare('SELECT id FROM licenses WHERE license_key=? LIMIT 1');
        $q->execute([$licenseKey]);
        if ($q->fetchColumn()) throw new RuntimeException('This license key already exists.');

        $db->prepare('INSERT INTO licenses(provider_account_id,license_key,domain,reseller_id,status,purchase_date,cost,expires_at) VALUES(?,?,?,?,?,?,?,?)')
            ->execute([$providerId,$licenseKey,$domain,$resellerId,$status,$purchaseDate,$cost,$expiresAt]);
        $licenseId = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')
            ->execute([$licenseId,$u['id'],'license_added','Added manually as '.$status.($resellerId ? '; assigned to reseller #'.$resellerId : '')]);

        if ($resellerId) {
            notify_reseller($resellerId,'license','License added','A license has been added to your SkyNoc account.'.($domain ? ' Domain: '.$domain : ''));
        }
        $db->commit();
        audit('license_created','licenses',$licenseId,'manual');
        $msg = 'License added successfully.'.($status === 'active' ? ' It is Active and assigned to the selected reseller.' : ' It is Available in inventory.');
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getMessage();
    error_log('SkyNoc manual license add error: '.$e->getMessage());
}

$providers = $db->query('SELECT id,provider_name,account_email,account_label FROM provider_accounts WHERE status="active" ORDER BY id DESC')->fetchAll();
$resellers = $db->query('SELECT id,name,email,status FROM resellers WHERE status="active" ORDER BY name,id')->fetchAll();
$recent = $db->query('SELECT l.id,l.license_key,l.domain,l.status,l.purchase_date,l.expires_at,r.name reseller_name,p.provider_name FROM licenses l LEFT JOIN resellers r ON r.id=l.reseller_id LEFT JOIN provider_accounts p ON p.id=l.provider_account_id ORDER BY l.id DESC LIMIT 20')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Add License — SkyNoc Admin</title>
<link rel="stylesheet" href="/admin-page-ui.css">
<style>
.hero{background:linear-gradient(135deg,#101828,#172554,#3641f5);color:#fff;border-radius:20px;padding:25px;margin-bottom:18px;box-shadow:0 16px 38px rgba(16,24,40,.14)}
.hero h1{margin:7px 0 5px;font-size:28px}.hero p{margin:0;color:#dbe4ff;line-height:1.6}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:15px}.info{padding:12px 14px;border:1px solid #d0d5dd;border-radius:11px;background:#f8fafc;color:#475467;font-size:12px;line-height:1.65;margin-bottom:15px}.success{padding:12px 14px;border:1px solid #abefc6;background:#ecfdf3;color:#067647;border-radius:11px;margin-bottom:15px}.error{padding:12px 14px;border:1px solid #fecdca;background:#fef3f2;color:#b42318;border-radius:11px;margin-bottom:15px}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.actions button,.actions a{min-width:145px;text-align:center}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:760px}.table th,.table td{padding:11px 12px;border-bottom:1px solid #eaecf0;text-align:left;font-size:12px}.table th{font-size:10px;text-transform:uppercase;color:#667085;letter-spacing:.06em}.badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:700}.active{background:#ecfdf3;color:#067647}.available{background:#f2f4f7;color:#475467}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}@media(max-width:720px){.grid2{grid-template-columns:1fr}.hero{padding:20px 17px}.hero h1{font-size:24px}.actions>*{width:100%}}
</style>
</head>
<body>
<div class="top"><strong>SkyNoc <span style="opacity:.5">/</span> Add License</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a></span></div>
<div class="wrap">
<div class="hero"><div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.8">LICENSE INVENTORY</div><h1>Manually Add WHMCS License</h1><p>Add a license purchased from your provider, optionally assign it to a reseller, and choose whether it should immediately appear as <b>Active</b>.</p></div>
<?php if($msg):?><div class="success">✓ <?=e($msg)?></div><?php endif;?>
<?php if($error):?><div class="error">! <?=e($error)?></div><?php endif;?>
<section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">＋</div><div><h2>Add license</h2><p class="hint">For a license that should be immediately usable by a reseller, select <b>Active</b> and choose that reseller.</p></div></div></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="license">
<div class="grid2">
<div class="field"><label>License Key *</label><input name="license_key" required placeholder="WHMCS license key"></div>
<div class="field"><label>Domain</label><input name="domain" placeholder="billing.example.com"></div>
<div class="field"><label>Status *</label><select name="status" required><option value="available">Available — inventory</option><option value="active">Active — assigned/usable</option></select></div>
<div class="field"><label>Assign to reseller</label><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?> — <?=e($r['email'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Provider account</label><select name="provider_account_id"><option value="">Manual / no provider record</option><?php foreach($providers as $p):?><option value="<?=$p['id']?>"><?=e($p['provider_name'])?><?= $p['account_email'] ? ' — '.e($p['account_email']) : '' ?></option><?php endforeach;?></select></div>
<div class="field"><label>Purchase date</label><input type="date" name="purchase_date" value="<?=e(date('Y-m-d'))?>"></div>
<div class="field"><label>Cost (USD)</label><input type="number" name="cost" step="0.01" min="0" placeholder="0.00"></div>
<div class="field"><label>Expiry date / time</label><input type="datetime-local" name="expires_at"></div>
</div>
<div class="info" style="margin-top:15px"><b>Active flow:</b> If you choose <b>Active</b> + a reseller, the license is inserted as active and assigned to that reseller. The reseller will receive a notification. If you choose <b>Available</b>, it stays in inventory and can later be assigned from the admin dashboard.</div>
<div class="actions"><button type="submit">Add License</button><a class="button-link" href="/admin">← Back to Admin</a></div>
</form></section>
<section class="card" style="margin-top:18px"><div class="section-head"><div class="section-title"><div class="section-icon">▥</div><div><h2>Recent licenses</h2><p class="hint">Quick view of the latest inventory records.</p></div></div></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>License</th><th>Domain</th><th>Status</th><th>Reseller</th><th>Provider</th><th>Added</th></tr></thead><tbody><?php if(!$recent):?><tr><td colspan="7">No licenses yet.</td></tr><?php endif;?><?php foreach($recent as $l):?><tr><td>#<?=$l['id']?></td><td class="mono"><?=e($l['license_key'])?></td><td><?=e($l['domain'] ?: '-')?></td><td><span class="badge <?=e($l['status'])?>"><?=e($l['status'])?></span></td><td><?=e($l['reseller_name'] ?: 'Unassigned')?></td><td><?=e($l['provider_name'] ?: 'Manual')?></td><td><?=e($l['purchase_date'] ?: '-')?></td></tr><?php endforeach;?></tbody></table></div></section>
<div class="page-footer">SkyNoc Admin · <a href="/admin">Dashboard</a> · License inventory</div>
</div></body></html>
