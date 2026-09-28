<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user()) redirect('/dashboard.php');
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $s=$db->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $s->execute([trim($_POST['email'] ?? '')]); $u=$s->fetch();
    if ($u && $u['status']==='active' && password_verify($_POST['password'] ?? '', $u['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id']=$u['id']; audit('login'); redirect($u['role']==='reseller' ? '/reseller.php' : '/dashboard.php'); }
    $error='Invalid email or password.';
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Login</title><style>body{font-family:Arial;background:#f4f6f8;display:grid;place-items:center;height:100vh}.card{background:#fff;padding:30px;border-radius:12px;width:min(400px,90%);box-shadow:0 8px 30px #0001}input,button{width:100%;padding:12px;margin:7px 0;box-sizing:border-box}button{background:#111827;color:white;border:0;border-radius:6px}.err{color:#b91c1c}</style></head><body><div class="card"><h2>SkyNoc License Manager</h2><?php if($error): ?><p class="err"><?=e($error)?></p><?php endif; ?><form method="post"><?=csrf_field()?><input name="email" type="email" placeholder="Email" required><input name="password" type="password" placeholder="Password" required><button>Sign in</button></form></div></body></html>