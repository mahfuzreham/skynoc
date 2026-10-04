<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin']);
$msg=null;$error=null;
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf(); $action=$_POST['action']??''; $rid=(int)($_POST['reseller_id']??0);
  $q=$db->prepare('SELECT id,name,email,level_mode,custom_level FROM resellers WHERE id=? LIMIT 1');$q->execute([$rid]);$r=$q->fetch();
  if(!$r) throw new RuntimeException('Reseller not found.');
  if($action==='set_level'){
   $mode=$_POST['level_mode']??'auto';$custom=$_POST['custom_level']??'';
   if($mode==='custom' && (!ctype_digit((string)$custom) || (int)$custom<1 || (int)$custom>5)) throw new RuntimeException('Custom level must be between 1 and 5.');
   $old=($r['level_mode']==='custom' && $r['custom_level'])?(int)$r['custom_level']:reseller_calculated_level(reseller_active_license_count($rid));
   $new=$mode==='custom'?(int)$custom:reseller_calculated_level(reseller_active_license_count($rid));
   $reason=trim((string)($_POST['reason']??''))?:null;
   $db->beginTransaction();
   $db->prepare('UPDATE resellers SET level_mode=?,custom_level=?,level_updated_at=NOW() WHERE id=?')->execute([$mode,$mode==='custom'?$new:null,$rid]);
   if($old!==$new || $mode==='custom') $db->prepare('INSERT INTO reseller_level_history(reseller_id,old_level,new_level,mode,reason,changed_by) VALUES(?,?,?,?,?,?)')->execute([$rid,$old,$new,$mode,$reason,$u['id']]);
   $db->commit(); audit('reseller_level_changed','resellers',$rid,'mode='.$mode.' level='.$new);
   $msg='Reseller level updated.';
  }
 }
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();error_log('SkyNoc level admin: '.$e->getMessage());}
$resellers=$db->query('SELECT id,name,email,status,level_mode,custom_level FROM resellers ORDER BY id DESC')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reseller Levels — SkyNoc Admin</title><link rel="stylesheet" href="/admin-page-ui.css"><style>*{box-sizing:border-box}body{margin:0;background:#f6f8fb;color:#0f172a;font-family:Inter,system-ui,sans-serif}.nav{background:#111827;color:#fff;padding:15px 5%;display:flex;justify-content:space-between}.nav a{color:#fff}.wrap{max-width:1250px;margin:25px auto;padding:0 14px}.msg,.err{padding:12px;border-radius:10px;margin-bottom:15px}.msg{background:#ecfdf5;color:#166534}.err{background:#fef2f2;color:#991b1b}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;margin-bottom:15px}.table{overflow:auto}table{width:100%;min-width:900px;border-collapse:collapse}th,td{padding:12px;border-bottom:1px solid #eef2f7;text-align:left}input,select,button{padding:9px;border:1px solid #dbe2ea;border-radius:9px;font:inherit}button{background:#111827;color:#fff;border:0;font-weight:700;cursor:pointer}.muted{color:#64748b}.pill{padding:4px 8px;border-radius:99px;background:#eef2ff}@media(max-width:700px){.nav{flex-direction:column;gap:8px}}</style></head><body><div class="nav"><b>SkyNoc Admin</b><span><?=e($u['name'])?> · <a href="/admin">Admin</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><h1>Reseller Levels</h1><p class="muted">Manage automatic reseller levels or apply a controlled custom level override.</p><?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>
<div class="card"><b>Automatic levels:</b> Level 1 = 0–10 · Level 2 = 11–25 · Level 3 = 26–50 · Level 4 = 51–100 · Level 5 = 101+</div>
<div class="table"><table><tr><th>Reseller</th><th>Status</th><th>Active</th><th>Calculated</th><th>Assigned</th><th>Mode</th><th>Change</th></tr>
<?php foreach($resellers as $r):$lv=reseller_level((int)$r['id']);?><tr><td><b><?=e($r['name'])?></b><br><small><?=e($r['email'])?></small></td><td><?=e($r['status'])?></td><td><?=$lv['active']?></td><td>Level <?=$lv['calculated_level']?></td><td>Level <?=$lv['assigned_level']?></td><td><span class="pill"><?=e($lv['mode'])?></span></td><td><form method="post" class="inline"><?=csrf_field()?><input type="hidden" name="action" value="set_level"><input type="hidden" name="reseller_id" value="<?=$r['id']?>"><select name="level_mode"><option value="auto" <?=$lv['mode']==='auto'?'selected':''?>>Automatic</option><option value="custom" <?=$lv['mode']==='custom'?'selected':''?>>Custom</option></select><select name="custom_level"><option value="">Select</option><?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>" <?=($lv['mode']==='custom'&&$lv['assigned_level']===$i)?'selected':''?>>Level <?=$i?></option><?php endfor;?></select><input name="reason" placeholder="Reason (optional)"><button>Save</button></form></td></tr><?php endforeach;?></table></div>
<div class="page-footer">SkyNoc Admin · <a href="/admin">Back to Admin</a> · <a href="mailto:support@skynoc.net">support@skynoc.net</a></div></div></body></html>