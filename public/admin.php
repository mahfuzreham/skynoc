<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin','manager','staff']);
$msg = null;
$error = null;
$newKey = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $a = (string)($_POST['action'] ?? '');

        if ($a === 'package_create' && can('license.manage', $u)) {
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $price = (float)($_POST['price'] ?? 0);
            $limit = ($_POST['client_limit'] ?? '') === '' ? null : (int)$_POST['client_limit'];
            $period = trim((string)($_POST['billing_period'] ?? 'monthly')) ?: 'monthly';
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{1,119}$/', $slug) || $price <= 0) {
                throw new RuntimeException('Package name, valid slug and positive price are required.');
            }
            if (!in_array($period, ['monthly','annual','one_time'], true)) $period = 'monthly';
            $db->prepare('INSERT INTO packages(name,slug,description,price,client_limit,billing_period,active,sort_order) VALUES(?,?,?,?,?,?,1,?)')
                ->execute([$name,$slug,$description,$price,$limit,$period,$sort]);
            audit('package_created','packages',(int)$db->lastInsertId());
            $msg = 'Package created and enabled for reseller ordering.';
        }

        if ($a === 'package_update' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $price = (float)($_POST['price'] ?? 0);
            $limit = ($_POST['client_limit'] ?? '') === '' ? null : (int)$_POST['client_limit'];
            $period = trim((string)($_POST['billing_period'] ?? 'monthly')) ?: 'monthly';
            $description = trim((string)($_POST['description'] ?? '')) ?: null;
            if ($id <= 0 || $name === '' || $price <= 0) throw new RuntimeException('Package name and positive price are required.');
            if (!in_array($period, ['monthly','annual','one_time'], true)) $period = 'monthly';
            $db->prepare('UPDATE packages SET name=?,description=?,price=?,client_limit=?,billing_period=? WHERE id=?')
                ->execute([$name,$description,$price,$limit,$period,$id]);
            audit('package_updated','packages',$id);
            $msg = 'Package updated.';
        }

        if ($a === 'package_status' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $active = ((int)($_POST['active'] ?? 0) === 1) ? 1 : 0;
            $db->prepare('UPDATE packages SET active=? WHERE id=?')->execute([$active,$id]);
            audit('package_status_changed','packages',$id,(string)$active);
            $msg = $active ? 'Package enabled. Resellers can now order it.' : 'Package disabled.';
        }

        if ($a === 'order_review' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? 'processing');
            if (!in_array($status, ['processing','completed','rejected'], true)) $status = 'processing';
            $licenseId = (int)($_POST['license_id'] ?? 0);
            $note = trim((string)($_POST['notes'] ?? '')) ?: null;

            $db->beginTransaction();
            $q = $db->prepare('SELECT o.*,p.name package_name FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.id=? FOR UPDATE');
            $q->execute([$id]);
            $o = $q->fetch();
            if (!$o || !in_array($o['status'], ['pending','processing'], true)) throw new RuntimeException('Order is not available for review.');

            if ($status === 'completed') {
                if ($licenseId <= 0) throw new RuntimeException('Select an available license.');
                $q = $db->prepare('SELECT id,reseller_id,status FROM licenses WHERE id=? FOR UPDATE');
                $q->execute([$licenseId]);
                $lic = $q->fetch();
                if (!$lic || $lic['reseller_id'] !== null || $lic['status'] !== 'available') throw new RuntimeException('Selected license is not available.');
                $db->prepare('UPDATE licenses SET reseller_id=?,domain=?,status="active",purchase_date=CURDATE(),package_id=? WHERE id=?')
                    ->execute([$o['reseller_id'],$o['domain'],$o['package_id'],$licenseId]);
                $db->prepare('UPDATE orders SET status="completed",license_id=?,notes=?,completed_at=NOW() WHERE id=?')
                    ->execute([$licenseId,$note,$id]);
                notify_reseller((int)$o['reseller_id'],'order','Order completed','Order #'.$id.' for '.$o['package_name'].' has been completed.');
            } elseif ($status === 'rejected') {
                $db->prepare('UPDATE orders SET status="rejected",notes=? WHERE id=?')->execute([$note,$id]);
                wallet_credit((int)$o['reseller_id'],(float)$o['amount'],'refund','ORDER-'.$id,'Refund for rejected order #'.$id,$id,$u['id']);
                notify_reseller((int)$o['reseller_id'],'order','Order rejected','Order #'.$id.' was rejected and the wallet amount was refunded.');
            } else {
                $db->prepare('UPDATE orders SET status="processing",notes=? WHERE id=?')->execute([$note,$id]);
                notify_reseller((int)$o['reseller_id'],'order','Order processing','Order #'.$id.' is now being processed.');
            }
            $db->commit();
            if ($status === 'completed') platform_invoice_for_order($id);
            audit('order_'.$status,'orders',$id);
            $msg = 'Order updated.';
        }

        if ($a === 'deposit_review' && can('reseller.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['approved','rejected'], true) ? $_POST['status'] : 'rejected';
            $note = trim((string)($_POST['review_note'] ?? '')) ?: null;
            $db->beginTransaction();
            $q = $db->prepare('SELECT * FROM deposit_requests WHERE id=? FOR UPDATE');
            $q->execute([$id]);
            $d = $q->fetch();
            if (!$d || $d['status'] !== 'pending') throw new RuntimeException('Deposit request is no longer pending.');
            if ($status === 'approved') {
                $q = $db->prepare('SELECT status FROM resellers WHERE id=? FOR UPDATE');
                $q->execute([$d['reseller_id']]);
                $rs = $q->fetchColumn();
                if ($rs === false) throw new RuntimeException('Reseller not found.');
                $type = ($rs === 'pending') ? 'activation_deposit' : 'deposit';
                $db->prepare('UPDATE deposit_requests SET status="approved",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
                wallet_credit((int)$d['reseller_id'],(float)$d['amount'],$type,'DEPOSIT-'.$id,'Approved reseller deposit #'.$id,null,$u['id']);
                if ($rs === 'pending') $db->prepare('UPDATE resellers SET status="active" WHERE id=?')->execute([$d['reseller_id']]);
                notify_reseller((int)$d['reseller_id'],'wallet','Deposit approved','Your deposit #'.$id.' has been approved and credited to your wallet.');
            } else {
                $db->prepare('UPDATE deposit_requests SET status="rejected",reviewed_by=?,review_note=? WHERE id=?')->execute([$u['id'],$note,$id]);
                notify_reseller((int)$d['reseller_id'],'wallet','Deposit rejected','Your deposit #'.$id.' was rejected.'.($note ? ' Note: '.$note : ''));
            }
            $db->commit();
            audit('deposit_'.$status,'deposit_requests',$id);
            $msg = 'Deposit review completed.';
        }

        if ($a === 'provider' && can('provider.manage', $u)) {
            $db->prepare('INSERT INTO provider_accounts(provider_type,provider_name,account_email,account_label,internal_notes) VALUES(?,?,?,?,?)')
                ->execute([
                    trim((string)($_POST['provider_type'] ?? 'MANUAL_PARTNER')) ?: 'MANUAL_PARTNER',
                    trim((string)($_POST['provider_name'] ?? '')),
                    trim((string)($_POST['account_email'] ?? '')) ?: null,
                    trim((string)($_POST['account_label'] ?? '')) ?: null,
                    trim((string)($_POST['internal_notes'] ?? '')) ?: null
                ]);
            audit('provider_created','provider_accounts',(int)$db->lastInsertId());
            $msg = 'Provider added.';
        }

        if ($a === 'license' && can('license.manage', $u)) {
            $db->prepare('INSERT INTO licenses(provider_account_id,license_key,domain,reseller_id,status,purchase_date,cost,expires_at) VALUES(?,?,?,?,?,?,?,?)')
                ->execute([
                    ($_POST['provider_account_id'] ?? '') !== '' ? (int)$_POST['provider_account_id'] : null,
                    trim((string)($_POST['license_key'] ?? '')),
                    trim((string)($_POST['domain'] ?? '')) ?: null,
                    ($_POST['reseller_id'] ?? '') !== '' ? (int)$_POST['reseller_id'] : null,
                    $_POST['status'] ?? 'available',
                    $_POST['purchase_date'] ?? null,
                    ($_POST['cost'] ?? '') !== '' ? $_POST['cost'] : null,
                    $_POST['expires_at'] ?? null
                ]);
            audit('license_created','licenses',(int)$db->lastInsertId());
            $msg = 'License added.';
        }

        if ($a === 'license_status' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['available','active','suspended','expired','cancelled'], true) ? $_POST['status'] : 'available';
            $q = $db->prepare('SELECT status FROM licenses WHERE id=?');
            $q->execute([$id]);
            $old = $q->fetchColumn();
            $db->prepare('UPDATE licenses SET status=? WHERE id=?')->execute([$status,$id]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,$u['id'],'status_changed',($old ?: '-').' -> '.$status]);
            audit('license_status_changed','licenses',$id,$status);
            $msg = 'License status updated.';
        }

        if ($a === 'reassign_license' && can('license.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $rid = ($_POST['reseller_id'] ?? '') === '' ? null : (int)$_POST['reseller_id'];
            $q = $db->prepare('SELECT reseller_id FROM licenses WHERE id=?');
            $q->execute([$id]);
            $oldRid = $q->fetchColumn();
            $db->prepare('UPDATE licenses SET reseller_id=? WHERE id=?')->execute([$rid,$id]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,notes) VALUES(?,?,?,?)')->execute([$id,$u['id'],'reseller_changed','Old: '.($oldRid ?: 'unassigned').' New: '.($rid ?: 'unassigned')]);
            audit('license_reseller_changed','licenses',$id,(string)($rid ?: 'unassigned'));
            $msg = 'License assignment updated.';
        }

        if ($a === 'reseller' && can('reseller.manage', $u)) {
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

        if ($a === 'staff' && can('staff.manage', $u)) {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $pw = (string)($_POST['password'] ?? '');
            $role = in_array($_POST['role'] ?? '', ['admin','manager','staff'], true) ? $_POST['role'] : 'staff';
            if (strlen($pw) < 10) throw new RuntimeException('Staff password must be at least 10 characters.');
            $permissions = [];
            foreach (['provider.manage','license.manage','reseller.manage','reissue.manage','api.manage','staff.manage','ticket.manage'] as $perm) $permissions[$perm] = isset($_POST['perm'][$perm]);
            $db->prepare('INSERT INTO users(name,email,password_hash,role,status,permissions) VALUES(?,?,?,?,"active",?)')->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT),$role,json_encode($permissions)]);
            audit('staff_created','users',(int)$db->lastInsertId(),$role);
            $msg = 'Staff account created.';
        }

        if ($a === 'staff_status' && can('staff.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$u['id']) throw new RuntimeException('You cannot change your own status here.');
            $status = in_array($_POST['status'] ?? '', ['active','suspended','disabled'], true) ? $_POST['status'] : 'active';
            $db->prepare('UPDATE users SET status=? WHERE id=? AND role IN ("admin","manager","staff")')->execute([$status,$id]);
            audit('staff_status_changed','users',$id,$status);
            $msg = 'Staff status updated.';
        }

        if ($a === 'api_key' && can('api.manage', $u)) {
            $rid = (int)($_POST['reseller_id'] ?? 0);
            $plain = 'skynoc_'.bin2hex(random_bytes(24));
            $scopes = [];
            foreach (['licenses:read','licenses:manage','reissue:create','reissue:read','packages:read','orders:create','orders:read'] as $scope) if (isset($_POST['scope'][$scope])) $scopes[] = $scope;
            if (!$scopes) $scopes = ['licenses:read'];
            $db->prepare('INSERT INTO api_keys(reseller_id,name,key_prefix,key_hash,scopes,expires_at) VALUES(?,?,?,?,?,?)')->execute([$rid,trim((string)($_POST['name'] ?? '')) ?: 'API Key',substr($plain,0,15),hash('sha256',$plain),implode(',',$scopes),$_POST['expires_at'] ?: null]);
            $newKey = $plain;
            audit('api_key_created','api_keys',(int)$db->lastInsertId());
        }

        if ($a === 'api_revoke' && can('api.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE api_keys SET status="revoked" WHERE id=?')->execute([$id]);
            audit('api_key_revoked','api_keys',$id);
            $msg = 'API key revoked.';
        }

        if ($a === 'ticket_status' && can('ticket.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['open','pending','closed'], true) ? $_POST['status'] : 'open';
            $db->prepare('UPDATE tickets SET status=? WHERE id=?')->execute([$status,$id]);
            $q = $db->prepare('SELECT reseller_id,subject FROM tickets WHERE id=?');
            $q->execute([$id]);
            $t = $q->fetch();
            if ($t) notify_reseller((int)$t['reseller_id'],'ticket','Ticket updated',$t['subject'].' is now '.$status.'.');
            audit('ticket_status_changed','tickets',$id,$status);
            $msg = 'Ticket updated.';
        }

        if ($a === 'reissue' && can('reissue.manage', $u)) {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? '', ['review','manual_reissue','completed','rejected'], true) ? $_POST['status'] : 'review';
            $note = trim((string)($_POST['admin_note'] ?? '')) ?: null;
            $q = $db->prepare('SELECT rr.*,l.license_key FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id WHERE rr.id=?');
            $q->execute([$id]);
            $rr = $q->fetch();
            if (!$rr) throw new RuntimeException('Reissue request not found.');
            $db->beginTransaction();
            $db->prepare('UPDATE reissue_requests SET status=?,handled_by=?,admin_note=? WHERE id=?')->execute([$status,$u['id'],$note,$id]);
            if ($status === 'completed') {
                $db->prepare('UPDATE licenses SET domain=?,status="active" WHERE id=?')->execute([$rr['new_domain'],$rr['license_id']]);
                $db->prepare('INSERT INTO license_history(license_id,user_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?,?)')->execute([$rr['license_id'],$u['id'],'reissue_completed',$rr['current_domain'],$rr['new_domain'],$note]);
            }
            notify_reseller((int)$rr['reseller_id'],'reissue','Reissue '.$status,'License '.$rr['license_key'].' reissue request is now '.strtoupper($status).'.'.($note ? ' Note: '.$note : ''));
            $db->commit();
            audit('reissue_'.$status,'reissue_requests',$id);
            $msg = 'Reissue updated.';
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error = $e->getCode() === '23000' ? 'This email, slug or value already exists.' : 'The request could not be completed. Check the server error log.';
    error_log('SkyNoc admin error: '.$e->getMessage());
}

$packages = $db->query('SELECT id,name,slug,description,price,client_limit,billing_period,active,sort_order FROM packages ORDER BY sort_order,id DESC')->fetchAll();
$deposits = $db->query('SELECT d.*,r.name reseller_name,r.email reseller_email FROM deposit_requests d JOIN resellers r ON r.id=d.reseller_id ORDER BY d.id DESC LIMIT 100')->fetchAll();
$orders = $db->query('SELECT o.*,r.name reseller_name,p.name package_name,l.license_key FROM orders o JOIN resellers r ON r.id=o.reseller_id JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id ORDER BY o.id DESC LIMIT 100')->fetchAll();
$providers = $db->query('SELECT id,provider_name,account_email,account_label,status FROM provider_accounts ORDER BY id DESC')->fetchAll();
$resellers = $db->query('SELECT id,name,email,status FROM resellers ORDER BY id DESC')->fetchAll();
$licenses = $db->query('SELECT l.*,r.name reseller_name,p.provider_name FROM licenses l LEFT JOIN resellers r ON r.id=l.reseller_id LEFT JOIN provider_accounts p ON p.id=l.provider_account_id ORDER BY l.id DESC LIMIT 100')->fetchAll();
$reissues = $db->query('SELECT rr.*,l.license_key,r.name reseller_name FROM reissue_requests rr JOIN licenses l ON l.id=rr.license_id JOIN resellers r ON r.id=rr.reseller_id ORDER BY rr.id DESC LIMIT 50')->fetchAll();
$staff = $db->query('SELECT id,name,email,role,status,permissions,created_at FROM users WHERE role IN ("admin","manager","staff") ORDER BY id DESC')->fetchAll();
$apiKeys = $db->query('SELECT ak.id,ak.name,ak.key_prefix,ak.scopes,ak.status,ak.last_used_at,ak.expires_at,r.name reseller_name FROM api_keys ak JOIN resellers r ON r.id=ak.reseller_id ORDER BY ak.id DESC LIMIT 100')->fetchAll();
$tickets = $db->query('SELECT t.*,r.name reseller_name FROM tickets t LEFT JOIN resellers r ON r.id=t.reseller_id ORDER BY t.id DESC LIMIT 50')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SkyNoc Admin • Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--brand:#465fff;--brand-dark:#3641f5;--brand-soft:#ecf3ff;--ink:#101828;--muted:#667085;--line:#e4e7ec;--surface:#fff;--bg:#f9fafb;--success:#12b76a;--success-soft:#ecfdf3;--warning:#f79009;--warning-soft:#fffaeb;--danger:#f04438;--danger-soft:#fef3f2;--sidebar:#101828;--shadow:0 1px 3px rgba(16,24,40,.06),0 8px 24px rgba(16,24,40,.04)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:Outfit,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px}button,input,select,textarea{font:inherit}a{text-decoration:none;color:inherit}.app{min-height:100vh;display:flex}.sidebar{position:fixed;inset:0 auto 0 0;width:252px;background:var(--sidebar);color:#fff;padding:22px 14px;z-index:80;display:flex;flex-direction:column;transition:transform .25s ease}.brand{display:flex;align-items:center;gap:12px;padding:0 10px 25px;border-bottom:1px solid rgba(255,255,255,.08)}.brand-mark{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#465fff,#7a8cff);display:grid;place-items:center;font-weight:800;font-size:19px;box-shadow:0 8px 22px rgba(70,95,255,.3)}.brand-title{font-size:17px;font-weight:700;letter-spacing:-.02em}.brand-sub{font-size:11px;color:#98a2b3;margin-top:1px}.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:#667085;font-weight:700;padding:22px 11px 8px}.side-link{display:flex;align-items:center;gap:11px;color:#98a2b3;padding:10px 11px;border-radius:9px;margin:2px 0;font-weight:500;transition:.15s}.side-link svg{width:18px;height:18px;flex:none}.side-link:hover,.side-link.active{background:rgba(70,95,255,.16);color:#fff}.side-link.active{box-shadow:inset 3px 0 0 #465fff}.sidebar-footer{margin-top:auto;padding:13px 10px;border-top:1px solid rgba(255,255,255,.08);color:#98a2b3;font-size:12px}.user-mini{display:flex;align-items:center;gap:9px;color:#fff}.avatar{width:31px;height:31px;border-radius:50%;background:#344054;display:grid;place-items:center;font-size:12px;font-weight:700}.main{width:calc(100% - 252px);margin-left:252px;min-width:0}.topbar{height:72px;background:rgba(255,255,255,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:50}.mobile-menu{display:none;border:0;background:transparent;padding:7px;width:auto}.top-title{font-size:15px;font-weight:600}.top-right{display:flex;align-items:center;gap:9px}.top-link{padding:8px 11px;border:1px solid var(--line);border-radius:8px;color:#475467;background:#fff;font-weight:600;font-size:12px}.top-link:hover{border-color:#c2d6ff;color:var(--brand);background:var(--brand-soft)}.content{max-width:1540px;margin:0 auto;padding:27px 28px 55px}.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}.eyebrow{color:var(--brand);font-weight:700;font-size:12px;margin-bottom:5px}h1{font-size:30px;line-height:1.15;letter-spacing:-.035em;margin:0}.page-desc{margin:7px 0 0;color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:18px}.span-12{grid-column:span 12}.span-8{grid-column:span 8}.span-6{grid-column:span 6}.span-4{grid-column:span 4}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:21px}.stat-card{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:18px;box-shadow:var(--shadow);position:relative;overflow:hidden}.stat-card:after{content:"";position:absolute;right:-28px;bottom:-35px;width:90px;height:90px;border-radius:50%;background:var(--brand-soft)}.stat-top{display:flex;align-items:center;justify-content:space-between;gap:8px}.stat-icon{width:38px;height:38px;border-radius:9px;background:var(--brand-soft);color:var(--brand);display:grid;place-items:center}.stat-label{font-size:12px;color:var(--muted);font-weight:600}.stat-value{font-size:26px;font-weight:800;letter-spacing:-.03em;margin-top:10px}.stat-foot{font-size:11px;color:#98a2b3;margin-top:2px}.card{background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}.card-head{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:18px 20px;border-bottom:1px solid var(--line)}.card-title{font-size:16px;font-weight:700;margin:0}.card-sub{font-size:12px;color:var(--muted);margin-top:3px}.card-body{padding:20px}.section{margin-top:22px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:30px 0 12px}.section-head h2{font-size:18px;margin:0;letter-spacing:-.02em}.section-head p{margin:3px 0 0;color:var(--muted);font-size:12px}.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;text-transform:capitalize;white-space:nowrap}.badge:before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor}.badge-success{color:#027a48;background:var(--success-soft)}.badge-warning{color:#b54708;background:var(--warning-soft)}.badge-danger{color:#b42318;background:var(--danger-soft)}.badge-neutral{color:#475467;background:#f2f4f7}.badge-brand{color:#3641f5;background:var(--brand-soft)}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.full{grid-column:1/-1}.field label{display:block;font-size:12px;font-weight:600;color:#344054;margin:0 0 6px}.hint{font-size:11px;color:#98a2b3;margin-top:3px}input,select,textarea{width:100%;border:1px solid #d0d5dd;background:#fff;color:#101828;border-radius:8px;padding:10px 11px;outline:none;transition:.15s}input:focus,select:focus,textarea:focus{border-color:#84adff;box-shadow:0 0 0 3px rgba(70,95,255,.10)}textarea{min-height:84px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;width:auto;border:0;border-radius:8px;padding:9px 13px;background:var(--brand);color:#fff;font-weight:700;cursor:pointer;white-space:nowrap}.btn:hover{background:var(--brand-dark)}.btn-secondary{background:#fff;color:#344054;border:1px solid #d0d5dd}.btn-secondary:hover{background:#f9fafb;color:var(--brand)}.btn-danger{background:#fff;color:#b42318;border:1px solid #fecdca}.btn-danger:hover{background:var(--danger-soft)}.form-actions{display:flex;gap:8px;align-items:center;margin-top:5px;flex-wrap:wrap}.notice{border-radius:10px;padding:12px 14px;margin-bottom:18px;display:flex;gap:10px;align-items:flex-start}.notice strong{display:block}.notice-success{background:var(--success-soft);border:1px solid #abefc6;color:#027a48}.notice-error{background:var(--danger-soft);border:1px solid #fecdca;color:#b42318}.notice-secret{background:var(--warning-soft);border:1px solid #fedf89;color:#93370d;word-break:break-all}.table-wrap{overflow:auto}.table{width:100%;min-width:850px;border-collapse:collapse}.table th{background:#fcfcfd;color:#667085;font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;text-align:left;padding:11px 14px;border-bottom:1px solid var(--line)}.table td{padding:13px 14px;border-bottom:1px solid #f2f4f7;vertical-align:top;color:#344054;font-size:12px}.table tr:last-child td{border-bottom:0}.table tr:hover td{background:#fcfdff}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}.muted{color:var(--muted)}.strong{font-weight:700;color:#101828}.subline{font-size:11px;color:#98a2b3;margin-top:2px}.stack{display:grid;gap:8px}.row-form{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.row-form>*{width:auto;margin:0}.row-form select{min-width:120px}.row-form input{min-width:150px}.package{border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:10px}.package:last-child{margin-bottom:0}.package-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.package-name{font-weight:700}.package-price{font-weight:800;color:var(--brand)}.checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 12px;margin:5px 0}.checks label{font-size:12px;color:#475467;display:flex;align-items:center;gap:7px}.checks input{width:auto}.empty{padding:25px;text-align:center;color:#98a2b3}.overlay{display:none;position:fixed;inset:0;background:rgba(16,24,40,.45);z-index:70}.overlay.show{display:block}@media(max-width:1150px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.span-8,.span-6,.span-4{grid-column:span 12}}@media(max-width:850px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{width:100%;margin-left:0}.mobile-menu{display:inline-flex}.topbar{padding:0 14px}.top-link{display:none}.content{padding:20px 14px 45px}.page-head{align-items:flex-start}.page-head h1{font-size:26px}.form-grid{grid-template-columns:1fr}.stats{gap:10px}.stat-card{padding:14px}}@media(max-width:560px){.stats{grid-template-columns:1fr 1fr}.stats .stat-value{font-size:22px}.card-head,.card-body{padding:14px}.section-head{margin-top:24px}.row-form{align-items:stretch;flex-direction:column}.row-form>*{width:100%}.checks{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="overlay" id="overlay"></div>
<div class="app">
<aside class="sidebar" id="sidebar">
  <div class="brand"><div class="brand-mark">S</div><div><div class="brand-title">SkyNoc</div><div class="brand-sub">License Reseller Platform</div></div></div>
  <div class="nav-label">Workspace</div>
  <a class="side-link active" href="/admin"><span>▦</span>Dashboard</a>
  <a class="side-link" href="#packages"><span>◈</span>Packages</a>
  <a class="side-link" href="#orders"><span>▤</span>Orders</a>
  <a class="side-link" href="#licenses"><span>▥</span>License Inventory</a>
  <a class="side-link" href="#deposits"><span>◫</span>Deposits</a>
  <div class="nav-label">Management</div>
  <a class="side-link" href="/admin/reseller-levels"><span>★</span>Reseller Levels</a>
  <a class="side-link" href="/admin/reports"><span>↗</span>Reports</a>
  <a class="side-link" href="/admin/coupons"><span>◇</span>Coupons</a>
  <a class="side-link" href="/admin/settings"><span>⚙</span>Settings</a>
  <a class="side-link" href="/dashboard"><span>→</span>Back to Portal</a>
  <div class="sidebar-footer"><div class="user-mini"><div class="avatar"><?=e(strtoupper(substr((string)$u['name'],0,1)))?></div><div><div><?=e($u['name'])?></div><div style="color:#667085;margin-top:1px"><?=e($u['role'])?></div></div></div></div>
</aside>
<main class="main">
<header class="topbar"><div style="display:flex;align-items:center;gap:10px"><button class="mobile-menu" id="menuBtn" aria-label="Open menu">☰</button><div class="top-title">Admin Console</div></div><div class="top-right"><a class="top-link" href="/admin/settings">Platform Settings</a><a class="top-link" href="/logout">Sign out</a></div></header>
<div class="content">
  <div class="page-head"><div><div class="eyebrow">SKYNOC ADMINISTRATION</div><h1>Overview</h1><p class="page-desc">Manage reseller packages, orders, wallet deposits and license inventory from one place.</p></div></div>
  <?php if($msg): ?><div class="notice notice-success"><span>✓</span><div><strong>Action completed</strong><?=e($msg)?></div></div><?php endif;?>
  <?php if($error): ?><div class="notice notice-error"><span>!</span><div><strong>Something went wrong</strong><?=e($error)?></div></div><?php endif;?>
  <?php if($newKey): ?><div class="notice notice-secret"><span>🔑</span><div><strong>New API key — copy it now.</strong><div class="mono"><?=e($newKey)?></div></div></div><?php endif;?>
  <div class="stats">
    <div class="stat-card"><div class="stat-top"><div class="stat-label">Licenses</div><div class="stat-icon">⌁</div></div><div class="stat-value"><?=count($licenses)?></div><div class="stat-foot">Latest inventory records</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-label">Resellers</div><div class="stat-icon">◉</div></div><div class="stat-value"><?=count($resellers)?></div><div class="stat-foot">Registered accounts</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-label">Pending deposits</div><div class="stat-icon">৳</div></div><div class="stat-value"><?=count(array_filter($deposits,fn($d)=>$d['status']==='pending'))?></div><div class="stat-foot">Waiting for review</div></div>
    <div class="stat-card"><div class="stat-top"><div class="stat-label">Open reissues</div><div class="stat-icon">↻</div></div><div class="stat-value"><?=count(array_filter($reissues,fn($r)=>in_array($r['status'],['pending','review','manual_reissue'],true)))?></div><div class="stat-foot">Need staff action</div></div>
  </div>
  <div class="section-head" id="packages"><div><h2>License packages</h2><p>Create and control what resellers can order.</p></div></div>
  <div class="grid">
    <?php if(can('license.manage',$u)): ?><div class="card span-4"><div class="card-head"><div><div class="card-title">Create package</div><div class="card-sub">New packages are enabled for reseller ordering.</div></div></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="package_create"><?=csrf_field()?><div class="form-grid"><div class="field full"><label>Package name</label><input name="name" placeholder="Professional" required></div><div class="field"><label>Slug</label><input name="slug" placeholder="professional" pattern="[a-z0-9][a-z0-9_-]{1,119}" required></div><div class="field"><label>Price (USD)</label><input name="price" type="number" step="0.01" min="0.01" placeholder="25.00" required></div><div class="field"><label>Client limit</label><input name="client_limit" type="number" min="1" placeholder="100"></div><div class="field"><label>Billing period</label><select name="billing_period"><option value="monthly">Monthly</option><option value="annual">Annual</option><option value="one_time">One time</option></select></div><div class="field full"><label>Sort order</label><input name="sort_order" type="number" value="0"></div><div class="field full"><label>Description</label><textarea name="description" placeholder="Short package description"></textarea></div></div><div class="form-actions"><button class="btn">Create package</button></div></form></div></div><?php endif;?>
    <div class="card span-8"><div class="card-head"><div><div class="card-title">Existing packages</div><div class="card-sub">Disabled packages disappear from the reseller order screen.</div></div><span class="badge badge-brand"><?=count($packages)?> packages</span></div><div class="card-body">
      <?php if(!$packages):?><div class="empty">No packages yet. Create the first reseller package.</div><?php endif;?>
      <?php foreach($packages as $p):?><div class="package"><div class="package-top"><div><div class="package-name"><?=e($p['name'])?></div><div class="subline"><?=e($p['slug'])?></div></div><div class="package-price">$<?=number_format((float)$p['price'],2)?></div></div><?php if(can('license.manage',$u)):?><form method="post"><input type="hidden" name="action" value="package_update"><input type="hidden" name="id" value="<?=$p['id']?>"><?=csrf_field()?><div class="form-grid"><div class="field"><label>Name</label><input name="name" value="<?=e($p['name'])?>" required></div><div class="field"><label>Price</label><input name="price" type="number" step="0.01" min="0.01" value="<?=e((string)$p['price'])?>" required></div><div class="field"><label>Client limit</label><input name="client_limit" type="number" min="1" value="<?=e((string)($p['client_limit'] ?? ''))?>" placeholder="Unlimited"></div><div class="field"><label>Billing</label><select name="billing_period"><option value="monthly" <?=$p['billing_period']==='monthly'?'selected':''?>>Monthly</option><option value="annual" <?=$p['billing_period']==='annual'?'selected':''?>>Annual</option><option value="one_time" <?=$p['billing_period']==='one_time'?'selected':''?>>One time</option></select></div><div class="field full"><label>Description</label><textarea name="description"><?=e($p['description'] ?? '')?></textarea></div></div><div class="form-actions"><button class="btn btn-secondary">Save changes</button></div></form><form method="post" class="form-actions"><?=csrf_field()?><input type="hidden" name="action" value="package_status"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="active" value="<?=$p['active']?0:1?>"><button class="btn <?=$p['active']?'btn-danger':'btn-secondary'?>"><?=$p['active']?'Disable package':'Enable package'?></button><span class="badge <?=$p['active']?'badge-success':'badge-neutral'?>"><?=$p['active']?'active':'disabled'?></span></form><?php endif;?></div><?php endforeach;?></div></div>
  </div>
  <div class="section-head" id="orders"><div><h2>Reseller orders</h2><p>Review orders, assign available licenses and issue refunds when required.</p></div><span class="badge badge-brand"><?=count($orders)?> latest</span></div>
  <div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Order</th><th>Reseller</th><th>Package</th><th>Domain</th><th>Amount</th><th>Status</th><th>Fulfillment</th></tr></thead><tbody><?php if(!$orders):?><tr><td colspan="7" class="empty">No reseller orders yet.</td></tr><?php endif;?><?php foreach($orders as $o):$os=$o['status'];$oc=$os==='completed'?'badge-success':(in_array($os,['pending','processing'],true)?'badge-warning':($os==='rejected'?'badge-danger':'badge-neutral'));?><tr><td><span class="strong">#<?=$o['id']?></span><div class="subline"><?=e($o['created_at'] ?? '')?></div></td><td><span class="strong"><?=e($o['reseller_name'])?></span></td><td><?=e($o['package_name'])?></td><td class="mono"><?=e($o['domain'])?></td><td class="strong">$<?=number_format((float)$o['amount'],2)?></td><td><span class="badge <?=$oc?>"><?=e($os)?></span></td><td><?php if(in_array($os,['pending','processing'],true)):?><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="order_review"><input type="hidden" name="id" value="<?=$o['id']?>"><select name="status"><option value="processing">Processing</option><option value="completed">Completed</option><option value="rejected">Reject + Refund</option></select><select name="license_id"><option value="">Select available license</option><?php foreach($licenses as $li):if($li['status']==='available' && $li['reseller_id']===null):?><option value="<?=$li['id']?>"><?=e($li['license_key'])?></option><?php endif;endforeach;?></select><input name="notes" placeholder="Order note"><button class="btn">Update order</button></form><?php else:?><span class="mono"><?=e($o['license_key'] ?? '-')?></span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div>
  <div class="section-head" id="deposits"><div><h2>Wallet deposits</h2><p>Approve or reject reseller funding requests.</p></div><span class="badge badge-warning"><?=count(array_filter($deposits,fn($d)=>$d['status']==='pending'))?> pending</span></div>
  <div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Reseller</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th><th>Review</th></tr></thead><tbody><?php if(!$deposits):?><tr><td colspan="7" class="empty">No deposit requests.</td></tr><?php endif;?><?php foreach($deposits as $d):$ds=$d['status'];$dc=$ds==='approved'?'badge-success':($ds==='pending'?'badge-warning':($ds==='rejected'?'badge-danger':'badge-neutral'));?><tr><td>#<?=$d['id']?></td><td><span class="strong"><?=e($d['reseller_name'])?></span><div class="subline"><?=e($d['reseller_email'])?></div></td><td class="strong">$<?=number_format((float)$d['amount'],2)?></td><td><?=e($d['method'])?></td><td class="mono"><?=e($d['reference'] ?: '-')?></td><td><span class="badge <?=$dc?>"><?=e($ds)?></span></td><td><?php if($ds==='pending' && can('reseller.manage',$u)):?><form method="post" class="row-form"><?=csrf_field()?><input type="hidden" name="action" value="deposit_review"><input type="hidden" name="id" value="<?=$d['id']?>"><select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select><input name="review_note" placeholder="Review note"><button class="btn">Save</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div>
  <div class="section-head"><div><h2>Operations</h2><p>Common administration actions.</p></div></div>
  <div class="grid">
    <?php if(can('provider.manage',$u)):?><div class="card span-4"><div class="card-head"><div><div class="card-title">Provider account</div><div class="card-sub">Store provider identification only — never provider passwords.</div></div></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="provider"><?=csrf_field()?><div class="form-grid"><div class="field full"><label>Provider name</label><input name="provider_name" placeholder="Provider name" required></div><div class="field"><label>Account email</label><input name="account_email" type="email" placeholder="Internal email"></div><div class="field"><label>Label</label><input name="account_label" placeholder="EU Partner"></div><div class="field full"><label>Provider type</label><select name="provider_type"><option>MANUAL_PARTNER</option><option>WHMCS_API</option></select></div><div class="field full"><label>Internal notes</label><textarea name="internal_notes"></textarea></div></div><div class="form-actions"><button class="btn">Add provider</button></div></form></div></div><?php endif;?>
    <?php if(can('reseller.manage',$u)):?><div class="card span-4"><div class="card-head"><div><div class="card-title">Create reseller</div><div class="card-sub">Create an active reseller account.</div></div></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="reseller"><?=csrf_field()?><div class="form-grid"><div class="field full"><label>Name</label><input name="name" placeholder="Reseller name" required></div><div class="field full"><label>Login email</label><input name="email" type="email" placeholder="name@example.com" required></div><div class="field full"><label>Temporary password</label><input name="password" type="password" placeholder="Minimum 10 characters" required></div></div><div class="form-actions"><button class="btn">Create reseller</button></div></form></div></div><?php endif;?>
    <?php if(can('api.manage',$u)):?><div class="card span-4"><div class="card-head"><div><div class="card-title">API key</div><div class="card-sub">Generate a reseller-scoped API credential.</div></div></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="api_key"><?=csrf_field()?><div class="field"><label>Reseller</label><select name="reseller_id" required><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Key name</label><input name="name" placeholder="WHMCS Integration"></div><div class="field"><label>Expiry</label><input name="expires_at" type="datetime-local"></div><div class="checks"><?php foreach(['licenses:read','licenses:manage','reissue:create','reissue:read','packages:read','orders:create','orders:read'] as $scope):?><label><input type="checkbox" name="scope[<?=e($scope)?>]" checked> <?=e($scope)?></label><?php endforeach;?></div><button class="btn">Generate key</button></form></div></div><?php endif;?>
    <?php if(can('license.manage',$u)):?><div class="card span-6"><div class="card-head"><div><div class="card-title">Add license inventory</div><div class="card-sub">Add an available license that can later be assigned to an order.</div></div></div><div class="card-body"><form method="post"><input type="hidden" name="action" value="license"><?=csrf_field()?><div class="form-grid"><div class="field full"><label>License key</label><input name="license_key" placeholder="XXXX-XXXX-XXXX" required></div><div class="field"><label>Domain</label><input name="domain" placeholder="client-domain.com"></div><div class="field"><label>Provider</label><select name="provider_account_id"><option value="">Unassigned</option><?php foreach($providers as $p):?><option value="<?=$p['id']?>"><?=e($p['provider_name'].' — '.($p['account_label'] ?? ''))?></option><?php endforeach;?></select></div><div class="field"><label>Reseller</label><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>"><?=e($r['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Status</label><select name="status"><option>available</option><option>active</option><option>suspended</option><option>expired</option><option>cancelled</option></select></div><div class="field"><label>Purchase date</label><input name="purchase_date" type="date"></div><div class="field"><label>Cost</label><input name="cost" type="number" step="0.01" placeholder="0.00"></div><div class="field"><label>Expires</label><input name="expires_at" type="datetime-local"></div></div><div class="form-actions"><button class="btn">Add license</button></div></form></div></div><?php endif;?>
  </div>
  <div class="section-head" id="licenses"><div><h2>License inventory</h2><p>Control availability and reseller assignment.</p></div><span class="badge badge-brand"><?=count($licenses)?> records</span></div>
  <div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>License</th><th>Domain</th><th>Provider</th><th>Reseller</th><th>Status</th><th>Controls</th></tr></thead><tbody><?php if(!$licenses):?><tr><td colspan="7" class="empty">No license inventory.</td></tr><?php endif;?><?php foreach($licenses as $l):$ls=$l['status'];$lc=$ls==='active'?'badge-success':($ls==='available'?'badge-brand':(in_array($ls,['suspended','expired'],true)?'badge-warning':'badge-neutral'));?><tr><td>#<?=$l['id']?></td><td class="mono"><?=e($l['license_key'])?></td><td><?=e($l['domain'] ?: '-')?></td><td><?=e($l['provider_name'] ?: '-')?></td><td><?=e($l['reseller_name'] ?: 'Unassigned')?></td><td><span class="badge <?=$lc?>"><?=e($ls)?></span></td><td><?php if(can('license.manage',$u)):?><div class="stack"><form method="post" class="row-form"><?=csrf_field()?><input type="hidden" name="action" value="license_status"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="status"><?php foreach(['available','active','suspended','expired','cancelled'] as $st):?><option <?=$ls===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select><button class="btn btn-secondary">Save</button></form><form method="post" class="row-form"><?=csrf_field()?><input type="hidden" name="action" value="reassign_license"><input type="hidden" name="id" value="<?=$l['id']?>"><select name="reseller_id"><option value="">Unassigned</option><?php foreach($resellers as $r):?><option value="<?=$r['id']?>" <?=$l['reseller_id']==$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select><button class="btn btn-secondary">Assign</button></form></div><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div>
  <?php if(can('staff.manage',$u)):?><div class="section-head"><div><h2>Staff</h2><p>Manage staff status and access.</p></div></div><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Permissions</th><th>Action</th></tr></thead><tbody><?php foreach($staff as $st):?><tr><td class="strong"><?=e($st['name'])?></td><td><?=e($st['email'])?></td><td><?=e($st['role'])?></td><td><span class="badge <?=$st['status']==='active'?'badge-success':'badge-danger'?>"><?=e($st['status'])?></span></td><td class="mono"><?=e($st['permissions'] ?: 'role defaults')?></td><td><form method="post" class="row-form"><?=csrf_field()?><input type="hidden" name="action" value="staff_status"><input type="hidden" name="id" value="<?=$st['id']?>"><select name="status"><option>active</option><option>suspended</option><option>disabled</option></select><button class="btn btn-secondary">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
  <?php if(can('reissue.manage',$u)):?><div class="section-head"><div><h2>Reissue requests</h2><p>Review domain change / license reissue requests.</p></div></div><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>License</th><th>Reseller</th><th>Current → New</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($reissues as $r):$rs=$r['status'];?><tr><td>#<?=$r['id']?></td><td class="mono"><?=e($r['license_key'])?></td><td><?=e($r['reseller_name'])?></td><td><?=e($r['current_domain'])?> <span class="muted">→</span> <?=e($r['new_domain'])?></td><td><span class="badge <?=$rs==='completed'?'badge-success':($rs==='rejected'?'badge-danger':'badge-warning')?>"><?=e($rs)?></span></td><td><form method="post" class="row-form"><?=csrf_field()?><input type="hidden" name="action" value="reissue"><input type="hidden" name="id" value="<?=$r['id']?>"><select name="status"><option>review</option><option>manual_reissue</option><option>completed</option><option>rejected</option></select><input name="admin_note" placeholder="Admin note"><button class="btn btn-secondary">Update</button></form></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
  <?php if(can('ticket.manage',$u)):?><div class="section-head"><div><h2>Support tickets</h2><p>Quick status control for reseller support tickets.</p></div></div><div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Reseller</th><th>Subject</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($tickets as $t):?><tr><td>#<?=$t['id']?></td><td><?=e($t['reseller_name'] ?? '-')?></td><td class="strong"><?=e($t['subject'])?></td><td><?=e($t['priority'])?></td><td><span class="badge badge-neutral"><?=e($t['status'])?></span></td><td><form method="post" class="row-form"><?=csrf_field()?><input type="hidden" name="action" value="ticket_status"><input type="hidden" name="id" value="<?=$t['id']?>"><select name="status"><option>open</option><option>pending</option><option>closed</option></select><button class="btn btn-secondary">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></div><?php endif;?>
</div></main></div>
<script>const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('overlay'),menu=document.getElementById('menuBtn');function closeMenu(){sidebar.classList.remove('open');overlay.classList.remove('show')}if(menu)menu.addEventListener('click',()=>{sidebar.classList.add('open');overlay.classList.add('show')});overlay.addEventListener('click',closeMenu);document.querySelectorAll('.side-link[href^="#"]').forEach(a=>a.addEventListener('click',closeMenu));</script>
</body></html>
