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
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) throw new RuntimeException('Invalid user.');

        if ($action === 'toggle') {
            $q = $db->prepare('SELECT id,name,email,role,status FROM users WHERE id=? LIMIT 1');
            $q->execute([$userId]);
            $target = $q->fetch();
            if (!$target) throw new RuntimeException('User not found.');
            if ((int)$target['id'] === (int)$u['id']) throw new RuntimeException('You cannot disable your own admin account.');
            if (in_array($target['role'], ['owner','admin'], true) && $u['role'] !== 'owner') {
                throw new RuntimeException('Only the owner can change another administrator account.');
            }
            $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
            $db->prepare('UPDATE users SET status=? WHERE id=?')->execute([$newStatus, $userId]);
            audit('user_status_changed','users',$userId,'User status changed to '.$newStatus);
            $msg = 'User status changed to '.ucfirst($newStatus).'.';
        } elseif ($action === 'delete') {
            $db->beginTransaction();
            $q = $db->prepare('SELECT id,name,email,role,status FROM users WHERE id=? FOR UPDATE');
            $q->execute([$userId]);
            $target = $q->fetch();
            if (!$target) throw new RuntimeException('User not found.');
            if ((int)$target['id'] === (int)$u['id']) throw new RuntimeException('You cannot delete your own account.');
            if ($target['role'] === 'owner') throw new RuntimeException('The owner account cannot be deleted.');
            if (in_array($target['role'], ['admin','manager','staff'], true) && $u['role'] !== 'owner') {
                throw new RuntimeException('Only the owner can delete staff/admin accounts.');
            }

            if ($target['role'] === 'reseller') {
                $q = $db->prepare('SELECT id,wallet_balance FROM resellers WHERE user_id=? LIMIT 1');
                $q->execute([$userId]);
                $reseller = $q->fetch();
                if ($reseller) {
                    $rid = (int)$reseller['id'];
                    $q = $db->prepare('SELECT COUNT(*) FROM orders WHERE reseller_id=?');
                    $q->execute([$rid]);
                    $orders = (int)$q->fetchColumn();
                    $q = $db->prepare('SELECT COUNT(*) FROM invoices WHERE reseller_id=?');
                    $q->execute([$rid]);
                    $invoices = (int)$q->fetchColumn();
                    $q = $db->prepare('SELECT COUNT(*) FROM licenses WHERE reseller_id=?');
                    $q->execute([$rid]);
                    $licenses = (int)$q->fetchColumn();
                    if ($orders > 0 || $invoices > 0 || $licenses > 0 || abs((float)$reseller['wallet_balance']) > 0.00001) {
                        throw new RuntimeException('This reseller has financial/history records. Disable the account instead of deleting it.');
                    }
                    $db->prepare('DELETE FROM resellers WHERE id=?')->execute([$rid]);
                }
            }
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
            $db->commit();
            audit('user_deleted','users',$userId,'User account permanently deleted by admin');
            $msg = 'User account deleted successfully.';
        } else {
            throw new RuntimeException('Invalid action.');
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'The user action could not be completed.';
    if (!($e instanceof RuntimeException)) error_log('SkyNoc user management error: '.$e->getMessage());
}

$users = $db->query('SELECT u.id,u.name,u.email,u.role,u.status,u.created_at,(SELECT COUNT(*) FROM resellers r WHERE r.user_id=u.id) reseller_count FROM users u ORDER BY u.id DESC')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>User Management — SkyNoc</title><link rel="stylesheet" href="/admin-page-ui.css"><style>
.hero{background:linear-gradient(135deg,#101828,#172554,#3641f5);color:#fff;border-radius:20px;padding:25px;margin-bottom:18px;box-shadow:0 16px 38px rgba(16,24,40,.14)}.hero h1{margin:7px 0 5px;font-size:28px}.hero p{margin:0;color:#dbe4ff;line-height:1.6}.table-wrap{overflow:auto}.table{min-width:1000px}.actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800}.status.active{background:#ecfdf3;color:#067647}.status.disabled,.status.suspended{background:#fef3f2;color:#b42318}.role{display:inline-flex;padding:4px 8px;border-radius:999px;background:#f2f4f7;color:#344054;font-size:10px;font-weight:800;text-transform:capitalize}.small{font-size:11px;color:#667085}.danger-btn{background:#b42318!important;color:#fff!important}.toggle-btn{background:#465fff!important;color:#fff!important}.add-link{display:inline-flex;margin-top:14px;padding:10px 13px;border-radius:10px;background:#fff;color:#172554;text-decoration:none;font-weight:800;font-size:12px}
</style></head><body><div class="top"><strong>SkyNoc <span style="opacity:.5">/</span> User Management</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/admin/settings">Settings</a> · <a href="/logout">Logout</a></span></div><div class="wrap"><div class="hero"><div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.8">ACCOUNT CONTROL</div><h1>User Management</h1><p>Manage customer, reseller and staff accounts from one admin page. Disable accounts safely or permanently delete eligible accounts.</p><a class="add-link" href="/admin/reseller-add">＋ Add Reseller</a></div><?php if($msg): ?><div class="msg">✓ <?=e($msg)?></div><?php endif; ?><?php if($error): ?><div class="err">! <?=e($error)?></div><?php endif; ?><section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">👤</div><div><h2>All Users</h2><p class="hint">Owner accounts are protected. Reseller deletion is blocked when licenses, orders, invoices or wallet balance exist.</p></div></div></div><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>User</th><th>Role</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody><?php if(!$users): ?><tr><td colspan="6">No users found.</td></tr><?php endif; ?><?php foreach($users as $row): $isSelf=(int)$row['id']===(int)$u['id']; $protectedRole=$row['role']==='owner' || (in_array($row['role'],['admin','manager','staff'],true) && $u['role']!=='owner'); ?><tr><td>#<?=e((string)$row['id'])?></td><td><b><?=e($row['name'])?></b><div class="small"><?=e($row['email'])?></div></td><td><span class="role"><?=e($row['role'])?></span></td><td><span class="status <?=e($row['status'])?>"><?=e(ucfirst($row['status']))?></span></td><td class="small"><?=e($row['created_at'])?></td><td><div class="actions"><?php if(!$isSelf && !$protectedRole): ?><form method="post" onsubmit="return confirm('Change this user status?')"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?=e((string)$row['id'])?>"><button class="toggle-btn" type="submit"><?= $row['status']==='active' ? 'Disable' : 'Enable' ?></button></form><?php endif; ?><?php if(!$isSelf && $row['role']!=='owner' && !$protectedRole): ?><form method="post" onsubmit="return confirm('Permanently delete this user account? This cannot be undone.')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="<?=e((string)$row['id'])?>"><button class="danger-btn" type="submit">Delete</button></form><?php endif; ?><?php if($isSelf): ?><span class="small">Current account</span><?php elseif($protectedRole): ?><span class="small">Owner/Admin protected</span><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section><div class="page-footer">SkyNoc Admin · <a href="/admin">Dashboard</a> · <a href="/admin/reseller-manage">Reseller Management</a> · <a href="/admin/reseller-add">Add Reseller</a> · <a href="/admin/settings">Settings</a></div></div></body></html>
