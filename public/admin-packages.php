<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin','manager','staff']);
$msg = null; $error = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!can('license.manage', $u)) throw new RuntimeException('You do not have permission to manage packages.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $price = (float)($_POST['price'] ?? 0);
            $limit = ($_POST['client_limit'] ?? '') === '' ? null : (int)$_POST['client_limit'];
            $period = trim((string)($_POST['billing_period'] ?? 'monthly')) ?: 'monthly';
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,119}$/', $slug) || $price <= 0) throw new RuntimeException('Package name, valid slug and positive price are required.');
            if (!in_array($period, ['monthly','annual','one_time'], true)) $period = 'monthly';
            $db->prepare('INSERT INTO packages(name,slug,description,price,client_limit,billing_period,active,sort_order) VALUES(?,?,?,?,?,?,1,?)')->execute([$name,$slug,$description,$price,$limit,$period,$sort]);
            audit('package_created','packages',(int)$db->lastInsertId());
            $msg = 'Package created and enabled.';
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['name'] ?? '')); $price = (float)($_POST['price'] ?? 0);
            $limit = ($_POST['client_limit'] ?? '') === '' ? null : (int)$_POST['client_limit'];
            $period = trim((string)($_POST['billing_period'] ?? 'monthly')) ?: 'monthly';
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            if ($id <= 0 || $name === '' || $price <= 0) throw new RuntimeException('Package name and positive price are required.');
            if (!in_array($period, ['monthly','annual','one_time'], true)) $period = 'monthly';
            $db->prepare('UPDATE packages SET name=?,description=?,price=?,client_limit=?,billing_period=? WHERE id=?')->execute([$name,$description,$price,$limit,$period,$id]);
            audit('package_updated','packages',$id); $msg = 'Package updated.';
        } elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0); $active = ((int)($_POST['active'] ?? 0) === 1) ? 1 : 0;
            $db->prepare('UPDATE packages SET active=? WHERE id=?')->execute([$active,$id]);
            audit('package_status_changed','packages',$id,(string)$active); $msg = $active ? 'Package enabled.' : 'Package disabled.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getCode() === '23000' ? 'This package slug already exists.' : 'The package action could not be completed.';
    error_log('SkyNoc packages error: '.$e->getMessage());
}
$packages = $db->query('SELECT id,name,slug,description,price,client_limit,billing_period,active,sort_order FROM packages ORDER BY sort_order,id DESC')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Admin • Packages</title><link rel="stylesheet" href="/admin-page-ui.css"></head><body>
<header class="nav"><strong>SkyNoc Admin</strong><span><a href="/admin">Dashboard</a> &nbsp; <a href="/admin/#deposits">Deposits</a> &nbsp; <a href="/admin/settings">Settings</a> &nbsp; <a href="/logout">Sign out</a></span></header>
<main class="wrap"><h1>License Packages</h1><p class="muted">Create and control the packages resellers can order. This section is now independent from the main admin dashboard.</p>
<?php if($msg):?><div class="msg"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?>
<?php if(can('license.manage',$u)):?><section class="card"><h2>Create package</h2><p class="muted">New packages are enabled immediately for reseller ordering.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create"><div class="row"><div><label>Package name</label><input name="name" placeholder="Professional" required></div><div><label>Slug</label><input name="slug" placeholder="professional" pattern="[a-z0-9][a-z0-9_-]{1,119}" required></div><div><label>Price (USD)</label><input name="price" type="number" step="0.01" min="0.01" placeholder="25.00" required></div><div><label>Client limit</label><input name="client_limit" type="number" min="1" placeholder="Unlimited"></div><div><label>Billing period</label><select name="billing_period"><option value="monthly">Monthly</option><option value="annual">Annual</option><option value="one_time">One time</option></select></div><div><label>Sort order</label><input name="sort_order" type="number" value="0"></div><div class="wide"><label>Description</label><textarea name="description" placeholder="Short package description"></textarea></div></div><p><button>Create package</button></p></form></section><?php endif;?>
<section class="card"><h2>Existing packages <span class="pill"><?=count($packages)?></span></h2><p class="muted">Disabled packages are hidden from the reseller order screen.</p><div class="table"><table><thead><tr><th>Package</th><th>Price</th><th>Billing</th><th>Client limit</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if(!$packages):?><tr><td colspan="6">No packages yet.</td></tr><?php endif;?><?php foreach($packages as $p):?><tr><td><strong><?=e($p['name'])?></strong><div class="muted"><?=e($p['slug'])?></div></td><td>$<?=number_format((float)$p['price'],2)?></td><td><?=e($p['billing_period'])?></td><td><?=e((string)($p['client_limit'] ?? 'Unlimited'))?></td><td><span class="pill"><?= $p['active'] ? 'active' : 'disabled' ?></span></td><td><?php if(can('license.manage',$u)):?><details><summary>Edit</summary><form method="post" style="margin-top:10px"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?=$p['id']?>"><div class="row"><div><label>Name</label><input name="name" value="<?=e($p['name'])?>" required></div><div><label>Price</label><input name="price" type="number" step="0.01" min="0.01" value="<?=e((string)$p['price'])?>" required></div><div><label>Client limit</label><input name="client_limit" type="number" min="1" value="<?=e((string)($p['client_limit'] ?? ''))?>"></div><div><label>Billing</label><select name="billing_period"><option value="monthly" <?=$p['billing_period']==='monthly'?'selected':''?>>Monthly</option><option value="annual" <?=$p['billing_period']==='annual'?'selected':''?>>Annual</option><option value="one_time" <?=$p['billing_period']==='one_time'?'selected':''?>>One time</option></select></div><div class="wide"><label>Description</label><textarea name="description"><?=e($p['description'] ?? '')?></textarea></div></div><p><button>Save changes</button></p></form></details><form method="post" class="inline" style="margin-top:8px"><?=csrf_field()?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="active" value="<?=$p['active']?0:1?>"><button><?=$p['active']?'Disable':'Enable'?></button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section></main><footer class="page-footer">SkyNoc Admin · <a href="/admin">Return to dashboard</a></footer></body></html>