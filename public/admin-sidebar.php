<?php
/* Shared admin navigation. Keep this server-rendered so the admin UI still works when JS is unavailable. */
$adminNavGroups = [
    'Workspace' => [
        ['/admin/dashboard','Dashboard','▦'], ['/admin/packages','Packages','◈'], ['/admin/orders','Orders','▤'], ['/admin/deposits','Deposits','◫'], ['/admin/licenses','License Inventory','▥'],
    ],
    'Licensing' => [
        ['/admin/license-add','Add License','＋'], ['/admin/license-transfer','License Transfer','⇄'], ['/admin/order-edit','Edit Orders / License Key','✎'], ['/admin/order-fulfill','Order Fulfillment','✓'], ['/admin/renew','Renewals / Billing','↻'], ['/admin/providers','Provider Accounts','▣'], ['/admin/reissues','License Reissues','↻'],
    ],
    'Billing' => [
        ['/admin/package-pricing','Package Pricing / Profit','৳'], ['/admin/invoice-settings','Invoice Settings','▤'],
    ],
    'Resellers' => [
        ['/admin/reseller-add','Add Reseller','＋'], ['/admin/reseller-activate','Activate Resellers','●'], ['/admin/reseller-manage','Reseller Management','♟'], ['/admin/reseller-funds','Reseller Funds','$'], ['/admin/reseller-levels','Reseller Levels','★'],
    ],
    'Products' => [
        ['/admin/hostname','Cloudflare Hostnames','⌁'],
    ],
    'Support' => [
        ['/admin/tickets','Support Tickets','✉'],
    ],
    'Access & API' => [
        ['/admin/user-management','User Management','♙'], ['/admin/staff','Staff Management','♟'], ['/admin/api-keys','API Keys','⚿'],
    ],
    'Reports' => [
        ['/admin/reports','Reports','↗'], ['/admin/coupons','Coupons','◇'],
    ],
    'Settings' => [
        ['/admin/settings','General Settings','⚙'], ['/admin/settings/payments','Payment Methods','৳'], ['/admin/settings/binance','Binance / Crypto','₿'], ['/admin/settings/discord','Discord','◉'], ['/admin/settings/telegram','Telegram','✈'], ['/admin/settings/telegram/message','Telegram Messages','☷'], ['/admin/settings/smtp','SMTP / Email','@'], ['/admin/settings/whitelabel','White-label Settings','◇'],
    ],
];
?>
<nav class="admin-nav" aria-label="Admin navigation">
<?php $currentPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/'; ?>
<?php foreach ($adminNavGroups as $group => $items): ?>
<?php $isActiveGroup=false; foreach($items as $item){ if(rtrim($item[0],'/')===$currentPath){$isActiveGroup=true;break;} } ?>
<details class="nav-group"<?=($isActiveGroup || $group==='Workspace')?' open':''?>>
<summary class="nav-group-title"><span class="nav-group-icon"><?=e(match($group){'Workspace'=>'▦','Licensing'=>'◈','Billing'=>'৳','Resellers'=>'♟','Products'=>'⌁','Support'=>'✉','Access & API'=>'⚿','Reports'=>'↗','Settings'=>'⚙',default=>'•'})?></span><span><?=e($group)?></span><span class="nav-chevron">⌄</span></summary>
<div class="nav-group-body">
<?php foreach($items as $item): $active=rtrim($item[0],'/')===$currentPath; ?>
<a class="side-link<?=$active?' active':''?>" href="<?=e($item[0])?>"><span class="side-link-icon"><?=e($item[2])?></span><span><?=e($item[1])?></span></a>
<?php endforeach; ?>
</div>
</details>
<?php endforeach; ?>
</nav>
<style>
.admin-nav{padding:8px 0 12px}.nav-group{margin:4px 10px 8px;border-radius:12px;overflow:hidden}.nav-group-title{list-style:none;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;cursor:pointer;font-size:12px;font-weight:800;color:#667085;user-select:none}.nav-group-title::-webkit-details-marker{display:none}.nav-group-title:hover{background:#f2f4f7;color:#344054}.nav-group-icon{width:20px;text-align:center;font-size:15px}.nav-chevron{margin-left:auto;transition:transform .18s ease;font-size:14px}.nav-group[open]>.nav-group-title .nav-chevron{transform:rotate(180deg)}.nav-group-body{display:flex;flex-direction:column;gap:2px;padding:2px 4px 7px}.nav-group .side-link{display:flex;align-items:center;gap:9px;text-decoration:none;padding:9px 10px 9px 30px;border-radius:9px;font-size:13px;color:#475467;font-weight:600}.nav-group .side-link:hover{background:#f2f4f7;color:#101828}.nav-group .side-link.active{background:#eef2ff;color:#3447d6;font-weight:800}.side-link-icon{width:18px;text-align:center;opacity:.8}@media(max-width:900px){.nav-group{margin-left:7px;margin-right:7px}.nav-group .side-link{padding-left:26px}}
</style>
