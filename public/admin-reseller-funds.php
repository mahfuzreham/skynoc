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

        if ($action !== 'add_fund') {
            throw new RuntimeException('Invalid request.');
        }

        $resellerId = (int)($_POST['reseller_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $reference = trim((string)($_POST['reference'] ?? '')) ?: null;
        $description = trim((string)($_POST['description'] ?? '')) ?: null;

        if ($resellerId <= 0) throw new RuntimeException('Select a reseller.');
        if ($amount <= 0) throw new RuntimeException('Fund amount must be greater than 0.');
        if ($amount > 1000000) throw new RuntimeException('Fund amount is too large.');
        if ($reference !== null && strlen($reference) > 120) throw new RuntimeException('Reference is too long.');
        if ($description !== null && strlen($description) > 255) throw new RuntimeException('Description is too long.');

        $db->beginTransaction();

        $q = $db->prepare('SELECT id,name,email,status,wallet_balance FROM resellers WHERE id=? FOR UPDATE');
        $q->execute([$resellerId]);
        $reseller = $q->fetch();
        if (!$reseller) throw new RuntimeException('Reseller not found.');
        if (in_array($reseller['status'], ['disabled','suspended'], true)) {
            throw new RuntimeException('This reseller is suspended or disabled.');
        }

        $oldBalance = (float)$reseller['wallet_balance'];
        $newBalance = round($oldBalance + $amount, 2);
        $db->prepare('UPDATE resellers SET wallet_balance=? WHERE id=?')->execute([$newBalance, $resellerId]);

        $finalReference = $reference ?: 'ADMIN-FUND-' . strtoupper(bin2hex(random_bytes(5)));
        $finalDescription = $description ?: 'Manual wallet fund added by admin';
        $db->prepare('INSERT INTO wallet_transactions(reseller_id,type,amount,reference,description,created_by) VALUES(?,?,?,?,?,?)')
            ->execute([$resellerId, 'adjustment', $amount, $finalReference, $finalDescription, $u['id']]);

        notify_reseller(
            $resellerId,
            'wallet',
            'Wallet funded',
            'SkyNoc added $' . number_format($amount, 2) . ' to your reseller wallet. New balance: $' . number_format($newBalance, 2) . '.'
        );

        $db->commit();
        audit('reseller_wallet_funded', 'resellers', $resellerId, 'Amount: ' . number_format($amount, 2, '.', '') . '; Reference: ' . $finalReference);
        $msg = $reseller['name'] . ' wallet funded successfully. New balance: $' . number_format($newBalance, 2) . '.';
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'The wallet update could not be completed.';
    if (!($e instanceof RuntimeException)) error_log('SkyNoc reseller fund error: ' . $e->getMessage());
}

$resellers = $db->query('SELECT id,name,email,status,wallet_balance FROM resellers ORDER BY name ASC,id ASC')->fetchAll();

function fund_badge(string $status): string {
    return match ($status) {
        'active' => '<span class="badge ok">Active</span>',
        'pending' => '<span class="badge pending">Pending</span>',
        default => '<span class="badge off">' . e(ucfirst($status)) . '</span>',
    };
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SkyNoc Admin • Add Reseller Funds</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--brand:#465fff;--brand-dark:#3641f5;--ink:#101828;--muted:#667085;--line:#e4e7ec;--bg:#f8fafc;--card:#fff;--success:#027a48;--success-bg:#ecfdf3;--danger:#b42318;--danger-bg:#fef3f2;--shadow:0 12px 35px rgba(16,24,40,.07)}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#f5f8ff 0,#f8fafc 360px);color:var(--ink);font-family:Outfit,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:28px 20px 60px}.top{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:24px}.brand{display:flex;align-items:center;gap:11px}.mark{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#465fff,#7a8cff);display:grid;place-items:center;color:#fff;font-weight:800;box-shadow:0 8px 20px rgba(70,95,255,.25)}h1{margin:0;font-size:27px;letter-spacing:-.03em}.sub{margin:5px 0 0;color:var(--muted);font-size:13px}.links{display:flex;gap:8px;flex-wrap:wrap}.link,.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:9px;padding:9px 13px;font-weight:700;font-size:12px;text-decoration:none}.link{background:#fff;border:1px solid var(--line);color:#344054}.btn{border:0;background:var(--brand);color:#fff;cursor:pointer}.btn:hover{background:var(--brand-dark)}.layout{display:grid;grid-template-columns:minmax(0,430px) minmax(0,1fr);gap:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:15px;box-shadow:var(--shadow);overflow:hidden}.head{padding:18px 20px;border-bottom:1px solid var(--line)}.head h2{font-size:16px;margin:0}.head p{font-size:12px;color:var(--muted);margin:4px 0 0}.body{padding:20px}.notice{border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px}.success{background:var(--success-bg);border:1px solid #abefc6;color:var(--success)}.error{background:var(--danger-bg);border:1px solid #fecdca;color:var(--danger)}.field{margin-bottom:13px}.field label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:6px}input,select,textarea{width:100%;border:1px solid #d0d5dd;border-radius:9px;background:#fff;padding:10px 11px;font:inherit;color:var(--ink);outline:none}input:focus,select:focus,textarea:focus{border-color:#84adff;box-shadow:0 0 0 3px rgba(70,95,255,.1)}textarea{min-height:86px;resize:vertical}.hint{font-size:11px;color:#98a2b3;margin-top:5px}.summary{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#f8faff;border:1px solid #dbe4ff;border-radius:10px;padding:12px;margin:12px 0 16px}.summary .label{font-size:11px;color:var(--muted)}.summary .value{font-size:18px;font-weight:800;color:var(--brand);margin-top:2px}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:620px}.table th{text-align:left;background:#fcfcfd;color:#667085;text-transform:uppercase;letter-spacing:.08em;font-size:10px;padding:11px 14px;border-bottom:1px solid var(--line)}.table td{padding:13px 14px;border-bottom:1px solid #f2f4f7;font-size:12px;vertical-align:middle}.table tr:last-child td{border-bottom:0}.name{font-weight:700}.email{font-size:11px;color:#98a2b3;margin-top:2px}.money{font-weight:800;font-variant-numeric:tabular-nums}.badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800}.ok{color:#027a48;background:#ecfdf3}.pending{color:#b54708;background:#fffaeb}.off{color:#b42318;background:#fef3f2}.empty{text-align:center;color:#98a2b3;padding:30px}.note{font-size:12px;color:var(--muted);line-height:1.6;margin-top:15px}.note strong{color:#344054}@media(max-width:820px){.layout{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.links{width:100%}.link{flex:1}.wrap{padding:20px 14px 45px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand">
      <div class="mark">S</div>
      <div><h1>Add Reseller Funds</h1><p class="sub">Manually credit a reseller wallet from the admin panel.</p></div>
    </div>
    <div class="links"><a class="link" href="/admin">← Admin Dashboard</a><a class="link" href="/admin/reports">Reports</a></div>
  </div>

  <?php if ($msg): ?><div class="notice success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

  <div class="layout">
    <section class="card">
      <div class="head"><h2>Credit Wallet</h2><p>Funds are added immediately and recorded as an adjustment.</p></div>
      <div class="body">
        <form method="post" autocomplete="off" id="fundForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_fund">
          <div class="field">
            <label for="reseller_id">Reseller</label>
            <select name="reseller_id" id="reseller_id" required>
              <option value="">Select reseller</option>
              <?php foreach ($resellers as $r): ?>
                <option value="<?= (int)$r['id'] ?>" data-balance="<?= e((string)$r['wallet_balance']) ?>" <?= ((int)($_POST['reseller_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>>
                  <?= e($r['name']) ?> — <?= e($r['email']) ?> — $<?= number_format((float)$r['wallet_balance'],2) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="summary"><div><div class="label">Current wallet balance</div><div class="value" id="currentBalance">$0.00</div></div><div>💳</div></div>

          <div class="field"><label for="amount">Amount (USD)</label><input type="number" name="amount" id="amount" min="0.01" max="1000000" step="0.01" placeholder="50.00" required><div class="hint">Example: 50.00 adds $50.00 to the reseller wallet.</div></div>
          <div class="field"><label for="reference">Reference <span style="font-weight:400;color:#98a2b3">(optional)</span></label><input type="text" name="reference" maxlength="120" placeholder="e.g. ADMIN-FUND-001"></div>
          <div class="field"><label for="description">Note <span style="font-weight:400;color:#98a2b3">(optional)</span></label><textarea name="description" maxlength="255" placeholder="Reason for manual funding"></textarea></div>
          <button class="btn" type="submit" onclick="return confirm('Add this amount to the selected reseller wallet?')">Add Funds</button>
        </form>
        <div class="note"><strong>Audit:</strong> every manual credit is saved in <code>wallet_transactions</code> as an <code>adjustment</code> with the admin user ID and an audit log entry. The reseller also receives a wallet notification.</div>
      </div>
    </section>

    <section class="card">
      <div class="head"><h2>Reseller Wallets</h2><p>Current balances and account status.</p></div>
      <div class="table-wrap">
        <?php if (!$resellers): ?>
          <div class="empty">No reseller accounts found.</div>
        <?php else: ?>
          <table class="table">
            <thead><tr><th>Reseller</th><th>Status</th><th>Wallet Balance</th></tr></thead>
            <tbody>
              <?php foreach ($resellers as $r): ?>
                <tr>
                  <td><div class="name"><?= e($r['name']) ?></div><div class="email"><?= e($r['email']) ?></div></td>
                  <td><?= fund_badge((string)$r['status']) ?></td>
                  <td class="money">$<?= number_format((float)$r['wallet_balance'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
<script>
const resellerSelect=document.getElementById('reseller_id');
const balance=document.getElementById('currentBalance');
function syncBalance(){const o=resellerSelect.options[resellerSelect.selectedIndex];const n=parseFloat(o?.dataset.balance||'0')||0;balance.textContent='$'+n.toFixed(2)}
resellerSelect.addEventListener('change',syncBalance);syncBalance();
</script>
</body>
</html>
