<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin','manager','staff']);
if (!can('license.manage', $u) && !in_array($u['role'], ['owner','admin'], true)) { http_response_code(403); exit('Forbidden'); }

$totalLicenses = (int)$db->query("SELECT COUNT(*) FROM licenses")->fetchColumn();
$activeLicenses = (int)$db->query("SELECT COUNT(*) FROM licenses WHERE status='active'")->fetchColumn();
$totalResellers = (int)$db->query("SELECT COUNT(*) FROM resellers")->fetchColumn();
$activeResellers = (int)$db->query("SELECT COUNT(*) FROM resellers WHERE status='active'")->fetchColumn();
$totalOrders = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$completedOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status='completed'")->fetchColumn();
$totalRevenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='completed'")->fetchColumn();
$totalCost = (float)$db->query("SELECT COALESCE(SUM(p.buy_price),0) FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.status='completed'")->fetchColumn();
$totalProfit = $totalRevenue - $totalCost;
$pendingActivation = (int)$db->query("SELECT COUNT(*) FROM resellers WHERE status='pending'")->fetchColumn();
$pendingOrders = (int)$db->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','processing')")->fetchColumn();
$recent = $db->query("SELECT o.id,o.amount,o.status,o.created_at,r.name reseller_name,p.name package_name,p.buy_price FROM orders o JOIN resellers r ON r.id=o.reseller_id JOIN packages p ON p.id=o.package_id ORDER BY o.id DESC LIMIT 8")->fetchAll();
function money(float $v): string { return number_format($v, 2); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Admin Dashboard</title>
<link rel="stylesheet" href="/admin-page-ui.css"><style>
body{background:#f5f7fb}.top{position:sticky;top:0;z-index:5}.wrap{max-width:1280px;margin:0 auto;padding:24px}.hero{border-radius:22px;padding:28px;color:#fff;background:linear-gradient(135deg,#101828,#1d2939 45%,#465fff);box-shadow:0 18px 45px rgba(16,24,40,.16);margin-bottom:20px}.hero h1{margin:5px 0;font-size:30px}.hero p{margin:0;color:#dbe4ff}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}.card{background:#fff;border:1px solid #e4e7ec;border-radius:18px;padding:20px;box-shadow:0 5px 18px rgba(16,24,40,.05)}.metric .label{font-size:12px;color:#667085;font-weight:700}.metric .value{font-size:28px;font-weight:800;margin:7px 0}.metric .sub{font-size:12px;color:#667085}.profit{border-color:#b7ebcd}.profit .value{color:#067647}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-block;text-decoration:none;background:#465fff;color:#fff;padding:10px 14px;border-radius:10px;font-weight:700;font-size:13px}.btn.secondary{background:#eef2ff;color:#344054}.btn.warn{background:#fff4e5;color:#b54708}.section{margin-top:16px}.section h2{font-size:17px;margin:0 0 14px}.table-wrap{overflow:auto}.table{width:100%;min-width:760px;border-collapse:collapse}.table th,.table td{padding:11px 9px;border-bottom:1px solid #eaecf0;text-align:left;font-size:13px}.table th{font-size:11px;text-transform:uppercase;color:#667085}.status{font-size:11px;font-weight:800;border-radius:999px;padding:4px 8px;background:#f2f4f7}.status.completed{background:#ecfdf3;color:#067647}.status.pending,.status.processing{background:#fffaeb;color:#b54708}.status.rejected,.status.refunded{background:#fef3f2;color:#b42318}.cards2{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cards2{grid-template-columns:1fr}}@media(max-width:560px){.wrap{padding:14px}.grid{grid-template-columns:1fr}.hero h1{font-size:24px}.metric .value{font-size:24px}}
</style></head><body>
<div class="top"><strong>SkyNoc <span style="opacity:.5">/</span> Admin Dashboard</strong><span><?=e($u['name'])?> · <a href="/admin/reseller-manage">Resellers</a> · <a href="/admin/orders">Orders</a> · <a href="/admin/licenses">Licenses</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><div class="hero"><div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.8">LIVE BUSINESS OVERVIEW</div><h1>License & Reseller Dashboard</h1><p>Live totals from the current database. Revenue and profit are calculated from completed orders using each package's configured buy price and sales price.</p><div class="actions"><a class="btn" href="/admin/package-pricing">Package Pricing</a><a class="btn secondary" href="/admin/reseller-activate">Pending Activations (<?=e((string)$pendingActivation)?>)</a><a class="btn secondary" href="/admin/order-edit">Order & License Editor</a></div></div>
<div class="grid">
<div class="card metric"><div class="label">TOTAL LICENSES</div><div class="value"><?=number_format($totalLicenses)?></div><div class="sub"><?=number_format($activeLicenses)?> active</div></div>
<div class="card metric"><div class="label">RESELLERS</div><div class="value"><?=number_format($totalResellers)?></div><div class="sub"><?=number_format($activeResellers)?> active</div></div>
<div class="card metric"><div class="label">TOTAL SALES REVENUE</div><div class="value">$<?=money($totalRevenue)?></div><div class="sub"><?=number_format($completedOrders)?> completed orders</div></div>
<div class="card metric profit"><div class="label">TOTAL PROFIT</div><div class="value">$<?=money($totalProfit)?></div><div class="sub">Revenue $<?=money($totalRevenue)?> − cost $<?=money($totalCost)?></div></div>
</div>
<div class="cards2">
<div class="card"><h2>Operations</h2><div class="sub">Orders: <b><?=number_format($totalOrders)?></b> · Pending/processing: <b><?=number_format($pendingOrders)?></b></div><div class="sub" style="margin-top:8px">Pending reseller activations: <b><?=number_format($pendingActivation)?></b></div></div>
<div class="card"><h2>Financial Summary</h2><div class="sub">Sales: <b>$<?=money($totalRevenue)?></b> · Buy cost: <b>$<?=money($totalCost)?></b> · Profit: <b>$<?=money($totalProfit)?></b></div><div class="sub" style="margin-top:8px">Profit margin: <b><?= $totalRevenue > 0 ? number_format(($totalProfit/$totalRevenue)*100,2) : '0.00' ?>%</b></div></div>
</div>
<div class="card section"><h2>Recent Orders</h2><div class="table-wrap"><table class="table"><thead><tr><th>Order</th><th>Reseller</th><th>Package</th><th>Sales</th><th>Buy Cost</th><th>Profit</th><th>Status</th><th>Date</th></tr></thead><tbody>
<?php foreach($recent as $o): $profit=(float)$o['amount']-(float)$o['buy_price']; ?><tr><td>#<?=e((string)$o['id'])?></td><td><?=e($o['reseller_name'])?></td><td><?=e($o['package_name'])?></td><td>$<?=money((float)$o['amount'])?></td><td>$<?=money((float)$o['buy_price'])?></td><td><b>$<?=money($profit)?></b></td><td><span class="status <?=e($o['status'])?>"><?=e(ucfirst($o['status']))?></span></td><td><?=e($o['created_at'])?></td></tr><?php endforeach; ?>
<?php if(!$recent): ?><tr><td colspan="8">No orders yet.</td></tr><?php endif; ?></tbody></table></div></div>
</div></body></html>
