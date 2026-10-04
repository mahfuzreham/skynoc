<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['reseller']);
$s = $db->prepare('SELECT id,status FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]);
$r = $s->fetch();
if (!$r) exit('Reseller profile not found.');
$rid = (int)$r['id'];
$status = (string)$r['status'];
$msg = null;
$error = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'order_create') {
            if ($status !== 'active') throw new RuntimeException('Please activate your reseller account before placing an order.');
            $packageId = (int)($_POST['package_id'] ?? 0);
            $domain = trim((string)($_POST['domain'] ?? ''));
            $couponCode = trim((string)($_POST['coupon_code'] ?? ''));
            if (!$packageId) throw new RuntimeException('Please select a package.');
            if ($domain === '') throw new RuntimeException('WHMCS installation domain is required.');
            if (strlen($domain) > 255) throw new RuntimeException('Domain is too long.');

            $db->beginTransaction();
            $q = $db->prepare('SELECT id,name,price,active FROM packages WHERE id=? AND active=1 FOR UPDATE');
            $q->execute([$packageId]);
            $package = $q->fetch();
            if (!$package) throw new RuntimeException('Selected package is not available.');

            $q = $db->prepare('SELECT wallet_balance FROM resellers WHERE id=? FOR UPDATE');
            $q->execute([$rid]);
            $balance = (float)$q->fetchColumn();

            $currentLevel = reseller_level($rid);
            $basePrice = reseller_package_price((float)$package['price'], (int)$currentLevel['assigned_level']);
            $coupon = platform_coupon($couponCode, $basePrice);
            $couponDiscount = (float)($coupon['discount'] ?? 0);
            $price = round(max(0, $basePrice - $couponDiscount), 2);

            if ($balance < $price) {
                throw new RuntimeException('Insufficient wallet balance. Please add funds before ordering this package.');
            }

            $q = $db->prepare('INSERT INTO orders(reseller_id,package_id,domain,amount,status,source,coupon_code,coupon_discount) VALUES(?,?,?,?,"pending","portal",?,?)');
            $q->execute([$rid, $packageId, $domain, $price, $coupon['code'] ?? null, $couponDiscount]);
            $orderId = (int)$db->lastInsertId();

            if ($coupon) platform_redeem_coupon((int)$coupon['id'], $rid, $orderId, $couponDiscount);
            wallet_debit($rid, $price, 'purchase', 'ORDER-'.$orderId, 'Package purchase: '.$package['name'], $orderId, $u['id']);
            $db->commit();

            platform_invoice_for_order($orderId);
            telegram_notify("🛒 <b>NEW PACKAGE ORDER</b>\nReseller: ".e($u['name'])."\nOrder: #".$orderId."\nPackage: ".e($package['name'])."\nAmount: $".number_format($price,2)."\nDomain: ".e($domain));
            $msg = 'Order #'.$orderId.' submitted successfully. Your wallet has been reserved for this order.'.($coupon ? ' Coupon '.$coupon['code'].' applied.' : '');
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getMessage();
}

$wallet = reseller_wallet($rid);
$level = reseller_level($rid);
$discount = reseller_level_discount((int)$level['assigned_level']);

$s = $db->query('SELECT id,name,description,price,client_limit,billing_period FROM packages WHERE active=1 ORDER BY sort_order,id');
$packages = $s->fetchAll();

$s = $db->prepare('SELECT o.id,o.domain,o.amount,o.status,o.created_at,p.name package_name,l.license_key,(SELECT id FROM invoices i WHERE i.order_id=o.id LIMIT 1) invoice_id FROM orders o JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id WHERE o.reseller_id=? ORDER BY o.id DESC LIMIT 8');
$s->execute([$rid]);
$orders = $s->fetchAll();

$s = $db->prepare('SELECT COUNT(*) FROM licenses WHERE reseller_id=? AND status="active"');
$s->execute([$rid]);
$activeLicenses = (int)$s->fetchColumn();

