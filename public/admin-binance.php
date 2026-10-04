<?php
require __DIR__ . '/../app/bootstrap.php';
$u=require_role(['owner','admin']);
$msg=null;$error=null;
$method=payment_method('USDT_BEP20');
$cfg=$method && !empty($method['config_json']) ? json_decode((string)$method['config_json'],true) : [];
if(!is_array($cfg)) $cfg=[];
$defaults=['enabled'=>false,'api_key'=>'','api_secret'=>'','base_url'=>'https://api.binance.com','network'=>'BSC','coin'=>'USDT','auto_scan'=>true,'scan_minutes'=>30];
$b=array_merge($defaults,is_array($cfg['binance']??null)?$cfg['binance']:[]);
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf(); $action=$_POST['action']??'';
  if($action==='save'){
   $key=trim((string)($_POST['api_key']??'')); $secret=trim((string)($_POST['api_secret']??''));
   if($key==='' && !empty($b['api_key'])) $key=(string)$b['api_key'];
   if($secret==='' && !empty($b['api_secret'])) $secret=(string)$b['api_secret'];
   if($key==='' || $secret==='') throw new RuntimeException('Binance API Key and Secret are required.');
   $b=['enabled'=>isset($_POST['enabled']),'api_key'=>$key,'api_secret'=>$secret,'base_url'=>'https://api.binance.com','network'=>'BSC','coin'=>'USDT','auto_scan'=>((string)($_POST['auto_scan']??'1')==='1'),'scan_minutes'=>max(5,min(1440,(int)($_POST['scan_minutes']??30)))];
   $cfg['binance']=$b;
   $s=$db->prepare('UPDATE payment_methods SET config_json=? WHERE code=?');
   $s->execute([json_encode($cfg,JSON_UNESCAPED_SLASHES),'USDT_BEP20']);
   $msg='Binance API settings saved.';
  }
  if($action==='test'){
   $test=skynoc_binance_test(); $msg='Binance API connection successful. Read-only deposit history is accessible.';
  }
 }
}catch(Throwable $e){$error=$e->getMessage();}
$method=payment_method('USDT_BEP20');
$cfg=$method && !empty($method['config_json']) ? json_decode((string)$method['config_json'],true) : [];
if(!is_array($cfg))$cfg=[];
$b=array_merge($defaults,is_array($cfg['binance']??null)?$cfg['binance']:[]);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Binance API — SkyNoc Admin</title><link rel="stylesheet" href="/admin-page-ui.css"><style>
.hero{background:linear-gradient(135deg,#111827,#172554,#1d4ed8);color:#fff;border-radius:22px;padding:28px;margin-bottom:20px;box-shadow:0 18px 45px rgba(16,24,40,.15)}.hero h1{margin:8px 0;font-size:30px}.hero p{margin:0;color:#dbe4ff;line-height:1.7}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.card{border-radius:18px!important}.badge{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.12);font-size:11px;font-weight:800}.toggle-row{display:flex;justify-content:space-between;align-items:center;padding:15px;border:1px solid #e4e7ec;border-radius:14px;background:#fafbff;margin-bottom:16px}.switch{position:relative;width:48px;height:28px}.switch input{opacity:0;width:0;height:0}.slider{position:absolute;inset:0;background:#d0d5dd;border-radius:99px;cursor:pointer}.slider:before{content:"";position:absolute;width:22px;height:22px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 1px 4px #0002}.switch input:checked+.slider{background:#465fff}.switch input:checked+.slider:before{transform:translateX(20px)}.info{padding:13px;border:1px solid #b9c3d0;border-radius:13px;background:#f8fafc;color:#475467;font-size:12px;line-height:1.65}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.actions button,.actions a{min-width:150px;text-align:center}.success-box{padding:13px;border:1px solid #abefc6;background:#ecfdf3;color:#067647;border-radius:12px;margin-bottom:15px}.error-box{padding:13px;border:1px solid #fecdca;background:#fef3f2;color:#b42318;border-radius:12px;margin-bottom:15px}@media(max-width:720px){.grid2{grid-template-columns:1fr}.hero{padding:21px 17px}.hero h1{font-size:25px}.actions button,.actions a{width:100%}}
</style></head><body>
<div class="top"><strong>SkyNoc <span style="opacity:.55">/</span> Binance API</strong><span><?=e($u['name'])?> · <a href="/admin/settings">Settings</a> <a href="/admin">Dashboard</a></span></div>
<div class="wrap">
<div class="hero"><span class="badge">₿ BSC PAYMENT ENGINE</span><h1>Binance API Integration</h1><p>Connect a read-only Binance Exchange API so SkyNoc can verify USDT BEP20 deposits from Binance deposit history and recover a matching recent deposit when a reseller enters an incorrect TXID.</p></div>
<?php if($msg):?><div class="success-box"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="error-box"><?=e($error)?></div><?php endif;?>
<section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">🔐</div><div><h2>API Credentials</h2><p class="hint">Use a Binance API key with read-only wallet/deposit permissions. Withdrawal permission is not required.</p></div></div></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><div class="toggle-row"><div><b>Enable Binance verification</b><div class="hint">Use Binance deposit history as an additional verification source.</div></div><label class="switch"><input type="checkbox" name="enabled" value="1" <?=$b['enabled']?'checked':''?>><span class="slider"></span></label></div>
<div class="grid2"><div class="field"><label>Binance API Key</label><input name="api_key" value="<?=e((string)$b['api_key'])?>" autocomplete="off" placeholder="API key"></div><div class="field"><label>Binance API Secret</label><input type="password" name="api_secret" value="<?=e((string)$b['api_secret'])?>" autocomplete="new-password" placeholder="API secret"></div><div class="field"><label>Coin</label><input value="USDT" disabled></div><div class="field"><label>Network</label><input value="BSC / BEP20" disabled></div><div class="field"><label>Auto-scan window (minutes)</label><input type="number" name="scan_minutes" min="5" max="1440" value="<?=e((string)$b['scan_minutes'])?>"></div><div class="field"><label>Automatic recent-payment scan</label><select name="auto_scan"><option value="1" <?=$b['auto_scan']?'selected':''?>>Enabled</option><option value="0" <?=!$b['auto_scan']?'selected':''?>>Disabled</option></select></div></div>
<div class="info" style="margin-top:15px">BSC Chain ID: <b>56</b> · USDT contract: <code>0x55d398326f99059ff775485246999027b3197955</code>. The API secret is only used server-side for signed Binance API requests.</div>
<div class="actions"><button type="submit">Save Binance Settings</button></div></form></section>
<section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">🧪</div><div><h2>Connection Test</h2><p class="hint">Test the credentials before enabling automatic deposit verification.</p></div></div></div><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test"><div class="info">The test reads the latest USDT deposit-history record on BSC. It does not place trades, withdraw funds or modify your Binance account.</div><div class="actions"><button type="submit">Test Binance API</button><a class="button-link" href="/admin/settings">← Back to Settings</a></div></form></section>
<section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">⚠️</div><div><h2>Important Address Note</h2><p class="hint">How Binance Exchange API works with deposit addresses.</p></div></div></div><div class="info">Binance Exchange API can verify deposits and expose the Binance-assigned deposit address/history, but it does <b>not</b> provide a generic unlimited per-reseller BEP20 address generator. If you need one unique on-chain address per reseller, SkyNoc needs a wallet/address-derivation system or an eligible Binance business deposit-address product. Do not treat a shared Binance deposit address as unique to each reseller.</div></section>
<div class="page-footer">SkyNoc Admin · <a href="/admin/settings">Back to Settings</a> · <a href="mailto:support@skynoc.net">support@skynoc.net</a></div>
</div></body></html>