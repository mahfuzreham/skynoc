from pathlib import Path
p=Path('public/admin.php')
s=p.read_text()
old="""        if ($a === 'package_status' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $active = ((int)($_POST['active'] ?? 0) === 1) ? 1 : 0;
            $db->prepare('UPDATE packages SET active=? WHERE id=?')->execute([$active,$id]);
            audit('package_status_changed','packages',$id,(string)$active);
            $msg = $active ? 'Package enabled. Resellers can now order it.' : 'Package disabled.';
        }
"""
new=old+"""
        if ($a === 'package_delete' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Invalid package.');
            $q = $db->prepare('SELECT name FROM packages WHERE id=?');
            $q->execute([$id]);
            $packageName = $q->fetchColumn();
            if ($packageName === false) throw new RuntimeException('Package not found.');
            $q = $db->prepare('SELECT COUNT(*) FROM orders WHERE package_id=?');
            $q->execute([$id]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('This package cannot be deleted because it has existing orders. Disable it instead to preserve order history.');
            }
            $db->prepare('DELETE FROM packages WHERE id=?')->execute([$id]);
            audit('package_deleted','packages',$id,(string)$packageName);
            $msg = 'Package deleted permanently.';
        }
"""
assert old in s
s=s.replace(old,new,1)
old2="""        if ($a === 'reseller' && can('reseller.manage', $u)) {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 10) throw new RuntimeException('Password must be at least 10 characters.');
            $db->beginTransaction();
            $db->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,"reseller","active")')->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
            $uid = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO resellers(user_id,name,email) VALUES(?,?,?)')->execute([$uid,$name,$email]);
            $rid = (int)$db->lastInsertId();
            $db->commit();
            audit('reseller_created','resellers',$rid);
            $msg = 'Reseller created.';
        }
"""
new2=old2+"""
        if ($a === 'reseller_delete' && can('reseller.manage', $u)) {
            $rid = (int)($_POST['id'] ?? 0);
            if ($rid <= 0) throw new RuntimeException('Invalid reseller.');
            $db->beginTransaction();
            $q = $db->prepare('SELECT user_id,name,email FROM resellers WHERE id=? FOR UPDATE');
            $q->execute([$rid]);
            $r = $q->fetch();
            if (!$r) throw new RuntimeException('Reseller not found.');
            $q = $db->prepare('SELECT COUNT(*) FROM licenses WHERE reseller_id=? AND status="active"');
            $q->execute([$rid]);
            if ((int)$q->fetchColumn() > 0) {
                throw new RuntimeException('This reseller still has active licenses. Unassign or terminate them before deletion.');
            }
            audit('reseller_deleted','resellers',$rid,'Deleted reseller '.$r['name'].' <'.$r['email'].'>');
            $db->prepare('DELETE FROM resellers WHERE id=?')->execute([$rid]);
            if (!empty($r['user_id'])) {
                $db->prepare('DELETE FROM users WHERE id=? AND role="reseller"')->execute([(int)$r['user_id']]);
            }
            $db->commit();
            $msg = 'Reseller deleted permanently. Associated reseller data was removed and unassigned licenses were preserved.';
        }
"""
assert old2 in s
s=s.replace(old2,new2,1)
anchor='''  <a class="side-link" href="#deposits"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7h18v12H3z"/><path d="M7 7V5h10v2M7 13h4"/></svg>Deposits</a>
'''
rep=anchor+'''  <a class="side-link" href="#resellers"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20c.6-3.4 2.6-5 6-5s5.4 1.6 6 5M16 5.5a3 3 0 0 1 0 5.8M17 15c2.3.5 3.5 2.1 4 5"/></svg>Resellers</a>
'''
assert anchor in s
s=s.replace(anchor,rep,1)
anchor2='''<form method="post" class="form-actions"><?=csrf_field()?><input type="hidden" name="action" value="package_status"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="active" value="<?=$p['active']?0:1?>"><button class="btn <?=$p['active']?'btn-danger':'btn-secondary'?>"><?=$p['active']?'Disable package':'Enable package'?></button><span class="badge <?=$p['active']?'badge-success':'badge-neutral'?>"><?=$p['active']?'active':'disabled'?></span></form>'''
rep2=anchor2+'''\n        <form method="post" class="form-actions" onsubmit="return confirm('Delete this package permanently? Packages with existing orders cannot be deleted.');"><?=csrf_field()?><input type="hidden" name="action" value="package_delete"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn btn-danger">Delete package</button></form>'''
assert anchor2 in s
s=s.replace(anchor2,rep2,1)
s=s.replace("$resellers = $db->query('SELECT id,name,email,status FROM resellers ORDER BY id DESC')->fetchAll();", "$resellers = $db->query('SELECT id,name,email,status,wallet_balance FROM resellers ORDER BY id DESC')->fetchAll();",1)
anchor3='''  <div class="section-head" id="licenses"><div><h2>License inventory</h2><p>Control availability and reseller assignment.</p></div><span class="badge badge-brand"><?=count($licenses)?> records</span></div>'''
section='''  <div class="section-head" id="resellers"><div><h2>Resellers</h2><p>Manage reseller accounts. Deletion permanently removes the reseller account and its associated reseller data.</p></div><span class="badge badge-brand"><?=count($resellers)?> accounts</span></div>
  <div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Reseller</th><th>Email</th><th>Status</th><th>Wallet</th><th>Action</th></tr></thead><tbody>
  <?php if(!$resellers):?><tr><td colspan="6" class="empty">No reseller accounts.</td></tr><?php endif;?>
  <?php foreach($resellers as $r): $rstatus=$r['status']; $rc=$rstatus==='active'?'badge-success':($rstatus==='pending'?'badge-warning':'badge-danger'); ?>
  <tr><td>#<?=$r['id']?></td><td class="strong"><?=e($r['name'])?></td><td><?=e($r['email'])?></td><td><span class="badge <?=$rc?>"><?=e($rstatus)?></span></td><td>$<?=number_format((float)$r['wallet_balance'],2)?></td><td><?php if(can('reseller.manage',$u)):?><form method="post" class="row-form" onsubmit="return confirm('Delete this reseller permanently? All reseller orders, wallet/deposit records, API keys and notifications will be removed. Active licenses must be handled first.');"><?=csrf_field()?><input type="hidden" name="action" value="reseller_delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn btn-danger">Delete reseller</button></form><?php endif;?></td></tr>
  <?php endforeach;?></tbody></table></div></div>

'''+anchor3
assert anchor3 in s
s=s.replace(anchor3,section,1)
p.write_text(s)
PY