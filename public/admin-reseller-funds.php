<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin']);
$msg = null;
$error = null;

/* One-time form token prevents browser reload/back/duplicate-submit from replaying a wallet mutation. */
if (empty($_SESSION['wallet_adjust_token'])) {
    $_SESSION['wallet_adjust_token'] = bin2hex(random_bytes(32));
}
$formToken = (string)$_SESSION['wallet_adjust_token'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $postedToken = (string)($_POST['wallet_adjust_token'] ?? '');
        if ($postedToken === '' || !hash_equals($formToken, $postedToken)) {
            throw new RuntimeException('This wallet form has already been submitted or expired. Please use the current form.');
        }
        unset($_SESSION['wallet_adjust_token']);

        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'adjust_fund') throw new RuntimeException('Invalid wallet request.');

        $resellerId = (int)($_POST['reseller_id'] ?? 0);
        $direction = (string)($_POST['direction'] ?? 'credit');
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $reference = trim((string)($_POST['reference'] ?? '')) ?: null;
        $description = trim((string)($_POST['description'] ?? '')) ?: null;

        if ($resellerId <= 0) throw new RuntimeException('Select a reseller.');
        if (!in_array($direction, ['credit','debit'], true)) throw new RuntimeException('Invalid transaction type.');
        if ($amount <= 0) throw new RuntimeException('Amount must be greater than 0.');
        if ($amount > 1000000) throw new RuntimeException('Amount is too large.');
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

        if ($reference !== null) {
            $rq = $db->prepare('SELECT id FROM wallet_transactions WHERE reseller_id=? AND reference=? LIMIT 1');
            $rq->execute([$resellerId, $reference]);
            if ($rq->fetchColumn()) throw new RuntimeException('This reference has already been used for this reseller.');
        }

        $oldBalance = (float)$reseller['wallet_balance'];
        if ($direction === 'credit') {
            $newBalance = round($oldBalance + $amount, 2);
            $finalReference = $reference ?: 'ADMIN-CREDIT-' . strtoupper(bin2hex(random_bytes(6)));
            $finalDescription = $description ?: 'Manual wallet credit added by admin';
            wallet_credit($resellerId, $amount, 'adjustment', $finalReference, $finalDescription, null, (int)$u['id']);
            $title = 'Wallet credited';
            $message = 'SkyNoc added $' . number_format($amount, 2) . ' to your reseller wallet. New balance: $' . number_format($newBalance, 2) . '.';
            $auditAction = 'reseller_wallet_credited';
        } else {
            if ($oldBalance < $amount) throw new RuntimeException('Insufficient reseller wallet balance. Current balance: $' . number_format($oldBalance, 2) . '.');
            $newBalance = round($oldBalance - $amount, 2);
            $finalReference = $reference ?: 'ADMIN-DEBIT-' . strtoupper(bin2hex(random_bytes(6)));
            $finalDescription = $description ?: 'Manual wallet debit by admin';
            wallet_debit($resellerId, $amount, 'adjustment', $finalReference, $finalDescription, null, (int)$u['id']);
            $title = 'Wallet debited';
            $message = 'SkyNoc deducted $' . number_format($amount, 2) . ' from your reseller wallet. New balance: $' . number_format($newBalance, 2) . '.';
            $auditAction = 'reseller_wallet_debited';
        }

        notify_reseller($resellerId, 'wallet', $title, $message);
        $db->commit();
        audit($auditAction, 'resellers', $resellerId, 'Amount: ' . number_format($amount, 2, '.', '') . '; Reference: ' . $finalReference);

        $_SESSION['wallet_fund_flash'] = $reseller['name'] . ' wallet ' . ($direction === 'credit' ? 'credited' : 'debited') . ' successfully. New balance: $' . number_format($newBalance, 2) . '.';
        redirect('/admin/reseller-funds');
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    if (!isset($_SESSION['wallet_adjust_token']) || $_SESSION['wallet_adjust_token'] === '') $_SESSION['wallet_adjust_token'] = bin2hex(random_bytes(32));
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'The wallet update could not be completed.';
    if (!($e instanceof RuntimeException)) error_log('SkyNoc reseller fund error: ' . $e->getMessage());
}

