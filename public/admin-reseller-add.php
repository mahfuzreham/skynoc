<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin','manager','staff']);
if (!can('reseller.manage', $u)) {
    http_response_code(403);
    exit('Access denied.');
}

$msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '') throw new RuntimeException('Reseller name is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid email address is required.');
        if (strlen($password) < 10) throw new RuntimeException('Password must be at least 10 characters.');

        $check = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) throw new RuntimeException('An account with this email already exists.');

        $db->beginTransaction();

        $stmt = $db->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?,?,?,"reseller","active")');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$db->lastInsertId();

        // Admin-created resellers are activated immediately and do not require the public $15 activation deposit.
        // Wallet starts at $0.00; normal paid license purchases still require sufficient wallet balance.
        $stmt = $db->prepare('INSERT INTO resellers(user_id,name,email,status,wallet_balance) VALUES(?,?,?,"active",0.00)');
        $stmt->execute([$userId, $name, $email]);
        $resellerId = (int)$db->lastInsertId();

        $db->commit();

        audit('reseller_created', 'resellers', $resellerId, 'Admin-created active reseller without activation deposit');
        notify_reseller($resellerId, 'account', 'Reseller account activated', 'Your SkyNoc reseller account has been activated by an administrator. No activation deposit was required.');
        $msg = 'Reseller created and activated successfully. No $15 activation deposit was required.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'The reseller account could not be created.';
        if (!($e instanceof RuntimeException)) error_log('SkyNoc admin reseller creation error: '.$e->getMessage());
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Add Active Reseller — SkyNoc</title>
<style>
body{margin:0;background:#f5f7fb;color:#172033;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;padding:28px}.wrap{max-width:720px;margin:0 auto}.card{background:#fff;border:1px solid #e1e7ef;border-radius:16px;padding:28px;box-shadow:0 12px 35px rgba(20,35,60,.08)}h1{margin:0 0 8px;font-size:26px}p{color:#66758a;font-size:14px;line-height:1.6}.note{background:#eef6ff;border:1px solid #cfe3ff;padding:13px;border-radius:10px;margin:18px 0;font-size:13px}.ok{background:#ecfdf3;color:#067647;border:1px solid #abefc6;padding:12px;border-radius:10px}.err{background:#fff1f0;color:#b42318;border:1px solid #ffd1cc;padding:12px;border-radius:10px}.field{margin:16px 0}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:7px}.field input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd5e2;border-radius:9px;font:inherit}.btn{border:0;border-radius:9px;padding:12px 16px;background:#1769e0;color:#fff;font-weight:800;cursor:pointer}.links{margin-top:18px;font-size:13px}.links a{color:#1769e0;text-decoration:none;font-weight:700}
</style>
</head>
<body>
<div class="wrap">
<div class="card">
<h1>Add Active Reseller</h1>
<p>Create a reseller directly from the admin panel. This admin-created account is activated immediately and does not require the public $15 activation deposit.</p>
<div class="note"><strong>Important:</strong> Wallet balance starts at <strong>$0.00</strong>. The reseller can log in immediately, but paid license purchases still require wallet funds.</div>
<?php if($msg): ?><div class="ok"><?=e($msg)?></div><?php endif; ?>
<?php if($error): ?><div class="err"><?=e($error)?></div><?php endif; ?>
<form method="post">
<?=csrf_field()?>
<div class="field"><label>Full name</label><input name="name" value="<?=e($_POST['name'] ?? '')?>" required></div>
<div class="field"><label>Email address</label><input name="email" type="email" value="<?=e($_POST['email'] ?? '')?>" required></div>
<div class="field"><label>Password</label><input name="password" type="password" minlength="10" required></div>
<button class="btn" type="submit">Create & Activate Reseller</button>
</form>
<div class="links"><a href="/admin">← Back to Admin</a> · <a href="/admin/settings">Settings</a></div>
</div>
</div>
</body>
</html>
