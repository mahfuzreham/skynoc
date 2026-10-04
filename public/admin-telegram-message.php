<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin']);
$msg = null;
$error = null;
$tg = skynoc_telegram_settings();
$token = skynoc_telegram_bot_token();
$adminIds = skynoc_telegram_admin_chat_ids();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf();
        $message = trim((string)($_POST['message'] ?? ''));
        $target = trim((string)($_POST['chat_id'] ?? ''));
        $toAdmins = !empty($_POST['to_admins']);
        if ($token === '') throw new RuntimeException('Telegram Bot Token is not configured.');
        if ($message === '') throw new RuntimeException('Message cannot be empty.');
        if (mb_strlen($message) > 4000) throw new RuntimeException('Message is too long. Maximum 4000 characters.');
        $targets = [];
        if ($toAdmins) {
            $targets = $adminIds;
        } else {
            if (!preg_match('/^-?\d{5,20}$/', $target)) throw new RuntimeException('Enter a valid Telegram Chat ID.');
            $targets = [$target];
        }
        if (!$targets) throw new RuntimeException('No recipient is configured.');
        $sent = 0; $errors = [];
        foreach ($targets as $chatId) {
            // Escape user-entered text because the shared Telegram helper sends HTML parse mode.
            if (skynoc_telegram_send($token, $chatId, e($message))) $sent++;
            else $errors[] = $chatId;
        }
        if ($sent === count($targets)) {
            $msg = 'Custom Telegram message sent successfully to ' . $sent . ' recipient(s).';
        } else {
            $error = 'Delivered to ' . $sent . '/' . count($targets) . ' recipient(s). Failed Chat IDs: ' . implode(', ', $errors);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('SkyNoc custom Telegram message: ' . $e->getMessage());
    }
}
$adminIdsText = implode("\n", $adminIds);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Custom Telegram Message — SkyNoc</title>
<link rel="stylesheet" href="/admin-page-ui.css">
<style>
:root{--blue:#465fff;--ink:#101828;--muted:#667085;--line:#e4e7ec;--soft:#f8fafc}body{background:var(--soft)}.page{max-width:980px;margin:auto}.hero{background:linear-gradient(135deg,#0f172a,#172554 55%,#2563eb);color:#fff;border-radius:24px;padding:30px;margin-bottom:18px;box-shadow:0 18px 45px rgba(16,24,40,.16)}.badge{display:inline-block;padding:7px 11px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);border-radius:999px;font-size:10px;font-weight:900}.hero h1{font-size:30px;margin:14px 0 6px;letter-spacing:-.04em}.hero p{margin:0;color:#dbe5ff;font-size:13px}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}.nav a{padding:9px 12px;border-radius:10px;color:#fff!important;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);font-size:11px;font-weight:800;text-decoration:none}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px;box-shadow:0 8px 25px rgba(16,24,40,.04)}.field{margin-bottom:18px}.field label{display:block;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#475467;margin-bottom:7px}.field input,.field textarea{width:100%;box-sizing:border-box}.field textarea{min-height:190px;resize:vertical}.hint{margin-top:6px;color:#98a2b3;font-size:10px;line-height:1.55}.check{display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid var(--line);border-radius:13px;background:var(--soft)}.check input{margin-top:2px}.check b{display:block;font-size:12px}.check span{display:block;margin-top:3px;color:var(--muted);font-size:10px;line-height:1.5}.actions{display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap;margin-top:20px}.btn{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 14px;font-weight:800;font-size:11px;text-decoration:none;color:var(--ink)}.primary{background:var(--blue);border-color:var(--blue);color:#fff}.alert{padding:13px 15px;border-radius:12px;margin-bottom:16px;font-size:11px}.ok{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.err{background:#fef3f2;border:1px solid #fecdca;color:#b42318}.preview{margin-top:16px;padding:15px;border:1px dashed #cbd5e1;border-radius:13px;background:#f8fafc}.preview strong{font-size:11px}.preview p{margin:7px 0 0;white-space:pre-wrap;word-break:break-word;font-size:12px;color:#344054}@media(max-width:600px){.hero{padding:22px 17px;border-radius:18px}.card{padding:17px}.actions>*{width:100%;text-align:center}}
</style>
</head>
<body>
<div class="top"><strong>SkyNoc <span style="opacity:.55">/</span> Admin</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><div class="page">
<div class="hero"><span class="badge">✉ TELEGRAM MESSAGE CENTER</span><h1>Custom Telegram Message</h1><p>Send a custom message from the SkyNoc bot to a client or broadcast it to all configured admins.</p><div class="nav"><a href="/admin/settings/telegram">← Telegram Settings</a><a href="/admin">Dashboard</a></div></div>
<?php if($msg): ?><div class="alert ok">✓ <?=e($msg)?></div><?php endif; ?>
<?php if($error): ?><div class="alert err">! <?=e($error)?></div><?php endif; ?>
<div class="card"><?=csrf_field()?>
<form method="post">
<div class="field"><label>Recipient Chat ID</label><input name="chat_id" value="<?=e((string)($_POST['chat_id'] ?? ''))?>" placeholder="Example: 7227131733"><div class="hint">Use the client's Telegram Chat ID. For a group, use its group Chat ID.</div></div>
<div class="check"><input type="checkbox" name="to_admins" value="1" id="toAdmins" <?=!empty($_POST['to_admins'])?'checked':''?>><label for="toAdmins"><b>Send to all configured admins instead</b><span>This ignores the Chat ID field and sends the message through the same unified bot to every admin Chat ID.</span></label></div>
<div class="field" style="margin-top:18px"><label>Custom Message</label><textarea name="message" maxlength="4000" placeholder="Write your custom message here..."><?=e((string)($_POST['message'] ?? ''))?></textarea><div class="hint">Maximum 4000 characters. The message is sent as plain text through the SkyNoc bot.</div></div>
<div class="actions"><a class="btn" href="/admin/settings/telegram">Cancel</a><button class="btn primary" type="submit">✈ Send Message</button></div>
</form>
<div class="preview"><strong>How it works</strong><p>Admin Panel → SkyNoc Bot → Selected Telegram Chat</p></div>
</div></div></div>
</body></html>