$s = $db->prepare('SELECT COUNT(*) FROM reissue_requests WHERE reseller_id=? AND status IN ("pending","review","manual_reissue")');
$s->execute([$rid]);
$openReissues = (int)$s->fetchColumn();

$s = $db->prepare('SELECT COUNT(*) FROM tickets WHERE reseller_id=? AND status IN ("open","pending")');
$s->execute([$rid]);
$openTickets = (int)$s->fetchColumn();

$s = $db->prepare('SELECT COUNT(*) FROM notifications WHERE reseller_id=? AND read_at IS NULL');
$s->execute([$rid]);
$unread = (int)$s->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SkyNoc Reseller Dashboard</title>
<style>
:root{--nav:#101828;--nav2:#17233b;--blue:#465fff;--blue2:#eef2ff;--bg:#f5f7fb;--text:#101828;--muted:#667085;--line:#e4e7ec;--white:#fff;--green:#12b76a;--red:#f04438;--amber:#f79009;--shadow:0 8px 30px rgba(16,24,40,.06)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.layout{display:flex;min-height:100vh}.sidebar{width:250px;background:var(--nav);color:#d0d5dd;position:fixed;inset:0 auto 0 0;padding:22px 15px;z-index:20}.brand{display:flex;align-items:center;gap:12px;padding:4px 10px 24px;border-bottom:1px solid rgba(255,255,255,.08)}.brand-icon{width:40px;height:40px;border-radius:11px;background:var(--blue);display:grid;place-items:center;color:#fff;font-size:20px;font-weight:900}.brand strong{display:block;color:#fff;font-size:17px}.brand small{color:#98a2b3}.nav-title{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#667085;font-weight:800;margin:24px 10px 9px}.nav a{display:flex;align-items:center;gap:12px;padding:12px 13px;border-radius:10px;color:#aab3c5;text-decoration:none;font-weight:650;margin:3px 0}.nav a:hover,.nav a.active{background:#1d2a4e;color:#fff}.nav a.active{box-shadow:inset 3px 0 var(--blue)}.main{margin-left:250px;width:calc(100% - 250px)}.topbar{height:74px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 32px;position:sticky;top:0;z-index:10}.topbar h1{font-size:18px;margin:0}.topbar-right{display:flex;align-items:center;gap:14px;color:var(--muted);font-size:14px}.avatar{width:38px;height:38px;border-radius:50%;background:var(--blue2);color:var(--blue);display:grid;place-items:center;font-weight:800}.content{max-width:1450px;margin:auto;padding:30px}.hero{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:24px}.eyebrow{font-size:12px;color:var(--blue);font-weight:850;letter-spacing:.08em;text-transform:uppercase}.hero h2{font-size:31px;letter-spacing:-.03em;margin:5px 0}.hero p{color:var(--muted);margin:0}.actions{display:flex;gap:10px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--line);background:#fff;color:var(--text);padding:11px 15px;border-radius:9px;text-decoration:none;font-weight:750;cursor:pointer}.btn.primary{background:var(--blue);color:#fff;border-color:var(--blue)}.btn.full{width:100%}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}.stat,.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow)}.stat{padding:20px}.stat-top{display:flex;justify-content:space-between;color:var(--muted);font-size:13px;font-weight:700}.stat-icon{width:38px;height:38px;border-radius:10px;background:var(--blue2);color:var(--blue);display:grid;place-items:center}.stat-value{font-size:28px;font-weight:900;margin-top:13px}.stat-sub{font-size:12px;color:var(--muted);margin-top:4px}.grid2{display:grid;grid-template-columns:1.7fr 1fr;gap:18px;margin-bottom:22px}.card{padding:22px}.card-head{display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:18px}.card h3{margin:0;font-size:17px}.muted{color:var(--muted);font-size:13px}.level{background:linear-gradient(135deg,#111827,#1d2a4e);color:#fff;border:0}.level .muted{color:#b5bfd1}.level-name{font-size:23px;font-weight:900}.progress{height:9px;background:rgba(255,255,255,.13);border-radius:99px;overflow:hidden;margin:16px 0 8px}.progress>div{height:100%;background:#fff;border-radius:99px}.balance{font-size:32px;font-weight:950;margin:8px 0}.balance-note{color:var(--muted);font-size:13px}.package-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.package{border:1px solid var(--line);border-radius:14px;padding:20px;display:flex;flex-direction:column;min-height:250px;background:#fff}.package.popular{border:2px solid var(--blue);padding:19px}.package-badge{font-size:10px;font-weight:850;color:var(--blue);background:var(--blue2);padding:5px 8px;border-radius:99px;display:inline-block;margin-bottom:12px;width:max-content}.package h4{font-size:19px;margin:0 0 7px}.package p{color:var(--muted);font-size:13px;min-height:38px}.price{font-size:29px;font-weight:950;margin:8px 0}.old{font-size:13px;color:#98a2b3;text-decoration:line-through;margin-right:5px}.package ul{padding-left:18px;color:var(--muted);font-size:12px;line-height:1.9;flex:1}.package button{border:0;background:var(--blue);color:#fff;padding:11px;border-radius:9px;font-weight:800;cursor:pointer}.notice{padding:13px;border-radius:10px;margin-bottom:15px}.success{background:#ecfdf3;color:#067647}.error{background:#fef3f2;color:#b42318}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:650px}th,td{padding:13px 10px;text-align:left;border-bottom:1px solid #f0f2f5;font-size:13px}th{color:#667085;font-size:11px;text-transform:uppercase;letter-spacing:.06em}.badge{display:inline-flex;padding:5px 9px;border-radius:99px;font-size:11px;font-weight:800;background:#f2f4f7;color:#475467}.badge.active,.badge.completed{background:#ecfdf3;color:#067647}.badge.pending{background:#fffaeb;color:#b54708}.badge.suspended,.badge.failed{background:#fef3f2;color:#b42318}.empty{text-align:center;padding:30px;color:var(--muted);font-size:13px}.modal{display:none;position:fixed;inset:0;background:rgba(16,24,40,.55);z-index:100;align-items:center;justify-content:center;padding:18px}.modal.open{display:flex}.modal-box{width:min(540px,100%);background:#fff;border-radius:16px;padding:24px;box-shadow:0 25px 70px rgba(0,0,0,.2)}.modal-head{display:flex;justify-content:space-between;align-items:center}.close{border:0;background:#f2f4f7;border-radius:8px;width:36px;height:36px;cursor:pointer}.form-row{margin:14px 0}.form-row label{display:block;font-size:12px;font-weight:800;margin-bottom:6px}.form-row input{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:9px;font:inherit}.summary{background:#f8f9fc;border:1px solid var(--line);border-radius:10px;padding:13px;margin:14px 0}.mobile-menu{display:none}
@media(max-width:1100px){.stats{grid-template-columns:repeat(2,1fr)}.package-grid{grid-template-columns:repeat(2,1fr)}.grid2{grid-template-columns:1fr}.sidebar{width:225px}.main{margin-left:225px;width:calc(100% - 225px)}}
@media(max-width:760px){.sidebar{transform:translateX(-100%);transition:.2s}.sidebar.open{transform:translateX(0)}.main{margin-left:0;width:100%}.topbar{padding:0 16px}.mobile-menu{display:inline-flex;border:0;background:#f2f4f7;border-radius:9px;width:40px;height:40px;align-items:center;justify-content:center;font-size:19px}.content{padding:20px 14px}.hero{flex-direction:column}.actions{width:100%}.actions .btn{flex:1}.stats{grid-template-columns:1fr 1fr;gap:10px}.package-grid{grid-template-columns:1fr}.stat{padding:15px}.stat-value{font-size:24px}.topbar-right span{display:none}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar" id="sidebar">
  <div class="brand"><div class="brand-icon">S</div><div><strong>SkyNoc</strong><small>Reseller Portal</small></div></div>
  <div class="nav-title">Workspace</div>
  <nav class="nav">
    <a class="active" href="/reseller">▦ <span>Dashboard</span></a>
    <a href="#packages">◇ <span>License Packages</span></a>
    <a href="#orders">▤ <span>My Orders</span></a>
    <a href="/reseller/manage">▣ <span>My Licenses</span></a>
  </nav>
  <div class="nav-title">Account</div>
  <nav class="nav">
    <a href="/reseller/levels">★ <span>Reseller Levels</span></a>
    <a href="/reseller/white-label">✦ <span>White-label</span></a>
    <a href="/security">⚿ <span>Security</span></a>
    <a href="/reseller/manage">⚙ <span>Account & Support</span></a>
    <a href="/logout">→ <span>Logout</span></a>
  </nav>
</aside>
<main class="main">
<header class="topbar"><div style="display:flex;align-items:center;gap:12px"><button class="mobile-menu" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button><h1>Reseller Dashboard</h1></div><div class="topbar-right"><span><?=e($u['name'])?></span><?php if($unread):?><span>🔔 <?=$unread?></span><?php endif;?><div class="avatar"><?=e(strtoupper(substr((string)$u['name'],0,1)))?></div></div></header>
<section class="content">
  <div class="hero"><div><div class="eyebrow">SkyNoc Reseller</div><h2>Welcome back, <?=e($u['name'])?></h2><p>Manage your wallet, purchase WHMCS license packages and track your orders.</p></div><div class="actions"><a class="btn" href="/reseller/manage">Manage Account</a><a class="btn primary" href="#packages">Buy License</a></div></div>
  <?php if($msg):?><div class="notice success">✓ <?=e($msg)?></div><?php endif;?>
  <?php if($error):?><div class="notice error">! <?=e($error)?></div><?php endif;?>
  <div class="stats">
    <div class="stat"><div class="stat-top"><span>Wallet Balance</span><div class="stat-icon">$</div></div><div class="stat-value">$<?=number_format($wallet,2)?></div><div class="stat-sub">Available for package orders</div></div>
    <div class="stat"><div class="stat-top"><span>Active Licenses</span><div class="stat-icon">▣</div></div><div class="stat-value"><?=$activeLicenses?></div><div class="stat-sub">Your active WHMCS licenses</div></div>
    <div class="stat"><div class="stat-top"><span>Open Reissues</span><div class="stat-icon">↻</div></div><div class="stat-value"><?=$openReissues?></div><div class="stat-sub">Requests waiting for action</div></div>
    <div class="stat"><div class="stat-top"><span>Support Tickets</span><div class="stat-icon">?</div></div><div class="stat-value"><?=$openTickets?></div><div class="stat-sub">Open support conversations</div></div>
  </div>
  <div class="grid2">
    <div class="card level"><div class="card-head"><div><div class="muted">Current reseller level</div><div class="level-name"><?=e($level['name'])?></div></div><div style="font-size:28px;font-weight:950">L<?=$level['assigned_level']?></div></div><div class="muted"><?=$activeLicenses?> active licenses<?php if($level['assigned_level']<5):?> · <?=$level['needed']?> more to reach <?=e($level['next_name'])?><?php endif;?></div><div class="progress"><div style="width:<?=$level['progress']?>%"></div></div><div style="display:flex;justify-content:space-between;font-size:12px"><span><?=number_format($discount,2)?>% package discount</span><a style="color:#fff" href="/reseller/levels">View levels →</a></div></div>
    <div class="card"><div class="muted">Available wallet</div><div class="balance">$<?=number_format($wallet,2)?></div><div class="balance-note">Package purchases are deducted automatically after order submission.</div><div style="margin-top:16px"><a class="btn primary full" href="/reseller/manage">+ Add Funds</a></div></div>
  </div>
  <div class="card" id="packages"><div class="card-head"><div><h3>Choose a License Package</h3><div class="muted">Select a package, enter your WHMCS installation domain and place the order.</div></div><span class="badge active"><?=count($packages)?> available</span></div>
    <?php if($status !== 'active'):?><div class="notice error">Your reseller account is <b><?=e(strtoupper($status))?></b>. Activate your account before purchasing a package.</div><?php endif;?>
    <div class="package-grid">
      <?php foreach($packages as $i=>$p): $final=reseller_package_price((float)$p['price'],(int)$level['assigned_level']); ?>
      <div class="package <?=($i===1?'popular':'')?>">
        <?php if($i===1):?><span class="package-badge">POPULAR</span><?php endif;?>
        <h4><?=e($p['name'])?></h4><p><?=e($p['description'] ?: 'WHMCS license package for resellers.')?></p>
        <div class="price"><span class="old">$<?=number_format((float)$p['price'],2)?></span>$<?=number_format($final,2)?></div>
        <div class="muted"><?=number_format($discount,2)?>% reseller discount · <?=e($p['billing_period'])?></div>
        <ul><li><?=e((string)($p['client_limit'] ?? 'Standard'))?> client limit</li><li>Wallet billing</li><li>Reseller support</li></ul>
        <button type="button" <?=($status!=='active'?'disabled style="opacity:.5;cursor:not-allowed"':'')?> onclick="openOrder(<?=json_encode((int)$p['id'])?>,<?=json_encode($p['name'])?>,<?=json_encode($final)?>)">Select & Order</button>
      </div>
      <?php endforeach;?>
    </div>
    <?php if(!$packages):?><div class="empty">No active packages are available right now.</div><?php endif;?>
  </div>
  <div class="card" id="orders"><div class="card-head"><div><h3>Recent Orders</h3><div class="muted">Your latest license package purchases.</div></div><a class="btn" href="/reseller/manage">View all</a></div><div class="table-wrap"><table><thead><tr><th>Order</th><th>Package</th><th>Domain</th><th>Amount</th><th>Status</th><th>License</th><th>Date</th></tr></thead><tbody>
  <?php foreach($orders as $o):?><tr><td><b>#<?=$o['id']?></b></td><td><?=e($o['package_name'])?></td><td><?=e($o['domain'])?></td><td>$<?=number_format((float)$o['amount'],2)?></td><td><span class="badge <?=e(strtolower((string)$o['status']))?>"><?=e(strtoupper((string)$o['status']))?></span></td><td><?=e($o['license_key'] ?: 'Pending')?></td><td><?=e($o['created_at'])?></td></tr><?php endforeach;?>
  <?php if(!$orders):?><tr><td colspan="7" class="empty">No orders yet. Choose a package above to get started.</td></tr><?php endif;?></tbody></table></div></div>
</section>
</main></div>

<div class="modal" id="orderModal"><div class="modal-box"><div class="modal-head"><div><h3 style="margin:0">Place Package Order</h3><div class="muted" id="modalPackage"></div></div><button class="close" type="button" onclick="closeOrder()">×</button></div><form method="post"><input type="hidden" name="action" value="order_create"><?=csrf_field()?><input type="hidden" name="package_id" id="packageId"><div class="summary"><div class="muted">Your reseller discount</div><b><?=number_format($discount,2)?>%</b><div style="margin-top:6px">Package price: <b>$<span id="modalPrice">0.00</span></b></div><div class="muted" style="margin-top:4px">Current wallet: $<?=number_format($wallet,2)?></div></div><div class="form-row"><label>WHMCS Installation Domain</label><input name="domain" placeholder="example.com" required></div><div class="form-row"><label>Coupon Code <span class="muted">(optional)</span></label><input name="coupon_code" placeholder="Enter coupon code"></div><button class="btn primary full" type="submit">Confirm Order & Deduct Wallet</button></form></div></div>
<script>
function openOrder(id,name,price){document.getElementById('packageId').value=id;document.getElementById('modalPackage').textContent=name;document.getElementById('modalPrice').textContent=Number(price).toFixed(2);document.getElementById('orderModal').classList.add('open');}
function closeOrder(){document.getElementById('orderModal').classList.remove('open');}
document.getElementById('orderModal').addEventListener('click',function(e){if(e.target===this)closeOrder();});
</script>
</body></html>
