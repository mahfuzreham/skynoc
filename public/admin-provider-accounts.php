<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin','manager','staff']);
if (!can('provider.manage', $u)) { http_response_code(403); exit('Forbidden'); }

$msg = null;
$error = null;
$edit = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'save') {
            $type = trim((string)($_POST['provider_type'] ?? 'MANUAL_PARTNER')) ?: 'MANUAL_PARTNER';
            $name = trim((string)($_POST['provider_name'] ?? ''));
            $email = trim((string)($_POST['account_email'] ?? '')) ?: null;
            $label = trim((string)($_POST['account_label'] ?? '')) ?: null;
            $notes = trim((string)($_POST['internal_notes'] ?? '')) ?: null;
            if ($name === '') throw new RuntimeException('Provider name is required.');

            if ($id > 0) {
                $db->prepare('UPDATE provider_accounts SET provider_type=?,provider_name=?,account_email=?,account_label=?,internal_notes=? WHERE id=?')
                    ->execute([$type,$name,$email,$label,$notes,$id]);
                audit('provider_updated','provider_accounts',$id);
                $msg = 'Provider account updated.';
            } else {
                $db->prepare('INSERT INTO provider_accounts(provider_type,provider_name,account_email,account_label,internal_notes) VALUES(?,?,?,?,?)')
                    ->execute([$type,$name,$email,$label,$notes]);
                audit('provider_created','provider_accounts',(int)$db->lastInsertId());
                $msg = 'Provider account added.';
            }
        }

        if ($action === 'delete') {
            if ($id <= 0) throw new RuntimeException('Invalid provider account.');
            $q = $db->prepare('SELECT COUNT(*) FROM licenses WHERE provider_account_id=?');
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('This provider has licenses assigned to it. Reassign those licenses before deleting the provider.');
            }
            $db->prepare('DELETE FROM provider_accounts WHERE id=?')->execute([$id]);
            audit('provider_deleted','provider_accounts',$id);
            $msg = 'Provider account deleted.';
        }
    }

    if (isset($_GET['edit'])) {
        $q = $db->prepare('SELECT * FROM provider_accounts WHERE id=?');
        $q->execute([(int)$_GET['edit']]);
        $edit = $q->fetch() ?: null;
    }

    $providers = $db->query('SELECT p.*, (SELECT COUNT(*) FROM licenses l WHERE l.provider_account_id=p.id) AS license_count FROM provider_accounts p ORDER BY p.id DESC')->fetchAll();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getMessage();
    $providers = $db->query('SELECT p.*, (SELECT COUNT(*) FROM licenses l WHERE l.provider_account_id=p.id) AS license_count FROM provider_accounts p ORDER BY p.id DESC')->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Provider Accounts - SkyNoc Admin</title>
<style>
body{margin:0;background:#f6f7fb;color:#172033;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1200px;margin:0 auto;padding:28px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.top h1{margin:0;font-size:25px}.btn{display:inline-block;padding:10px 14px;border-radius:9px;border:1px solid #d9deea;background:#fff;color:#172033;text-decoration:none;font-weight:600;cursor:pointer}.btn.primary{background:#172033;color:#fff;border-color:#172033}.btn.danger{color:#b42318;border-color:#f2c5c2}.card{background:#fff;border:1px solid #e4e7ef;border-radius:14px;padding:20px;margin-bottom:20px;box-shadow:0 3px 12px rgba(0,0,0,.04)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:flex;flex-direction:column;gap:6px}.field.full{grid-column:1/-1}label{font-size:13px;font-weight:700;color:#475467}input,select,textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #d6dae3;border-radius:9px;font:inherit;background:#fff}textarea{min-height:90px;resize:vertical}.actions{display:flex;gap:8px;align-items:center}.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:800px}th,td{text-align:left;padding:13px 12px;border-bottom:1px solid #edf0f5;vertical-align:middle}th{font-size:12px;text-transform:uppercase;color:#667085;background:#fafbfc}td{font-size:14px}.muted{color:#667085}.pill{display:inline-block;padding:4px 8px;border-radius:99px;background:#eef2ff;font-size:12px;font-weight:700}.actions form{display:inline}.count{font-weight:700}
@media(max-width:700px){.wrap{padding:16px}.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.card{padding:15px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div><h1>Provider Accounts</h1><div class="muted">Manage WHMCS license source/provider accounts.</div></div>
    <a class="btn" href="/admin/dashboard">← Admin Dashboard</a>
  </div>

  <?php if ($msg): ?><div class="alert ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <h2 style="margin-top:0"><?= $edit ? 'Edit Provider Account' : 'Add Provider Account' ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="grid">
        <div class="field"><label>Provider Type</label><input name="provider_type" value="<?= htmlspecialchars($edit['provider_type'] ?? 'MANUAL_PARTNER') ?>" required></div>
        <div class="field"><label>Provider Name</label><input name="provider_name" value="<?= htmlspecialchars($edit['provider_name'] ?? '') ?>" required></div>
        <div class="field"><label>Account Email</label><input type="email" name="account_email" value="<?= htmlspecialchars($edit['account_email'] ?? '') ?>"></div>
        <div class="field"><label>Account Label</label><input name="account_label" value="<?= htmlspecialchars($edit['account_label'] ?? '') ?>" placeholder="e.g. Main WHMCS Provider"></div>
        <div class="field full"><label>Internal Notes</label><textarea name="internal_notes"><?= htmlspecialchars($edit['internal_notes'] ?? '') ?></textarea></div>
        <div class="field full"><div class="actions"><button class="btn primary" type="submit"><?= $edit ? 'Save Changes' : 'Add Provider' ?></button><?php if ($edit): ?><a class="btn" href="/admin/providers">Cancel</a><?php endif; ?></div></div>
      </div>
    </form>
  </div>

  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px"><h2 style="margin:0">Provider Account List</h2><span class="pill"><?= count($providers) ?> provider(s)</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>ID</th><th>Provider</th><th>Type</th><th>Account Email</th><th>Label</th><th>Licenses</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$providers): ?><tr><td colspan="7" class="muted">No provider accounts found.</td></tr><?php endif; ?>
      <?php foreach ($providers as $p): ?>
        <tr>
          <td>#<?= (int)$p['id'] ?></td><td><strong><?= htmlspecialchars($p['provider_name']) ?></strong></td><td><?= htmlspecialchars($p['provider_type']) ?></td><td><?= htmlspecialchars($p['account_email'] ?: '-') ?></td><td><?= htmlspecialchars($p['account_label'] ?: '-') ?></td><td class="count"><?= (int)$p['license_count'] ?></td>
          <td><div class="actions"><a class="btn" href="/admin/providers?edit=<?= (int)$p['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this provider account?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn danger" type="submit">Delete</button></form></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
</body>
</html>
