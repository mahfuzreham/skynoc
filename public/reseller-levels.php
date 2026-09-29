<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['reseller']);
$s = $db->prepare('SELECT * FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]);
$r = $s->fetch();
if (!$r) exit('Reseller profile not found.');
$level = reseller_level((int)$r['id']);
$thresholds = reseller_level_thresholds();
$colors = [1=>'🥉',2=>'🥈',3=>'🥇',4=>'💎',5=>'👑'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reseller Level — SkyNoc</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f6f8fb;color:#0f172a;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between;align-items:center}.nav a{color:#fff;text-decoration:none;margin-left:14px}
.wrap{max-width:1050px;margin:28px auto;padding:0 16px}.hero{background:#111827;color:#fff;border-radius:22px;padding:28px;margin-bottom:18px}
.badge{display:inline-block;padding:8px 13px;border-radius:999px;background:#fff1;color:#fff;font-weight:800}.level{font-size:38px;font-weight:900;margin:12px 0 4px}.muted{opacity:.72}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;margin-bottom:18px;box-shadow:0 6px 24px #0f172a0b}
.progress{height:13px;background:#e9eef5;border-radius:99px;overflow:hidden}.bar{height:100%;background:#111827;border-radius:99px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.stat{background:#f8fafc;border-radius:14px;padding:16px}.num{font-size:25px;font-weight:850}
.levels{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.lv{border:1px solid #e5e7eb;border-radius:15px;padding:15px;text-align:center}.active{border:2px solid #111827;background:#f8fafc}.emoji{font-size:27px}
@media(max-width:700px){.levels{grid-template-columns:1fr 1fr}.level{font-size:30px}.nav{flex-direction:column;align-items:flex-start;gap:8px}}
</style></head><body>
<div class="nav"><b>SkyNoc Reseller</b><span><?=e($u['name'])?> · <a href="/reseller">Dashboard</a> <a href="/logout">Logout</a></span></div>
<div class="wrap">
<div class="hero">
<div class="badge"><?=$colors[$level['assigned_level']]?> Level <?=$level['assigned_level']?><?= $level['mode']==='custom' ? ' · Custom' : '' ?></div>
<div class="level"><?=e($level['name'])?></div>
<div class="muted"><?=$level['active']?> active license<?= $level['active']===1?'':'s' ?> currently assigned</div>
</div>
<div class="grid">
<div class="card stat"><div class="muted">Active Licenses</div><div class="num"><?=number_format($level['active'])?></div></div>
<div class="card stat"><div class="muted">Current Level</div><div class="num">Level <?=$level['assigned_level']?></div></div>
<div class="card stat"><div class="muted">Level Mode</div><div class="num"><?=e(strtoupper($level['mode']))?></div></div>
<div class="card stat"><div class="muted">Calculated Level</div><div class="num">Level <?=$level['calculated_level']?></div></div>
</div>
<div class="card">
<h2><?= $level['assigned_level']>=5 ? '👑 You reached the top reseller level' : '🎯 Your next target' ?></h2>
<?php if($level['assigned_level']<5): ?>
<p><b><?=number_format($level['needed'])?> more active license<?= $level['needed']===1?'':'s' ?></b> to reach Level <?=$level['next_level']?> — <?=e($level['next_name'])?>.</p>
<div class="progress"><div class="bar" style="width:<?=$level['progress']?>%"></div></div>
<p class="muted"><?=$level['active']?> active / <?=$thresholds[$level['next_level']]['min']?> needed for next level</p>
<?php else: ?><p class="muted">There is no higher automatic level. Keep growing your active license portfolio.</p><?php endif; ?>
</div>
<div class="card"><h2>🏆 Reseller Levels</h2><div class="levels">
<?php foreach($thresholds as $n=>$range): ?><div class="lv <?=$n===$level['assigned_level']?'active':''?>"><div class="emoji"><?=$colors[$n]?></div><b>Level <?=$n?></b><br><small><?=e($range['name'])?></small><br><small><?=number_format($range['min'])?><?= $n===5 ? '+' : '–'.number_format($range['max']) ?> active</small></div><?php endforeach; ?>
</div></div>
<?php if($level['mode']==='custom'): ?><div class="card"><b>Admin-assigned level</b><p class="muted">Your account currently has a custom level assigned by SkyNoc. Your automatic calculated level is Level <?=$level['calculated_level']?>.</p></div><?php endif; ?>
</div></body></html>