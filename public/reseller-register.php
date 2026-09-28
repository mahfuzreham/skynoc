<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    $u = current_user();
    redirect($u['role'] === 'reseller' ? '/reseller' : '/dashboard');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();

        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirmation'] ?? '');

        if ($name === '' || mb_strlen($name) < 2) {
            throw new RuntimeException('Please enter your full name.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('Password must be at least 10 characters.');
        }
        if (!hash_equals($password, $confirm)) {
            throw new RuntimeException('Passwords do not match.');
        }

        $check = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new RuntimeException('An account with this email already exists. Please sign in instead.');
        }

        $db->beginTransaction();

        $stmt = $db->prepare('INSERT INTO users(name,email,password_hash,role,status) VALUES(?, ?, ?, "reseller", "active")');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO resellers(user_id,name,email,status,wallet_balance) VALUES(?, ?, ?, "pending", 0.00)');
        $stmt->execute([$userId, $name, $email]);

        $db->commit();

        audit('reseller_registered', 'users', $userId);

        redirect('/login?registered=1');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Registration could not be completed. Please try again.';
        if (!($e instanceof RuntimeException)) {
            error_log('SkyNoc reseller registration error: '.$e->getMessage());
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Create a SkyNoc reseller account.">
<title>Register as Reseller — SkyNoc</title>
<style>
:root{--navy:#0b1730;--blue:#1769e0;--blue2:#0e56c7;--ink:#14213d;--muted:#65738a;--line:#dfe6ef;--soft:#f6f9fd;--red:#b42318;--green:#159a69}
*{box-sizing:border-box}body{margin:0;min-height:100svh;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;background:linear-gradient(135deg,#f5f8fd,#fff 55%,#eef5ff);color:var(--ink);display:grid;place-items:center;padding:24px}.layout{width:min(980px,100%);display:grid;grid-template-columns:1fr 1fr;background:#fff;border:1px solid var(--line);border-radius:20px;overflow:hidden;box-shadow:0 25px 70px rgba(17,38,68,.12)}.intro{background:var(--navy);color:#fff;padding:44px}.brand{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:900}.mark{width:35px;height:35px;border-radius:10px;background:var(--blue);display:grid;place-items:center;font-size:13px}.intro h1{font-size:38px;line-height:1.08;letter-spacing:-1.5px;margin:55px 0 15px}.intro p{color:#afbdd1;font-size:14px;line-height:1.7}.points{margin-top:28px}.point{display:flex;gap:10px;margin:16px 0;color:#dce5f2;font-size:12px}.point b{display:block;color:#fff;font-size:13px}.check{width:24px;height:24px;border-radius:7px;background:#17365f;color:#7eb1f4;display:grid;place-items:center;font-weight:900;flex:none}.formbox{padding:44px}.back{font-size:12px;color:#66758a;text-decoration:none}.formbox h2{font-size:27px;letter-spacing:-.7px;margin:28px 0 5px}.sub{font-size:13px;color:var(--muted);margin:0 0 24px}.field{margin-bottom:15px}.field label{display:block;font-size:11px;font-weight:800;color:#3d4d64;margin-bottom:6px}.field input{width:100%;padding:13px;border:1px solid #ccd7e5;border-radius:9px;font:inherit;outline:none}.field input:focus{border-color:#6da2ec;box-shadow:0 0 0 3px #e8f1ff}.btn{width:100%;padding:13px;border:0;border-radius:9px;background:var(--blue);color:#fff;font-weight:800;font-size:13px;cursor:pointer}.btn:hover{background:var(--blue2)}.error{background:#fff1f0;color:var(--red);border:1px solid #ffd3cf;padding:11px;border-radius:9px;font-size:12px;margin-bottom:15px}.hint{font-size:10px;color:#8490a2;margin-top:13px;line-height:1.6}.login{text-align:center;font-size:12px;color:var(--muted);margin-top:18px}.login a{color:var(--blue);font-weight:800;text-decoration:none}@media(max-width:720px){.layout{grid-template-columns:1fr}.intro{padding:30px}.intro h1{margin-top:35px;font-size:31px}.points{display:none}.formbox{padding:30px 24px}}
</style>
</head>
<body>
<div class="layout">
<section class="intro">
<a class="brand" href="/"><span class="mark">SN</span>SkyNoc</a>
<h1>Start your reseller account.</h1>
<p>Create your SkyNoc reseller account and manage WHMCS licenses, reissue requests, support and API access from one portal.</p>
<div class="points">
<div class="point"><span class="check">✓</span><div><b>License management</b>View and manage licenses assigned to your account.</div></div>
<div class="point"><span class="check">✓</span><div><b>Reissue requests</b>Submit domain changes and follow their status.</div></div>
<div class="point"><span class="check">✓</span><div><b>Reseller API</b>Connect your billing platform with scoped API access.</div></div>
</div>
</section>
<section class="formbox">
<a class="back" href="/">← Back to SkyNoc</a>
<h2>Create reseller account</h2>
<p class="sub">Use a real email address. You will use it to sign in to the reseller portal.</p>
<?php if($error): ?><div class="error"><?=e($error)?></div><?php endif; ?>
<form method="post" autocomplete="on">
<?=csrf_field()?>
<div class="field"><label>Full name</label><input name="name" type="text" autocomplete="name" placeholder="Your full name" value="<?=e($_POST['name'] ?? '')?>" required></div>
<div class="field"><label>Email address</label><input name="email" type="email" autocomplete="email" placeholder="you@example.com" value="<?=e($_POST['email'] ?? '')?>" required></div>
<div class="field"><label>Password</label><input name="password" type="password" autocomplete="new-password" placeholder="At least 10 characters" minlength="10" required></div>
<div class="field"><label>Confirm password</label><input name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repeat your password" minlength="10" required></div>
<button class="btn" type="submit">Create Reseller Account →</button>
<p class="hint">By creating an account, you agree to use the SkyNoc reseller portal for legitimate licensing and account operations. Account activation may be subject to SkyNoc review.</p>
</form>
<div class="login">Already have an account? <a href="/login">Sign in</a></div>
</section>
</div>
</body>
</html>
