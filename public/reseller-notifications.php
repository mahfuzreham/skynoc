<?php
require __DIR__ . '/../app/bootstrap.php';
$u=require_role(['reseller']);
$s=$db->prepare('SELECT id FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]);
$r=$s->fetch();
if(!$r) exit('Reseller profile not found.');
$rid=(int)$r['id'];
$msg=null;$error=null;
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();
        if(($_POST['action']??'')==='save'){
            $discord=trim((string)($_POST['discord_webhook']??''));
            $token=trim((string)($_POST['telegram_bot_token']??''));
            $chat=trim((string)($_POST['telegram_chat_id']??''));
            if($discord!=='' && !filter_var($discord,FILTER_VALIDATE_URL)) throw new RuntimeException('Discord webhook URL is invalid.');
            if(((int)($_POST['telegram_enabled']??0)) && ($token==='' || $chat==='')) throw new RuntimeException('Telegram bot token and chat ID are required when Telegram is enabled.');
            reseller_notification_save($rid,[
                'email_enabled'=>!empty($_POST['email_enabled']),
                'discord_enabled'=>!empty($_POST['discord_enabled']),
                'discord_webhook'=>$discord,
                'telegram_enabled'=>!empty($_POST['telegram_enabled']),
                'telegram_bot_token'=>$token,
                'telegram_chat_id'=>$chat,
                'low_balance_threshold'=>(float)($_POST['low_balance_threshold']??5),
            ]);
            $msg='Notification preferences saved.';
        }
    }
}catch(Throwable $e){$error=$e->getMessage();}
$settings=reseller_notification_settings($rid);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Notification Preferences - SkyNoc</title><style>*{box-sizing:border-box}body{font-family:Inter,system-ui,sans-serif;background:#f6f8fb;margin:0;color:#111827}.nav{background:#111827;color:#fff;padding:16px 5%;display:flex;justify-content:space-between;gap:15px}.nav a{color:#fff;text-decoration:none}.wrap{max-width:820px;margin:28px auto;padding:0 16px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:0 5px 24px #1118270b}h1{margin:0 0 8px;font-size:26px}h2{font-size:18px;margin-top:0}p{color:#64748b;line-height:1.6}label{display:block;margin:13px 0 6px;font-weight:600;font-size:14px}input{width:100%;padding:11px 12px;border:1px solid #dbe1ea;border-radius:10px;font:inherit}input[type=checkbox]{width:auto;margin-right:8px}button{margin-top:18px;padding:11px 18px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:700;cursor:pointer}.ok{padding:12px;border-radius:10px;background:#ecfdf5;color:#065f46}.err{padding:12px;border-radius:10px;background:#fef2f2;color:#991b1b}.hint{font-size:13px;color:#64748b}.row{display:flex;align-items:center;gap:8px;margin-top:12px}</style></head><body><div class="nav"><strong>SkyNoc Reseller</strong><a href="/reseller">← Dashboard</a></div><main class="wrap"><div class="card"><h1>Notification Preferences</h1><p>Email notifications are enabled by default. You can additionally connect your own Discord webhook and Telegram bot for wallet, order, license and account alerts.</p><?php if($msg):?><div class="ok"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="err"><?=e($error)?></div><?php endif;?></div><form method="post" class="card"><?=csrf_field()?><input type="hidden" name="action" value="save"><h2>Email</h2><div class="row"><input type="checkbox" id="email_enabled" name="email_enabled" value="1" <?=$settings['email_enabled']?'checked':''?>><label for="email_enabled">Send notifications to my account email</label></div><h2 style="margin-top:26px">Discord</h2><div class="row"><input type="checkbox" id="discord_enabled" name="discord_enabled" value="1" <?=$settings['discord_enabled']?'checked':''?>><label for="discord_enabled">Enable Discord notifications</label></div><label>Discord Webhook URL</label><input type="url" name="discord_webhook" value="<?=e((string)($settings['discord_webhook']??''))?>" placeholder="https://discord.com/api/webhooks/..."><p class="hint">Create an Incoming Webhook for the Discord channel where you want SkyNoc alerts. Discord webhooks are designed for posting messages into a channel. urlDiscord webhook documentationhttps://discord.com/developers/docs/resources/webhook</p><h2 style="margin-top:26px">Telegram</h2><div class="row"><input type="checkbox" id="telegram_enabled" name="telegram_enabled" value="1" <?=$settings['telegram_enabled']?'checked':''?>><label for="telegram_enabled">Enable Telegram notifications</label></div><label>Telegram Bot Token</label><input type="password" name="telegram_bot_token" value="<?=e((string)($settings['telegram_bot_token']??''))?>" autocomplete="off"><label>Telegram Chat ID</label><input type="text" name="telegram_chat_id" value="<?=e((string)($settings['telegram_chat_id']??''))?>" placeholder="123456789 or @channelusername"><p class="hint">SkyNoc sends notifications through Telegram Bot API using the configured bot token and chat ID.</p><h2 style="margin-top:26px">Low Balance Alert</h2><label>Alert threshold (USD)</label><input type="number" name="low_balance_threshold" min="0" step="0.01" value="<?=e((string)$settings['low_balance_threshold'])?>"><p class="hint">If a purchase cannot be covered by the wallet, SkyNoc sends the default email alert and also sends Discord/Telegram alerts when enabled.</p><button type="submit">Save Preferences</button></form></main></body></html>
