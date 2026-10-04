<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin']);
$msg = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        $resellerId = (int)($_POST['reseller_id'] ?? 0);
        if ($resellerId <= 0) throw new RuntimeException('Invalid reseller.');

        if ($action === 'impersonate') {
            $q = $db->prepare('SELECT r.id,r.user_id,r.name,r.email,r.status,u.status user_status FROM resellers r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=? LIMIT 1');
            $q->execute([$resellerId]);
            $r = $q->fetch();
            if (!$r || !$r['user_id']) throw new RuntimeException('Reseller login account not found.');
            if ($r['status'] !== 'active' || $r['user_status'] !== 'active') throw new RuntimeException('This reseller account is not active.');
            $targetUserId = (int)$r['user_id'];
            audit('reseller_impersonation_started','resellers',$resellerId,'Admin signed in as reseller '.$r['email']);
            session_regenerate_id(true);
            $_SESSION['impersonating_admin_id'] = (int)$u['id'];
            $_SESSION['impersonating_reseller_id'] = $resellerId;
            $_SESSION['user_id'] = $targetUserId;
            redirect('/reseller');
        }

        if ($action === 'delete') {
            $db->beginTransaction();
            $q = $db->prepare('SELECT id,user_id,name,email,status,wallet_balance FROM resellers WHERE id=? FOR UPDATE');
            $q->execute([$resellerId]);
            $r = $q->fetch();
            if (!$r) throw new RuntimeException('Reseller not found.');

            $q = $db->prepare('SELECT COUNT(*) FROM orders WHERE reseller_id=?');
            $q->execute([$resellerId]);
            $orders = (int)$q->fetchColumn();
            $q = $db->prepare('SELECT COUNT(*) FROM invoices WHERE reseller_id=?');
            $q->execute([$resellerId]);
            $invoices = (int)$q->fetchColumn();

            if ($orders > 0 || $invoices > 0) {
                throw new RuntimeException('This reseller has order/invoice history and cannot be permanently deleted. Disable the account instead.');
            }
            if (abs((float)$r['wallet_balance']) > 0.00001) {
                throw new RuntimeException('This reseller has a wallet balance. Clear/refund the balance before permanent deletion.');
            }

            $userId = $r['user_id'] !== null ? (int)$r['user_id'] : null;
            $db->prepare('DELETE FROM resellers WHERE id=?')->execute([$resellerId]);
            if ($userId) $db->prepare('DELETE FROM users WHERE id=? AND role="reseller"')->execute([$userId]);
            $db->commit();

            audit('reseller_deleted','resellers',$resellerId,'Permanent reseller deletion by admin');
            $msg = 'Reseller account deleted successfully.';
        } elseif ($action !== 'impersonate') {
            throw new RuntimeException('Invalid action.');
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'The reseller action could not be completed.';
    if (!($e instanceof RuntimeException)) error_log('SkyNoc reseller management error: '.$e->getMessage());
}

$resellers = $db->query('SELECT r.id,r.user_id,r.name,r.email,r.status,r.wallet_balance,r.created_at,(SELECT COUNT(*) FROM licenses l WHERE l.reseller_id=r.id) license_count,(SELECT COUNT(*) FROM orders o WHERE o.reseller_id=r.id) order_count,(SELECT COUNT(*) FROM invoices i WHERE i.reseller_id=r.id) invoice_count FROM resellers r ORDER BY r.id DESC')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reseller Management — SkyNoc</title>
<link rel="stylesheet" href="/admin-page-ui.css">
<style>
.hero{background:linear-gradient(135deg,#101828,#172554,#3641f5);color:#fff;border-radius:20px;padding:25px;margin-bottom:18px;box-shadow:0 16px 38px rgba(16,24,40,.14)}.hero h1{margin:7px 0 5px;font-size:28px}.hero p{margin:0;color:#dbe4ff;line-height:1.6}.danger-btn{background:#b42318!important;color:#fff!important;min-width:105px!important}.danger-btn:hover{background:#912018!important}.login-btn{background:#465fff!important;color:#fff!important;min-width:105px!important}.login-btn:hover{background:#3641f5!important}.status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800}.status.active{background:#ecfdf3;color:#067647}.status.pending{background:#fffaeb;color:#b54708}.status.disabled,.status.suspended{background:#fef3f2;color:#b42318}.small{font-size:11px;color:#667085}.table-wrap{overflow:auto}.table{min-width:1150px}.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>
<div class="top"><strong>SkyNoc <span style="opacity:.5">/</span> Reseller Management</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/admin/reseller-add">Add Reseller</a> · <a href="/admin/invoice-settings">Invoice Settings</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap">
<div class="hero"><div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.8">RESELLER CONTROL</div><h1>Manage Resellers</h1><p>View accounts, open a reseller session for support/testing, and safely remove unused reseller accounts.</p></div>
<?php if($msg): ?><div class="msg">✓ <?=e($msg)?></div><?php endif; ?>
<?php if($error): ?><div class="err">! <?=e($error)?></div><?php endif; ?>
<section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">👥</div><div><h2>Reseller Accounts</h2><p class="hint">“Login as Reseller” switches this browser session to the selected active reseller. Logout returns you to the admin account.</p></div></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Reseller</th><th>Status</th><th>Wallet</th><th>Licenses</th><th>Orders</th><th>Invoices</th><th>Created</th><th>Action</th></tr></thead><tbody>
<?php if(!$resellers): ?><tr><td colspan="9">No reseller accounts found.</td></tr><?php endif; ?>
<?php foreach($resellers as $r): $canDelete=((int)$r['order_count']===0 && (int)$r['invoice_count']===0 && abs((float)$r['wallet_balance'])<0.00001); $canLogin=($r['status']==='active' && !empty($r['user_id'])); ?>
<tr>
<td>#<?=e((string)$r['id'])?></td>
<td><b><?=e($r['name'])?></b><div class="small"><?=e($r['email'])?></div></td>
<td><span class="status <?=e($r['status'])?>"><?=e(ucfirst($r['status']))?></span></td>
<td><b>$<?=number_format((float)$r['wallet_balance'],2)?></b></td>
<td><?=number_format((int)$r['license_count'])?></td>
<td><?=number_format((int)$r['order_count'])?></td>
<td><?=number_format((int)$r['invoice_count'])?></td>
<td class="small"><?=e($r['created_at'])?></td>
<td><div class="actions">
<?php if($canLogin): ?><form method="post" onsubmit="return confirm('Login as <?=e(addslashes($r['name']))?>? Your admin session will be safely saved and restored when you log out.')"><?=csrf_field()?><input type="hidden" name="action" value="impersonate"><input type="hidden" name="reseller_id" value="<?=e((string)$r['id'])?>"><button class="login-btn" type="submit">Login as Reseller</button></form><?php else: ?><span class="small">Login unavailable</span><?php endif; ?>
<?php if($canDelete): ?><form method="post" onsubmit="return confirm('Permanently delete this reseller account? This cannot be undone.')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="reseller_id" value="<?=e((string)$r['id'])?>"><button class="danger-btn" type="submit">Delete</button></form><?php else: ?><span class="small" title="Orders, invoices or wallet balance prevent permanent deletion.">Protected</span><?php endif; ?>
</div></td>
</tr>
<?php endforeach; ?></tbody></table></div></section>
<div class="page-footer">SkyNoc Admin · <a href="/admin">Dashboard</a> · <a href="/admin/reseller-add">Add Reseller</a> · <a href="/admin/reseller-funds">Manual Funds</a> · <a href="/admin/invoice-settings">Invoice Settings</a></div>
</div></body></html>