if (!empty($_SESSION['wallet_fund_flash'])) {
    $msg = (string)$_SESSION['wallet_fund_flash'];
    unset($_SESSION['wallet_fund_flash']);
}
$formToken = (string)($_SESSION['wallet_adjust_token'] ?? bin2hex(random_bytes(32)));
$_SESSION['wallet_adjust_token'] = $formToken;

$resellers = $db->query('SELECT id,name,email,status,wallet_balance FROM resellers ORDER BY name ASC,id ASC')->fetchAll();
$recentTransactions = $db->query('SELECT wt.id,wt.reseller_id,wt.type,wt.amount,wt.reference,wt.description,wt.created_at,r.name reseller_name FROM wallet_transactions wt JOIN resellers r ON r.id=wt.reseller_id ORDER BY wt.id DESC LIMIT 25')->fetchAll();

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
<title>SkyNoc Admin • Reseller Funds</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--brand:#465fff;--brand-dark:#3641f5;--ink:#101828;--muted:#667085;--line:#e4e7ec;--bg:#f8fafc;--card:#fff;--success:#027a48;--success-bg:#ecfdf3;--danger:#b42318;--danger-bg:#fef3f2;--shadow:0 12px 35px rgba(16,24,40,.07)}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#f5f8ff 0,#f8fafc 360px);color:var(--ink);font-family:Outfit,system-ui,-apple-system,"Segoe UI",sans-serif}.wrap{max-width:1220px;margin:0 auto;padding:28px 20px 60px}.top{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:24px}.brand{display:flex;align-items:center;gap:11px}.mark{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#465fff,#7a8cff);display:grid;place-items:center;color:#fff;font-weight:800;box-shadow:0 8px 20px rgba(70,95,255,.25)}h1{margin:0;font-size:27px;letter-spacing:-.03em}.sub{margin:5px 0 0;color:var(--muted);font-size:13px}.links{display:flex;gap:8px;flex-wrap:wrap}.link,.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:9px;padding:9px 13px;font-weight:700;font-size:12px;text-decoration:none}.link{background:#fff;border:1px solid var(--line);color:#344054}.btn{border:0;background:var(--brand);color:#fff;cursor:pointer}.btn:hover{background:var(--brand-dark)}.btn.danger{background:#b42318}.layout{display:grid;grid-template-columns:minmax(0,440px) minmax(0,1fr);gap:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:15px;box-shadow:var(--shadow);overflow:hidden}.head{padding:18px 20px;border-bottom:1px solid var(--line)}.head h2{font-size:16px;margin:0}.head p{font-size:12px;color:var(--muted);margin:4px 0 0}.body{padding:20px}.notice{border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px}.success{background:var(--success-bg);border:1px solid #abefc6;color:var(--success)}.error{background:var(--danger-bg);border:1px solid #fecdca;color:var(--danger)}.field{margin-bottom:13px}.field label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:6px}input,select,textarea{width:100%;border:1px solid #d0d5dd;border-radius:9px;background:#fff;padding:10px 11px;font:inherit;color:var(--ink);outline:none}input:focus,select:focus,textarea:focus{border-color:#84adff;box-shadow:0 0 0 3px rgba(70,95,255,.1)}textarea{min-height:86px;resize:vertical}.hint{font-size:11px;color:#98a2b3;margin-top:5px}.summary{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#f8faff;border:1px solid #dbe4ff;border-radius:10px;padding:12px;margin:12px 0 16px}.summary .label{font-size:11px;color:var(--muted)}.summary .value{font-size:18px;font-weight:800;color:var(--brand);margin-top:2px}.type-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:15px}.type-option{position:relative}.type-option input{position:absolute;opacity:0;pointer-events:none}.type-option label{display:block;border:1px solid #d0d5dd;border-radius:10px;padding:11px 12px;cursor:pointer;background:#fff}.type-option label strong{display:block;font-size:13px}.type-option label span{display:block;font-size:10px;color:#98a2b3;margin-top:2px}.type-option input:checked+label{border-color:#465fff;background:#f5f7ff;box-shadow:0 0 0 2px rgba(70,95,255,.08)}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:700px}.table th{text-align:left;background:#fcfcfd;color:#667085;text-transform:uppercase;letter-spacing:.08em;font-size:10px;padding:11px 14px;border-bottom:1px solid var(--line)}.table td{padding:13px 14px;border-bottom:1px solid #f2f4f7;font-size:12px;vertical-align:middle}.table tr:last-child td{border-bottom:0}.name{font-weight:700}.email{font-size:11px;color:#98a2b3;margin-top:2px}.money{font-weight:800;font-variant-numeric:tabular-nums}.credit{color:#027a48}.debit{color:#b42318}.badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:10px;font-weight:800}.ok{color:#027a48;background:#ecfdf3}.pending{color:#b54708;background:#fffaeb}.off{color:#b42318;background:#fef3f2}.empty{text-align:center;color:#98a2b3;padding:30px}.note{font-size:12px;color:var(--muted);line-height:1.6;margin-top:15px}.note strong{color:#344054}.warning{font-size:11px;color:#b54708;background:#fffaeb;border:1px solid #fedf89;border-radius:9px;padding:9px 10px;margin-bottom:14px}@media(max-width:820px){.layout{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}.links{width:100%}.link{flex:1}.wrap{padding:20px 14px 45px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="brand"><div class="mark">S</div><div><h1>Reseller Wallet Management</h1><p class="sub">Safe admin credit/debit adjustments with a protected transaction flow.</p></div></div>
    <div class="links"><a class="link" href="/admin">← Admin Dashboard</a><a class="link" href="/admin/reports">Reports</a></div>
  </div>

  <?php if ($msg): ?><div class="notice success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

  <div class="layout">
    <section class="card">
      <div class="head"><h2>Wallet Adjustment</h2><p>Credit or debit a reseller wallet manually. Every change is logged.</p></div>
      <div class="body">
        <div class="warning">Duplicate protection is enabled: the form uses a one-time submission token and redirects after a successful transaction, so browser refresh will not repeat the wallet change.</div>
        <form method="post" autocomplete="off" id="fundForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="adjust_fund">
          <input type="hidden" name="wallet_adjust_token" value="<?= e($formToken) ?>">
          <div class="field"><label>Transaction Type</label>
            <div class="type-grid">
              <div class="type-option"><input type="radio" name="direction" id="credit" value="credit" checked><label for="credit"><strong>＋ Credit</strong><span>Add money to wallet</span></label></div>
              <div class="type-option"><input type="radio" name="direction" id="debit" value="debit"><label for="debit"><strong>− Debit</strong><span>Deduct money from wallet</span></label></div>
            </div>
          </div>
          <div class="field"><label for="reseller_id">Reseller</label>
            <select name="reseller_id" id="reseller_id" required>
              <option value="">Select reseller</option>
              <?php foreach ($resellers as $r): ?>
                <option value="<?= (int)$r['id'] ?>" data-balance="<?= e((string)$r['wallet_balance']) ?>" <?= ((int)($_POST['reseller_id'] ?? 0) === (int)$r['id']) ? 'selected' : '' ?>><?= e($r['name']) ?> — <?= e($r['email']) ?> — $<?= number_format((float)$r['wallet_balance'],2) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="summary"><div><div class="label">Current wallet balance</div><div class="value" id="currentBalance">$0.00</div></div><div>💳</div></div>
          <div class="field"><label for="amount">Amount (USD)</label><input type="number" name="amount" id="amount" min="0.01" max="1000000" step="0.01" placeholder="50.00" required><div class="hint" id="amountHint">Credit adds this amount to the reseller wallet.</div></div>
          <div class="field"><label for="reference">Reference <span style="font-weight:400;color:#98a2b3">(optional)</span></label><input type="text" name="reference" maxlength="120" placeholder="e.g. ADMIN-ADJ-001"><div class="hint">A reference can only be used once for the same reseller.</div></div>
          <div class="field"><label for="description">Note <span style="font-weight:400;color:#98a2b3">(optional)</span></label><textarea name="description" maxlength="255" placeholder="Reason for manual wallet adjustment"></textarea></div>
          <button class="btn" id="submitBtn" type="submit">Credit Wallet</button>
        </form>
        <div class="note"><strong>Audit:</strong> every adjustment is stored in <code>wallet_transactions</code> with the admin ID, reference and signed amount. The reseller receives a wallet notification.</div>
      </div>
    </section>

    <section class="card">
      <div class="head"><h2>Reseller Wallets</h2><p>Current balances and account status.</p></div>
      <div class="table-wrap">
        <?php if (!$resellers): ?><div class="empty">No reseller accounts found.</div><?php else: ?>
        <table class="table"><thead><tr><th>Reseller</th><th>Status</th><th>Wallet Balance</th></tr></thead><tbody>
          <?php foreach ($resellers as $r): ?><tr><td><div class="name"><?= e($r['name']) ?></div><div class="email"><?= e($r['email']) ?></div></td><td><?= fund_badge((string)$r['status']) ?></td><td class="money">$<?= number_format((float)$r['wallet_balance'],2) ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <section class="card" style="margin-top:18px">
    <div class="head"><h2>Recent Wallet Transactions</h2><p>Latest 25 credit, debit, deposit, refund and purchase ledger entries.</p></div>
    <div class="table-wrap">
      <?php if (!$recentTransactions): ?><div class="empty">No wallet transactions found.</div><?php else: ?>
      <table class="table"><thead><tr><th>Reseller</th><th>Type</th><th>Amount</th><th>Reference</th><th>Description</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($recentTransactions as $tx): $isDebit=(float)$tx['amount']<0; ?>
        <tr><td class="name"><?= e($tx['reseller_name']) ?></td><td><?= e((string)$tx['type']) ?></td><td class="money <?= $isDebit?'debit':'credit' ?>"><?= $isDebit?'−':'+' ?>$<?= number_format(abs((float)$tx['amount']),2) ?></td><td><?= e($tx['reference'] ?: '—') ?></td><td><?= e($tx['description'] ?: '—') ?></td><td><?= e((string)$tx['created_at']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
  </section>
</div>
<script>
const resellerSelect=document.getElementById('reseller_id');
const balance=document.getElementById('currentBalance');
const submitBtn=document.getElementById('submitBtn');
const hint=document.getElementById('amountHint');
const form=document.getElementById('fundForm');
function direction(){return document.querySelector('input[name="direction"]:checked')?.value||'credit'}
function sync(){const o=resellerSelect.options[resellerSelect.selectedIndex];const n=parseFloat(o?.dataset.balance||'0')||0;balance.textContent='$'+n.toFixed(2);const d=direction();submitBtn.textContent=d==='debit'?'Debit Wallet':'Credit Wallet';submitBtn.className='btn'+(d==='debit'?' danger':'');hint.textContent=d==='debit'?'This amount will be deducted. The wallet can never go below $0.00.':'Credit adds this amount to the reseller wallet.'}
resellerSelect.addEventListener('change',sync);document.querySelectorAll('input[name="direction"]').forEach(x=>x.addEventListener('change',sync));
form.addEventListener('submit',function(){const d=direction();const amount=parseFloat(document.getElementById('amount').value||'0');const current=parseFloat((resellerSelect.options[resellerSelect.selectedIndex]?.dataset.balance||'0'));if(d==='debit'&&amount>current){alert('Debit amount cannot be greater than the current wallet balance.');return false}if(!confirm((d==='debit'?'Debit $':'Credit $')+amount.toFixed(2)+' '+(d==='debit'?'from':'to')+' the selected reseller wallet?'))return false;submitBtn.disabled=true;submitBtn.textContent='Processing…';return true});
sync();
</script>
</body>
</html>
