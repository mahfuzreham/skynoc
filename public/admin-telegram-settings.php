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
        if ($token === '') $token = (string)($tg['bot_token'] ?? $tg['license_bot_token'] ?? '');
        $rawIds = trim((string)($_POST['admin_chat_ids'] ?? ''));
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $rawIds) ?: [] as $id) {
            $id = trim($id); if ($id === '') continue;
            if (!preg_match('/^-?\d{5,20}$/', $id)) throw new RuntimeException('Invalid Telegram Chat ID: ' . $id);
            $ids[$id] = $id;
        }
        $adminIds = implode("\n", array_values($ids));
        $supportEnabled = !empty($_POST['support_enabled']) ? 1 : 0;
        $welcome = trim((string)($_POST['support_welcome'] ?? ''));
        if ($token === '') throw new RuntimeException('Telegram Bot Token is required.');
        if ($adminIds === '') throw new RuntimeException('Add at least one admin Chat ID.');
        $sql = "INSERT INTO telegram_settings (id,bot_token,admin_chat_id,license_bot_token,license_admin_chat_id,deposit_bot_token,deposit_admin_chat_id,admin_chat_ids,support_enabled,support_welcome) VALUES(1,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE bot_token=VALUES(bot_token),admin_chat_id=VALUES(admin_chat_id),license_bot_token=VALUES(license_bot_token),license_admin_chat_id=VALUES(license_admin_chat_id),deposit_bot_token=NULL,deposit_admin_chat_id=NULL,admin_chat_ids=VALUES(admin_chat_ids),support_enabled=VALUES(support_enabled),support_welcome=VALUES(support_welcome)";
        $db->prepare($sql)->execute([$token,$adminIds,$token,$adminIds,null,null,$adminIds,$supportEnabled,$welcome ?: null]);
        $tg = skynoc_telegram_settings();
        if ($action === 'test') {
            $test = skynoc_telegram_test_admins();
            if (($test['ok'] ?? false) === true) {
                $bot = trim((string)($test['bot_name'] ?? ''));
                $msg = 'Telegram test delivered to ' . (int)$test['sent'] . ' admin chat(s).' . ($bot !== '' ? ' Bot: @' . $bot : '');
            } else {
                $details = trim((string)($test['error'] ?? 'Unknown Telegram API error.'));
                $msg = 'Telegram test: ' . (int)($test['sent'] ?? 0) . '/' . (int)($test['total'] ?? count($ids)) . ' delivered.';
                if ($details !== '') $error = $details;
            }
        } elseif ($action === 'webhook') {
            $base = rtrim((string)($GLOBALS['config']['base_url'] ?? 'https://skynoc.net'), '/');
            $webhook = $base . '/telegram/control';
            $json = skynoc_telegram_api($token, 'setWebhook', ['url' => $webhook, 'allowed_updates' => json_encode(['message','callback_query'])]);
            if (!($json['ok'] ?? false)) throw new RuntimeException('Telegram webhook setup failed: ' . (string)($json['description'] ?? 'Unknown Telegram API error.'));
            $msg = 'Webhook connected successfully: ' . $webhook;
        } else $msg = 'Telegram settings saved successfully.';
    }
} catch (Throwable $e) { $error = $e->getMessage(); error_log('SkyNoc Telegram settings: ' . $e->getMessage()); }
$tg = skynoc_telegram_settings();
$adminIds = (string)($tg['admin_chat_ids'] ?? $tg['license_admin_chat_id'] ?? $tg['admin_chat_id'] ?? '');
$supportEnabled = (int)($tg['support_enabled'] ?? 1);
$welcome = (string)($tg['support_welcome'] ?? '');
$base = rtrim((string)($GLOBALS['config']['base_url'] ?? 'https://skynoc.net'), '/');
$adminCount = count(skynoc_telegram_admin_chat_ids());
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram & Support — SkyNoc</title><link rel="stylesheet" href="/admin-page-ui.css"><style>
:root{--blue:#465fff;--ink:#101828;--muted:#667085;--line:#e4e7ec;--soft:#f8fafc}body{background:var(--soft)}.page{max-width:1180px;margin:auto}.hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#07142f 0%,#10275f 48%,#2563eb 100%);color:#fff;border-radius:26px;padding:32px 34px 0;margin-bottom:18px;box-shadow:0 20px 55px rgba(16,24,40,.18);isolation:isolate}.hero:before{content:"";position:absolute;width:330px;height:330px;border-radius:50%;right:-100px;top:-190px;background:rgba(96,165,250,.22);filter:blur(4px);z-index:-1}.hero:after{content:"";position:absolute;width:230px;height:230px;border-radius:50%;right:190px;bottom:-170px;background:rgba(129,140,248,.14);z-index:-1}.hero-top{display:flex;justify-content:space-between;gap:25px;align-items:center}.hero-copy{max-width:780px}.badge{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.08em}.hero h1{font-size:34px;margin:14px 0 7px;letter-spacing:-.045em;line-height:1.1}.hero p{max-width:720px;margin:0;color:#dbe7ff;font-size:13px;line-height:1.7}.status{min-width:165px;padding:17px 18px;border-radius:18px;background:linear-gradient(180deg,rgba(255,255,255,.14),rgba(255,255,255,.07));border:1px solid rgba(255,255,255,.18);box-shadow:inset 0 1px rgba(255,255,255,.08);text-align:left}.status .status-icon{font-size:18px;margin-bottom:8px}.status strong{display:block;font-size:13px}.status span{font-size:10px;color:#cbd5e1}.nav{display:flex;gap:8px;flex-wrap:wrap;margin:27px -34px 0;padding:14px 34px;background:rgba(3,10,30,.36);border-top:1px solid rgba(255,255,255,.08)}.nav a{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border-radius:10px;color:#fff!important;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.13);font-size:11px;font-weight:800;text-decoration:none;transition:.18s}.nav a:hover{background:rgba(255,255,255,.15);transform:translateY(-1px)}.nav a.active{background:#fff;color:#10204b!important;border-color:#fff}.layout{display:grid;grid-template-columns:1.25fr .75fr;gap:16px}.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 8px 25px rgba(16,24,40,.04)}.card h2{margin:0 0 5px;font-size:17px}.sub{margin:0 0 18px;color:var(--muted);font-size:11px;line-height:1.6}.field{margin-bottom:16px}.field label{display:block;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.06em;color:#475467;margin-bottom:7px}.field input,.field textarea{width:100%;box-sizing:border-box}.field textarea{min-height:110px;resize:vertical}.hint{margin-top:6px;color:#98a2b3;font-size:10px;line-height:1.55}.check{display:flex;gap:10px;align-items:flex-start;padding:14px;border:1px solid var(--line);border-radius:13px;background:var(--soft)}.check input{margin-top:2px}.check b{display:block;font-size:12px}.check span{display:block;margin-top:3px;color:var(--muted);font-size:10px;line-height:1.5}.actions{display:flex;gap:9px;justify-content:flex-end;flex-wrap:wrap;margin-top:18px}.btn2{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 14px;font-weight:800;font-size:11px;cursor:pointer}.btn2.primary{background:var(--blue);border-color:var(--blue);color:#fff}.alert{padding:13px 15px;border-radius:12px;margin-bottom:16px;font-size:11px}.ok{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.err{background:#fef3f2;border:1px solid #fecdca;color:#b42318}.warn{margin-top:14px;background:#fffaeb;border:1px solid #fedf89;color:#92400e;line-height:1.6}.info{display:grid;gap:10px}.info-row{padding:13px;border:1px solid var(--line);border-radius:13px}.info-row strong{display:block;font-size:11px}.info-row span{display:block;color:var(--muted);font-size:10px;line-height:1.55;margin-top:4px}.code{display:block;margin-top:7px;padding:9px;border-radius:9px;background:#101828;color:#d1fae5;font:10px ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all}.help{margin-top:16px;padding-top:15px;border-top:1px solid var(--line)}.help h3{font-size:12px;margin:0 0 7px}.help ol{margin:0;padding-left:18px;color:var(--muted);font-size:10px;line-height:1.8}@media(max-width:850px){.layout{grid-template-columns:1fr}.hero-top{flex-direction:column;align-items:flex-start}.status{width:100%;box-sizing:border-box}.page{padding:0 4px}}@media(max-width:600px){.hero{padding:22px 17px 0;border-radius:18px}.hero h1{font-size:27px}.hero p{font-size:12px}.card{padding:17px}.nav{margin-left:-17px;margin-right:-17px;padding-left:17px;padding-right:17px}.actions>*{width:100%}}
</style></head><body>
<div class="top"><strong>SkyNoc <span style="opacity:.55">/</span> Admin</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap"><div class="page">
<header class="hero"><div class="hero-top"><div class="hero-copy"><span class="badge">✈ TELEGRAM CONTROL CENTER</span><h1>Telegram &amp; Support</h1><p>One secure bot for SkyNoc notifications, staff communication and real-time client support — all from one control center.</p></div><div class="status"><div class="status-icon">●</div><strong><?=$adminCount?> Admin Chat(s)</strong><span>Unified notification channel</span></div></div><nav class="nav"><a class="active" href="/admin/settings/telegram">✈ Telegram</a><a href="/admin/settings">⚙ Settings</a><a href="/admin/settings/payments">💳 Payments</a><a href="/admin/settings/discord">◉ Discord</a><a href="/admin/settings/smtp">✉ SMTP</a></nav></header>
<?php if($msg): ?><div class="alert ok">✓ <?=e($msg)?></div><?php endif; ?><?php if($error): ?><div class="alert err">! <?=e($error)?></div><?php endif; ?>
<form method="post"><div class="layout"><main class="card"><?=csrf_field()?><h2>Unified Bot Configuration</h2><p class="sub">License, deposit, order, payment and system notifications all use this bot. No separate notification bots are needed.</p>
<div class="field"><label>Telegram Bot Token</label><input type="password" name="bot_token" value="" placeholder="Leave blank to keep current token" autocomplete="new-password"><div class="hint">Create the bot with @BotFather. The saved token is never shown back.</div></div>
<div class="field"><label>Admin Chat IDs — Multiple</label><textarea name="admin_chat_ids" placeholder="123456789&#10;987654321&#10;-1001234567890"><?=e($adminIds)?></textarea><div class="hint">One Chat ID per line. Add every trusted staff/admin Telegram account. Group IDs are supported.</div></div>
<div class="check"><input type="checkbox" name="support_enabled" value="1" id="support" <?=$supportEnabled?'checked':''?>><label for="support"><b>Enable Client Support</b><span>Clients can message this same bot. Their messages are delivered to every configured admin, and admins can reply directly.</span></label></div>
<div class="field" style="margin-top:16px"><label>Client Welcome Message</label><textarea name="support_welcome" placeholder="Welcome to SkyNoc Support. Please send your message."><?=e($welcome)?></textarea></div>
<div class="actions"><button class="btn2" name="action" value="webhook">🔗 Connect Webhook</button><button class="btn2" name="action" value="test">🧪 Send Test</button><button class="btn2 primary" name="action" value="save">Save Telegram Settings</button></div></main>
<aside><section class="card"><h2>Client Support</h2><p class="sub">The same bot handles both internal notifications and customer conversations.</p><div class="info"><div class="info-row"><strong>Webhook URL</strong><span class="code"><?=e($base)?>/telegram/control</span></div><div class="info-row"><strong>Message Flow</strong><span>Client → Bot → all admins. Admin replies to the support notification → Bot → Client.</span></div><div class="info-row"><strong>Admin Notifications</strong><span>One bot token with multiple admin Chat IDs. License and deposit notifications are delivered to all configured admins.</span></div></div><div class="help"><h3>First-time setup</h3><ol><li>Enter BotFather token.</li><li>Add all admin Chat IDs.</li><li>Enable Client Support.</li><li>Save settings.</li><li>Connect Webhook.</li><li>Send Test.</li></ol></div></section><div class="alert warn">Security: only add trusted admin Chat IDs. Each admin should start the bot once so Telegram can deliver private notifications.</div></aside></div></form></div></div></body></html>