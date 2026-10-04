<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin']);
$msg = null; $error = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $methods = payment_methods();
        foreach ($methods as $m) {
            $code = $m['code'];
            $enabled = isset($_POST['enabled'][$code]) ? 1 : 0;
            $min = (float)($_POST['min_deposit'][$code] ?? 0);
            if ($min <= 0) throw new RuntimeException('Minimum deposit must be greater than 0 for '.$m['name'].'.');
            $name = trim((string)($_POST['name'][$code] ?? $m['name'])) ?: $m['name'];
            $instructions = trim((string)($_POST['instructions'][$code] ?? '')) ?: null;
            $sort = (int)($_POST['sort_order'][$code] ?? $m['sort_order']);
            $config = json_decode((string)($_POST['config_json'][$code] ?? ($m['config_json'] ?: '{}')), true);
            if (!is_array($config)) throw new RuntimeException('Invalid configuration for '.$m['name'].'.');
            if ($code === 'USDT_BEP20') {
                $config['receiving_address'] = trim((string)($_POST['usdt_receiving_address'] ?? ''));
                $config['rpc_url'] = trim((string)($_POST['usdt_rpc_url'] ?? 'https://bsc-dataseed.bnbchain.org'));
                $config['chain_id'] = 56;
                $config['token_contract'] = trim((string)($_POST['usdt_token_contract'] ?? '0x55d398326f99059ff775485246999027b3197955'));
                $config['decimals'] = 18;
                $config['min_confirmations'] = max(1, (int)($_POST['usdt_min_confirmations'] ?? 12));
            }
            $db->prepare('UPDATE payment_methods SET name=?,enabled=?,min_deposit=?,instructions=?,sort_order=?,config_json=? WHERE code=?')
                ->execute([$name,$enabled,$min,$instructions,$sort,json_encode($config, JSON_UNESCAPED_SLASHES),$code]);
        }
        $msg = 'Payment settings saved successfully.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    error_log('SkyNoc payment settings error: '.$e->getMessage());
}
$methods = payment_methods();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payment Settings — SkyNoc</title><link rel="stylesheet" href="/admin-page-ui.css"><style>
:root{--blue:#465fff;--ink:#101828;--muted:#667085;--line:#e4e7ec}.hero{background:linear-gradient(135deg,#111827,#1d4ed8);color:#fff;border-radius:20px;padding:26px 28px;margin-bottom:18px}.hero h1{margin:8px 0;font-size:28px}.hero p{margin:0;color:#dbe5ff}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.nav a{padding:9px 12px;border:1px solid rgba(255,255,255,.18);border-radius:10px;color:#fff!important;background:rgba(255,255,255,.08);font-size:12px;font-weight:700}.grid{display:grid;gap:16px}.card{padding:20px!important;border-radius:18px!important}.method{border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:14px;background:#fff}.topline{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:15px}.name{display:flex;gap:11px;align-items:center}.icon{width:38px;height:38px;border-radius:11px;background:#eef2ff;display:grid;place-items:center}.name h2{font-size:16px;margin:0}.code{font-size:10px;color:var(--muted)}.toggle{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:800}.toggle input{position:absolute;opacity:0}.switch{width:42px;height:24px;border-radius:999px;background:#d0d5dd;position:relative}.switch:after{content:"";position:absolute;width:18px;height:18px;top:3px;left:3px;background:#fff;border-radius:50%;transition:.2s}.toggle input:checked+.switch{background:var(--blue)}.toggle input:checked+.switch:after{transform:translateX(18px)}.fields{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.wide{grid-column:1/-1}.field label{display:block;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475467;margin-bottom:6px}.field input,.field textarea{width:100%;box-sizing:border-box}.field textarea{min-height:86px}.usdt{margin-top:12px;padding:14px;border:1px dashed #b8c2d0;background:#f8fafc;border-radius:13px}.usdt h3{font-size:11px;text-transform:uppercase;letter-spacing:.06em;margin:0 0 11px;color:#475467}.usdtgrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.actions{display:flex;justify-content:flex-end;margin-top:18px;position:sticky;bottom:12px;z-index:5}.actions button{box-shadow:0 10px 26px rgba(70,95,255,.25)}.hint{font-size:11px;color:var(--muted);line-height:1.6}.alert{margin-bottom:15px}.back{font-size:12px;font-weight:700;color:#344054}.back:hover{text-decoration:none}@media(max-width:800px){.fields,.usdtgrid{grid-template-columns:1fr}.wide{grid-column:auto}.topline{align-items:flex-start}.actions button{width:100%}}
</style></head><body><div class="top"><strong>SkyNoc / Admin</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/logout">Logout</a></span></div><div class="wrap">
<div class="hero"><div style="font-size:11px;font-weight:800;opacity:.75;letter-spacing:.08em">SETTINGS / PAYMENTS</div><h1>Payment Methods</h1><p>Manage reseller deposit methods, limits and blockchain verification from one dedicated page.</p><div class="nav"><a href="/admin/settings">⚙ Settings</a><a href="/admin/settings/telegram">✈ Telegram</a><a href="/admin/settings/discord">◉ Discord</a><a href="/admin/settings/binance">₿ Binance</a><a href="/admin/settings/smtp">✉ SMTP</a><a href="/admin/reseller-levels">🏆 Reseller Levels</a></div></div>
<?php if($msg):?><div class="msg alert">✓ <?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err alert">! <?=e($error)?></div><?php endif;?>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payments"><div class="grid card">
<?php foreach($methods as $m): $cfg=json_decode((string)($m['config_json']?:'{}'),true); if(!is_array($cfg))$cfg=[]; ?>
<div class="method"><div class="topline"><div class="name"><div class="icon"><?=($m['code']==='USDT_BEP20'?'₮':'💰')?></div><div><h2><?=e($m['name'])?></h2><span class="code"><?=e($m['code'])?></span></div></div><label class="toggle"><input type="checkbox" name="enabled[<?=e($m['code'])?>]" value="1" <?=$m['enabled']?'checked':''?>><span class="switch"></span>Enabled</label></div>
<div class="fields"><div class="field"><label>Display Name</label><input name="name[<?=e($m['code'])?>]" value="<?=e($m['name'])?>"></div><div class="field"><label>Minimum Deposit (USD)</label><input name="min_deposit[<?=e($m['code'])?>]" type="number" step="0.01" min="0.01" value="<?=e((string)$m['min_deposit'])?>"></div><div class="field"><label>Sort Order</label><input name="sort_order[<?=e($m['code'])?>]" type="number" value="<?=e((string)$m['sort_order'])?>"></div><div class="field wide"><label>Payment Instructions</label><textarea name="instructions[<?=e($m['code'])?>]" placeholder="Instructions shown to resellers..."><?=e((string)$m['instructions'])?></textarea></div>
<?php if($m['code']==='USDT_BEP20'): ?><div class="wide usdt"><h3>BSC / USDT Verification</h3><div class="usdtgrid"><div class="field"><label>Receiving Address</label><input name="usdt_receiving_address" value="<?=e((string)($cfg['receiving_address']??''))?>" placeholder="0x..."></div><div class="field"><label>BSC RPC URL</label><input name="usdt_rpc_url" value="<?=e((string)($cfg['rpc_url']??'https://bsc-dataseed.bnbchain.org'))?>"></div><div class="field"><label>Token Contract</label><input name="usdt_token_contract" value="<?=e((string)($cfg['token_contract']??'0x55d398326f99059ff775485246999027b3197955'))?>"></div><div class="field"><label>Confirmations Required</label><input name="usdt_min_confirmations" type="number" min="1" value="<?=e((string)($cfg['min_confirmations']??12))?>"></div></div><div class="hint" style="margin-top:9px">Chain ID 56 · 18 decimals · private keys and seed phrases are never stored.</div></div><input type="hidden" name="config_json[<?=e($m['code'])?>]" value="<?=e(json_encode($cfg,JSON_UNESCAPED_SLASHES))?>"><?php else: ?><div class="field wide"><label>Method Configuration JSON</label><input name="config_json[<?=e($m['code'])?>]" value="<?=e(json_encode($cfg,JSON_UNESCAPED_SLASHES))?>" placeholder="{}"></div><?php endif; ?></div></div>
<?php endforeach; ?></div><div class="actions"><button class="btn" type="submit">Save Payment Settings</button></div></form>
</div></body></html>
