<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['reseller']);
$s = $db->prepare('SELECT id FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]);
$r = $s->fetch();
if (!$r) exit('Reseller profile not found.');
$rid=(int)$r['id'];
$wallet=reseller_wallet($rid);
$level=reseller_level($rid);
$discount=reseller_level_discount((int)$level['assigned_level']);
$s=$db->prepare('SELECT COUNT(*) FROM licenses WHERE reseller_id=? AND status="active"');
$s->execute([$rid]);
$activeLicenses=(int)$s->fetchColumn();
$s=$db->prepare('SELECT COUNT(*) FROM notifications WHERE reseller_id=? AND read_at IS NULL');
$s->execute([$rid]);
$unread=(int)$s->fetchColumn();
$levelName=(string)($level['name'] ?? ('Level '.(int)$level['assigned_level']));
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Reseller Dashboard</title><style>
*{box-sizing:border-box}body{margin:0;background:#f6f8fb;color:#101828;font-family:Inter,system-ui,sans-serif}.wrap{max-width:1200px;margin:auto;padding:28px 18px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}.brand{font-weight:900;font-size:20px}.brand span{color:#465fff}.links{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}.link{display:block;background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:20px;text-decoration:none;color:#101828;box-shadow:0 8px 25px #1018280a}.link b{display:block;font-size:16px;margin-bottom:7px}.link small{color:#667085}.level{background:linear-gradient(135deg,#111827,#293a70);color:#fff;border-radius:16px;padding:22px;margin-bottom:20px}.level small{color:#b9c2d4}.level strong{display:block;font-size:26px;margin:5px 0}.meta{display:flex;gap:24px;color:#d7deea}.badge{display:inline-block;background:#eef2ff;color:#465fff;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800}@media(max-width:850px){.links{grid-template-columns:repeat(2,1fr)}}@media(max-width:520px){.links{grid-template-columns:1fr}.top{align-items:flex-start;gap:12px;flex-direction:column}}
</style></head><body><main class="wrap"><div class="top"><div class="brand"><span>SkyNoc</span> Reseller</div><a href="/logout">Logout</a></div><section class="level"><small>YOUR RESELLER LEVEL</small><strong><?=e($levelName)?></strong><div class="meta"><span><?=number_format($activeLicenses)?> active licenses</span><span><?=number_format($discount,2)?>% discount</span><span>$<?=number_format($wallet,2)?> wallet</span></div></section><div class="links"><a class="link" href="/reseller"><b>Dashboard</b><small>Reseller overview</small></a><a class="link" href="/reseller/manage#licenses"><b>My Licenses</b><small>View and manage licenses</small></a><a class="link" href="/reseller/manage#orders"><b>My Orders</b><small>Orders and invoices</small></a><a class="link" href="/reseller/notifications"><b>Notifications<?= $unread ? ' ('.$unread.')' : '' ?></b><small>Telegram, Discord and email alerts</small></a><a class="link" href="/reseller/manage#deposits"><b>Wallet & Deposits</b><small>Add funds and payment history</small></a><a class="link" href="/reseller/manage#api"><b>API Access</b><small>API keys and integration</small></a><a class="link" href="/reseller/manage#support"><b>Support</b><small>Tickets and reissue requests</small></a><a class="link" href="/reseller/levels"><b>Reseller Levels</b><small>Levels, discounts and profit</small></a><a class="link" href="/reseller/white-label"><b>White-label</b><small>Branding settings</small></a><a class="link" href="/security"><b>Security</b><small>Account security and 2FA</small></a></div></main></body></html>
