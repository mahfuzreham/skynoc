<?php
require __DIR__ . '/../app/bootstrap.php';
$u=require_role(['owner','admin']);
$msg=null;$error=null;
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$action=$_POST['action']??'';
  if($action==='payments'){
   $methods=payment_methods();
   foreach($methods as $m){
    $code=$m['code'];
    $enabled=isset($_POST['enabled'][$code])?1:0;
    $min=(float)($_POST['min_deposit'][$code]??0);
    if($min<=0) throw new RuntimeException('Minimum deposit must be greater than 0 for '.$m['name'].'.');
    $name=trim((string)($_POST['name'][$code]??$m['name']))?:$m['name'];
    $instructions=trim((string)($_POST['instructions'][$code]??''))?:null;
    $sort=(int)($_POST['sort_order'][$code]??$m['sort_order']);
    $config=json_decode((string)($_POST['config_json'][$code]??($m['config_json']?:'{}')),true);
    if(!is_array($config)) throw new RuntimeException('Invalid configuration for '.$m['name'].'.');
    if($code==='USDT_BEP20'){
        $config['receiving_address']=trim((string)($_POST['usdt_receiving_address']??''));
        $config['rpc_url']=trim((string)($_POST['usdt_rpc_url']??'https://bsc-dataseed.bnbchain.org'));
        $config['chain_id']=56;
        $config['token_contract']=trim((string)($_POST['usdt_token_contract']??'0x55d398326f99059ff775485246999027b3197955'));
        $config['decimals']=18;
        $config['min_confirmations']=max(1,(int)($_POST['usdt_min_confirmations']??12));
    }
    $s=$db->prepare('UPDATE payment_methods SET name=?,enabled=?,min_deposit=?,instructions=?,sort_order=?,config_json=? WHERE code=?');
    $s->execute([$name,$enabled,$min,$instructions,$sort,json_encode($config,JSON_UNESCAPED_SLASHES),$code]);
   }
   $msg='Payment settings saved.';
  }
  if($action==='telegram'){
   $lt=trim((string)($_POST['license_bot_token']??''));$lc=trim((string)($_POST['license_admin_chat_id']??''));
   if($lt===''||$lc==='') throw new RuntimeException('License Control Bot token and admin chat ID are required.');
   $dt=trim((string)($_POST['deposit_bot_token']??''));$dc=trim((string)($_POST['deposit_admin_chat_id']??''));
   $s=$db->prepare('INSERT INTO telegram_settings(id,bot_token,admin_chat_id,license_bot_token,license_admin_chat_id,deposit_bot_token,deposit_admin_chat_id) VALUES(1,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE bot_token=VALUES(bot_token),admin_chat_id=VALUES(admin_chat_id),license_bot_token=VALUES(license_bot_token),license_admin_chat_id=VALUES(license_admin_chat_id),deposit_bot_token=VALUES(deposit_bot_token),deposit_admin_chat_id=VALUES(deposit_admin_chat_id)');
   $s->execute([$lt,$lc,$lt,$lc,$dt?:null,$dc?:null]);$msg='Telegram settings saved.';
  }
 }
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();error_log('SkyNoc settings error: '.$e->getMessage());}
$methods=payment_methods();
$tg=$db->query('SELECT * FROM telegram_settings WHERE id=1 LIMIT 1')->fetch()?:[];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Settings — SkyNoc Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f7fb;color:#0f172a;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.top{background:#111827;color:#fff;padding:14px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px}.top a{color:#fff;text-decoration:none;margin-left:12px}.wrap{max-width:1150px;margin:24px auto;padding:0 14px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:20px;margin-bottom:18px;box-shadow:0 5px 20px #0f172a0a}.head{display:flex;justify-content:space-between;gap:12px;align-items:center}.method{border:1px solid #e5e7eb;border-radius:16px;padding:16px;margin:12px 0}.row{display:grid;grid-template-columns:1.2fr .7fr .7fr;gap:12px}.wide{grid-column:1/-1}label{font-size:13px;font-weight:700;display:block;margin:5px 0}input,textarea,select,button{width:100%;padding:11px;border:1px solid #d8e0e8;border-radius:10px;font:inherit}textarea{min-height:75px;resize:vertical}button{background:#111827;color:#fff;border:0;font-weight:800;cursor:pointer}.switch{display:flex;align-items:center;gap:8px}.switch input{width:auto}.ok{color:#166534}.msg,.err{padding:12px;border-radius:10px;margin-bottom:15px}.msg{background:#ecfdf5;color:#166534}.err{background:#fef2f2;color:#991b1b}.hint{color:#64748b;font-size:13px}.danger{background:#fff7ed;color:#9a3412;padding:12px;border-radius:10px;font-size:13px}@media(max-width:700px){.top{align-items:flex-start;flex-direction:column}.top span{display:flex;flex-wrap:wrap;gap:8px}.top a{margin-left:0}.wrap{padding:0 10px}.card{padding:15px}.row{grid-template-columns:1fr}.method{padding:13px}.head{align-items:flex-start;flex-direction:column}button{min-height:46px}}
</style></head><body>
<div class="top"><b>SkyNoc Admin Settings</b><span><?=e($u['name'])?> · <a href="/admin">Admin</a> <a href="/admin/reseller-levels">🏆 Levels</a> <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Settings</h1><p class="hint">Central settings for reseller payments, USDT verification and Telegram notifications.</p>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>
<div class="card"><div class="head"><div><h2>💳 Payment Methods</h2><p class="hint">Enable or disable each deposit method and set its minimum deposit. Reseller forms use these settings automatically.</p></div></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="payments">
<?php foreach($methods as $m):$cfg=json_decode((string)($m['config_json']?:'{}'),true);if(!is_array($cfg))$cfg=[];?>
<div class="method"><div class="head"><h3><?=e($m['name'])?> <small class="hint">(<?=e($m['code'])?>)</small></h3><label class="switch"><input type="checkbox" name="enabled[<?=e($m['code'])?>]" value="1" <?=$m['enabled']?'checked':''?>> Enabled</label></div>
<div class="row"><div><label>Display Name</label><input name="name[<?=e($m['code'])?>]" value="<?=e($m['name'])?>"></div><div><label>Minimum Deposit (USD)</label><input name="min_deposit[<?=e($m['code'])?>]" type="number" step="0.01" min="0.01" value="<?=e((string)$m['min_deposit'])?>"></div><div><label>Sort Order</label><input name="sort_order[<?=e($m['code'])?>]" type="number" value="<?=e((string)$m['sort_order'])?>"></div><div class="wide"><label>Payment Instructions</label><textarea name="instructions[<?=e($m['code'])?>]"><?=e((string)$m['instructions'])?></textarea></div>
<?php if($m['code']==='USDT_BEP20'):?>
<div><label>Receiving Address</label><input name="usdt_receiving_address" value="<?=e((string)($cfg['receiving_address']??''))?>" placeholder="0x..."></div>
<div><label>BSC RPC URL</label><input name="usdt_rpc_url" value="<?=e((string)($cfg['rpc_url']??'https://bsc-dataseed.bnbchain.org'))?>"></div>
<div><label>Token Contract</label><input name="usdt_token_contract" value="<?=e((string)($cfg['token_contract']??'0x55d398326f99059ff775485246999027b3197955'))?>"></div>
<div><label>Confirmations Required</label><input name="usdt_min_confirmations" type="number" min="1" value="<?=e((string)($cfg['min_confirmations']??12))?>"></div>
<div class="wide"><div class="hint">BSC chain ID 56 · USDT BEP20 contract is configurable above. No private key or seed is stored.</div><input type="hidden" name="config_json[<?=e($m['code'])?>]" value="<?=e(json_encode($cfg,JSON_UNESCAPED_SLASHES))?>"></div>
<?php else:?><div class="wide"><label>Method Configuration JSON (optional)</label><input name="config_json[<?=e($m['code'])?>]" value="<?=e(json_encode($cfg,JSON_UNESCAPED_SLASHES))?>"></div><?php endif;?></div></div>
<?php endforeach;?><button>Save Payment Settings</button></form></div>
<div class="card"><h2>🤖 Telegram</h2><p class="hint">Separate License Control and USDT Deposit bots.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="telegram"><div class="row"><div><label>License Control Bot Token</label><input type="password" name="license_bot_token" value="<?=e((string)($tg['license_bot_token']??''))?>" autocomplete="off"></div><div><label>License Admin Chat ID</label><input name="license_admin_chat_id" value="<?=e((string)($tg['license_admin_chat_id']??''))?>"></div><div><label>Deposit Admin Chat ID</label><input name="deposit_admin_chat_id" value="<?=e((string)($tg['deposit_admin_chat_id']??''))?>"></div><div><label>USDT Deposit Bot Token</label><input type="password" name="deposit_bot_token" value="<?=e((string)($tg['deposit_bot_token']??''))?>" autocomplete="off"></div></div><button>Save Telegram Settings</button></form></div>
<div class="card"><h2>🏆 Reseller Levels</h2><p class="hint">Automatic thresholds and custom admin overrides are managed separately.</p><p><b>Level 1:</b> 0–10 &nbsp; <b>Level 2:</b> 11–25 &nbsp; <b>Level 3:</b> 26–50 &nbsp; <b>Level 4:</b> 51–100 &nbsp; <b>Level 5:</b> 101+</p><a href="/admin/reseller-levels">Open Reseller Level Management →</a></div>
<div class="card"><h2>⚙️ System</h2><p class="hint">Database migrations are automatic when enabled in the local configuration. Keep production credentials in <code>config/config.local.php</code>.</p><div class="danger">Never publish database credentials, Telegram bot tokens or private provider credentials in the Git repository.</div></div>
</div></body></html>