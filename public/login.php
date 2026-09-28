<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user()) {
    $u = current_user();
    redirect($u['role'] === 'reseller' ? '/reseller' : '/dashboard');
}
$error=null;
$registered = isset($_GET['registered']) && $_GET['registered'] === '1';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $s=$db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
    $s->execute([trim($_POST['email'] ?? '')]);
    $u=$s->fetch();
    if ($u && $u['status']==='active' && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']=$u['id'];
        audit('login');
        redirect($u['role']==='reseller' ? '/reseller' : '/dashboard');
    }
    $error='Invalid email or password.';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Login</title><style>body{font-family:Arial;background:#f4f6f8;display:grid;place-items:center;height:100vh}.card{background:#fff;padding:30px;border-radius:12px;width:min(400px,90%);box-shadow:0 8px 30px #0001}input,button{width:100%;padding:12px;margin:7px 0;box-sizing:border-box}button{background:#111827;color:white;border:0;border-radius:6px}.err{color:#b91c1c}a{color:#111827}</style><style>:root{color-scheme:light}*{box-sizing:border-box}body{min-height:100svh;margin:0;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:linear-gradient(135deg,#f8fafc,#eef2ff);display:grid;place-items:center;padding:20px}.card{width:min(420px,100%);padding:32px;border-radius:22px;background:#fff;box-shadow:0 20px 60px #11182718}.brand{font-size:24px;font-weight:800}.sub{color:#64748b}.field{width:100%;padding:14px;border:1px solid #dbe2ea;border-radius:10px;margin:7px 0;font-size:15px}.btn{width:100%;padding:14px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:700;cursor:pointer}@media(max-width:480px){.card{padding:24px}}</style></head><body><div class="card"><div class="brand">SkyNoc</div><p class="sub">Sign in to your account</p><?php if($registered): ?><p style="background:#ecfdf5;color:#166534;padding:10px;border-radius:9px;font-size:12px">Reseller account created successfully. You can sign in now.</p><?php endif; ?><?php if($error): ?><p class="err" style="background:#fef2f2;padding:10px;border-radius:9px"><?=e($error)?></p><?php endif; ?><form method="post"><?=csrf_field()?><input name="email" type="email" class="field" placeholder="Email" required><input name="password" type="password" class="field" placeholder="Password" required><button class="btn">Sign in</button></form><p><a href="/">← Back to SkyNoc</a></p></div></body></html>