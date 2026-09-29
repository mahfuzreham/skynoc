<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user()) { $u=current_user(); redirect($u['role']==='reseller'?'/reseller':'/dashboard'); }
$error=null; $registered=isset($_GET['registered'])&&$_GET['registered']==='1'; $challenge=isset($_SESSION['pending_2fa_user']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    if ($challenge && ($_POST['action']??'')==='2fa') {
        $uid=(int)($_SESSION['pending_2fa_user']??0); $s=$db->prepare('SELECT * FROM users WHERE id=? AND status="active" LIMIT 1');$s->execute([$uid]);$u=$s->fetch();$two=$u?sn_user_2fa($uid):null;
        if($u&&$two&&$two['enabled']&&sn_totp_ok($two['secret'],trim((string)($_POST['code']??'')))){
            unset($_SESSION['pending_2fa_user']); session_regenerate_id(true); $_SESSION['user_id']=$uid; audit('login_2fa'); redirect($u['role']==='reseller'?'/reseller':'/dashboard');
        }
        $error='Invalid authenticator code.';
    } else {
        $s=$db->prepare('SELECT * FROM users WHERE email=? LIMIT 1');$s->execute([trim($_POST['email']??'')]);$u=$s->fetch();
        if($u&&$u['status']==='active'&&password_verify($_POST['password']??'',$u['password_hash'])){
            if(sn_2fa_required((int)$u['id'])){session_regenerate_id(true);$_SESSION['pending_2fa_user']=(int)$u['id'];$challenge=true;}
            else{session_regenerate_id(true);$_SESSION['user_id']=$u['id'];audit('login');redirect($u['role']==='reseller'?'/reseller':'/dashboard');}
        } else $error='Invalid email or password.';
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SkyNoc Login</title><style>*{box-sizing:border-box}body{min-height:100svh;margin:0;font-family:Inter,system-ui,sans-serif;background:linear-gradient(135deg,#f8fafc,#eef2ff);display:grid;place-items:center;padding:20px}.card{width:min(420px,100%);padding:32px;border-radius:22px;background:#fff;box-shadow:0 20px 60px #11182718}.brand{font-size:24px;font-weight:800}.sub{color:#64748b}.field{width:100%;padding:14px;border:1px solid #dbe2ea;border-radius:10px;margin:7px 0;font-size:15px}.btn{width:100%;padding:14px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:700;cursor:pointer}.err{color:#b91c1c;background:#fef2f2;padding:10px;border-radius:9px}a{color:#111827}</style></head><body><div class="card"><div class="brand">SkyNoc</div><?php if($challenge):?><p class="sub">Enter your authenticator code</p><?php else:?><p class="sub">Sign in to your account</p><?php endif;?><?php if($registered&&!$challenge):?><p style="background:#ecfdf5;color:#166534;padding:10px;border-radius:9px;font-size:12px">Reseller account created successfully. You can sign in now.</p><?php endif;?><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?><?php if($challenge):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="2fa"><input name="code" class="field" inputmode="numeric" maxlength="6" placeholder="6-digit code" required autofocus><button class="btn">Verify</button></form><p><a href="/login">Start over</a></p><?php else:?><form method="post"><?=csrf_field()?><input name="email" type="email" class="field" placeholder="Email" required><input name="password" type="password" class="field" placeholder="Password" required><button class="btn">Sign in</button></form><p><a href="/">← Back to SkyNoc</a></p><?php endif;?></div></body></html>