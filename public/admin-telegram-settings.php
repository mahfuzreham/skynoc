<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin']);
$msg = null;
$error = null;
$tg = skynoc_telegram_settings();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? 'save');
        $token = trim((string)($_POST['bot_token'] ?? ''));
        if ($token === '') {
            $token = (string)($tg['bot_token'] ?? $tg['license_bot_token'] ?? '');
        }

        $rawIds = trim((string)($_POST['admin_chat_ids'] ?? ''));
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $rawIds) ?: [] as $id) {
            $id = trim($id);
            if ($id === '') continue;
            if (!preg_match('/^-?\d{5,20}$/', $id)) {
                throw new RuntimeException('Invalid Telegram Chat ID: ' . $id);
            }
            $ids[$id] = $id;
        }
        $adminIds = implode("\n", array_values($ids));
        $supportEnabled = !empty($_POST['support_enabled']) ? 1 : 0;
        $welcome = trim((string)($_POST['support_welcome'] ?? ''));

        if ($token === '') throw new RuntimeException('Telegram Bot Token is required.');
        if ($adminIds === '') throw new RuntimeException('Add at least one admin Chat ID.');

        $sql = "INSERT INTO telegram_settings
            (id,bot_token,admin_chat_id,license_bot_token,license_admin_chat_id,deposit_bot_token,deposit_admin_chat_id,admin_chat_ids,support_enabled,support_welcome)
            VALUES(1,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
            bot_token=VALUES(bot_token),admin_chat_id=VALUES(admin_chat_id),
            license_bot_token=VALUES(license_bot_token),license_admin_chat_id=VALUES(license_admin_chat_id),
            deposit_bot_token=NULL,deposit_admin_chat_id=NULL,admin_chat_ids=VALUES(admin_chat_ids),
            support_enabled=VALUES(support_enabled),support_welcome=VALUES(support_welcome)";
        $db->prepare($sql)->execute([$token,$adminIds,$token,$adminIds,null,null,$adminIds,$supportEnabled,$welcome ?: null]);
        $tg = skynoc_telegram_settings();

        if ($action === 'test') {
            $sent = skynoc_telegram_send_admins("🟢 <b>SkyNoc Telegram Test</b>\n\nUnified bot is working.\n🤖 One bot · 👥 Multiple admins · 💬 Client support");
            $msg = $sent > 0 ? 'Test notification sent to ' . $sent . ' admin chat(s).' : 'Telegram test could not be delivered.';
        } elseif ($action === 'webhook') {
            $base = rtrim((string)($GLOBALS['config']['base_url'] ?? 'https://skynoc.net'), '/');
            $webhook = $base . '/telegram/control';
            $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/setWebhook');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['url' => $webhook],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            $result = curl_exec($ch);
            curl_close($ch);
            $json = is_string($result) ? json_decode($result, true) : null;
            if (!is_array($json) || !($json['ok'] ?? false)) throw new RuntimeException('Telegram webhook setup failed.');
            $msg = 'Webhook connected successfully.';
        } else {
            $msg = 'Telegram settings saved successfully.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    error_log('SkyNoc Telegram settings: ' . $e->getMessage());
}

$tg = skynoc_telegram_settings();
$adminIds = (string)($tg['admin_chat_ids'] ?? $tg['license_admin_chat_id'] ?? $tg['admin_chat_id'] ?? '');
$supportEnabled = (int)($tg['support_enabled'] ?? 1);
$welcome = (string)($tg['support_welcome'] ?? '');
$base = rtrim((string)($GLOBALS['config']['base_url'] ?? 'https://skynoc.net'), '/');
$adminCount = count(skynoc_telegram_admin_chat_ids());
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Telegram & Support — SkyNoc</title>
<link rel="stylesheet" href="/admin-page-ui.css">
<style>
:root{--blue:#465fff;--ink:#101828;--muted:#667085;--line:#e4e7ec;--soft:#f8fafc}
body{background:var(--soft)}.page{max-width:1180px;margin:auto}.hero{background:linear-gradient(135deg,#0f172a,#172554 55%,#2563eb);color:#fff;border-radius:24px;padding:30px;margin-bottom:18px;box-shadow:0 18px 45px rgba(16,24,40,.16)}.hero-top{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.badge{display:inline-block;padding:7px 11px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);border-radius:999px;font-size:10px;font-weight:900}.hero h1{font-size:31px;margin:14px 0 6px;letter-spacing:-.04em}.hero p{max-width:760px;margin:0;color:#dbe5ff;font-size:13px;line-height:1.7}.status{min-width:150px;padding:15px;border-radius:16px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);text-align:center}.status strong{display:block;font-size:12px}.status span{font-size:10px;color:#cbd5e1}.nav{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}.nav a{padding:9px 12px;border-radius:10px;color:#fff!important;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);font-size:11px;font-weight:800;text-decoration:none}.layout{display:grid;grid-template-columns:1.25fr .75fr;gap:16px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 8px 25px rgba(16,24,40,.04)}.card h2{margin:0 0 5px;font-size:17px}.sub{margin:0 0 18px;color:var(--muted);font-size:11px;line-height:1.6}.field{margin-bottom:16px}.field label{display:block;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#475467;margin-bottom:7px}.field input,.field textarea{width:100%;box-sizing:border-box}.field textarea{min-height:110px;resize:vertical}.hint{margin-top:6px;color:#98a2b3;font-size:10px;line-height:1.55}.check{display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid var(--line);border-radius:13px;background:var(--soft)}.check input{margin-top:2px}.check b{display:block;font-size:12px}.check span{display:block;margin-top:3px;color:var(--muted);font-size:10px;line-height:1.5}.actions{display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap;margin-top:18px}.btn2{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 14px;font-weight:800;font-size:11px;cursor:pointer}.btn2.primary{background:var(--blue);border-color:var(--blue);color:#fff}.alert{padding:13px 15px;border-radius:12px;margin-bottom:16px;font-size:11px}.ok{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.err{background:#fef3f2;border:1px solid #fecdca;color:#b42318}.warn{margin-top:14px;background:#fffaeb;border:1px solid #fedf89;color:#92400e;line-height:1.6}.info{display:grid;gap:10px}.info-row{padding:13px;border:1px solid var(--line);border-radius:13px}.info-row strong{display:block;font-size:11px}.info-row span{display:block;color:var(--muted);font-size:10px;line-height:1.55;margin-top:4px}.code{display:block;margin-top:7px;padding:9px;border-radius:9px;background:#101828;color:#d1fae5;font:10px ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all}.help{margin-top:16px;padding-top:15px;border-top:1px solid var(--line)}.help h3{font-size:12px;margin:0 0 7px}.help ol{margin:0;padding-left:18px;color:var(--muted);font-size:10px;line-height:1.8}
@media(max-width:850px){.layout{grid-template-columns:1fr}.hero-top{flex-direction:column}.status{width:100%;box-sizing:border-box}.page{padding:0 4px}}@media(max-width:600px){.hero{padding:22px 17px;border-radius:18px}.hero h1{font-size:26px}.card{padding:17px}.actions>*{width:100%}}
</style>
</head>
<body>
<div class="top"><strong>SkyNoc <span style="opacity:.55">/</span> Admin</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><div class="page">
<div class="hero"><div class="hero-top"><div><span class="badge">✈ TELEGRAM CONTROL CENTER</span><h1>Telegram & Support</h1><p>One secure bot for all SkyNoc admin notifications, multiple staff/admin accounts and client support conversations.</p></div><div class="status"><strong><?=$adminCount?> Admin Chat(s)</strong><span>Unified notification channel</span></div></div><div class="nav"><a href="/admin/settings">⚙ Settings</a><a href="/admin/settings/payments">💳 Payments</a><a href="/admin/settings/discord">◉ Discord</a><a href="/admin/settings/smtp">✉ SMTP</a></div></div>
<?php if($msg): ?><div class="alert ok">✓ <?=e($msg)?></div><?php endif; ?>
<?php if($error): ?><div class="alert err">! <?=e($error)?></div><?php endif; ?>
<form method="post">
<div class="layout"><main class="card"><?=csrf_field()?>
<h2>Unified Bot Configuration</h2><p class="sub">License, deposit, order, payment and system notifications all use this bot. No separate notification bots are needed.</p>
<div class="field"><label>Telegram Bot Token</label><input type="password" name="bot_token" value="" placeholder="Leave blank to keep current token" autocomplete="new-password"><div class="hint">Create the bot with @BotFather. The saved token is never shown back.</div></div>
<div class="field"><label>Admin Chat IDs — Multiple</label><textarea name="admin_chat_ids" placeholder="123456789&#10;987654321&#10;-1001234567890"><?=e($adminIds)?></textarea><div class="hint">One Chat ID per line. Add every trusted staff/admin Telegram account. Group IDs are supported.</div></div>
<div class="check"><input type="checkbox" name="support_enabled" value="1" id="support" <?=$supportEnabled?'checked':''?>><label for="support"><b>Enable Client Support</b><span>Clients can message this same bot. Their messages are delivered to every configured admin, and admins can reply directly.</span></label></div>
<div class="field" style="margin-top:16px"><label>Client Welcome Message</label><textarea name="support_welcome" placeholder="Welcome to SkyNoc Support. Please send your message."><?=e($welcome)?></textarea></div>
<div class="actions"><button class="btn2" name="action" value="webhook">🔗 Connect Webhook</button><button class="btn2" name="action" value="test">🧪 Send Test</button><button class="btn2 primary" name="action" value="save">Save Telegram Settings</button></div>
</main>
<aside><section class="card"><h2>Client Support</h2><p class="sub">The same bot handles both internal notifications and customer conversations.</p><div class="info"><div class="info-row"><strong>Webhook URL</strong><span class="code"><?=e($base)?>/telegram/control</span></div><div class="info-row"><strong>Message Flow</strong><span>Client → Bot → all admins. Admin replies to the support notification → Bot → Client.</span></div><div class="info-row"><strong>Admin Notifications</strong><span>One bot token with multiple admin Chat IDs. License and deposit notifications are delivered to all configured admins.</span></div></div><div class="help"><h3>First-time setup</h3><ol><li>Enter BotFather token.</li><li>Add all admin Chat IDs.</li><li>Enable Client Support.</li><li>Save settings.</li><li>Connect Webhook.</li><li>Send Test.</li></ol></div></section><div class="alert warn">Security: only add trusted admin Chat IDs. Each admin should start the bot once so Telegram can deliver private notifications.</div></aside></div>
</form></div></div></body></html>
