<?php
require __DIR__ . '/../app/bootstrap.php';

$u = require_role(['owner','admin']);

$msg = null;
$error = null;

function sms_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_settings') {
            $current = skynoc_sms_settings();
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $provider = trim((string)($_POST['provider_name'] ?? 'Generic JSON SMS')) ?: 'Generic JSON SMS';
            $apiUrl = trim((string)($_POST['api_url'] ?? '')) ?: null;
            $sender = trim((string)($_POST['sender_id'] ?? '')) ?: null;
            $authHeader = trim((string)($_POST['auth_header'] ?? 'Authorization')) ?: 'Authorization';
            $authPrefix = (string)($_POST['auth_prefix'] ?? 'Bearer ');
            $recipientField = trim((string)($_POST['recipient_field'] ?? 'to')) ?: 'to';
            $messageField = trim((string)($_POST['message_field'] ?? 'message')) ?: 'message';
            $senderField = trim((string)($_POST['sender_field'] ?? 'sender')) ?: 'sender';
            $payload = trim((string)($_POST['payload_template'] ?? '')) ?: null;
            if ($payload !== null && json_decode($payload, true) === null && json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Payload template must be valid JSON.');
            $days=[]; foreach (explode(',', (string)($_POST['reminder_days'] ?? '30,7,1')) as $d){$d=(int)trim($d);if($d>0&&$d<=365)$days[]=$d;} $days=array_values(array_unique($days));
            if (!$days) throw new RuntimeException('Enter at least one reminder day.');
            $apiKey = trim((string)($_POST['api_key'] ?? ''));
            $apiSecret = trim((string)($_POST['api_secret'] ?? ''));
            $sql = 'UPDATE sms_settings SET enabled=?,provider_name=?,api_url=?,sender_id=?,auth_header=?,auth_prefix=?,recipient_field=?,message_field=?,sender_field=?,payload_template=?,reminder_days=?,renewal_enabled=?,expired_enabled=?,reminder_enabled=?,renewed_enabled=?';
            $params = [$enabled,$provider,$apiUrl,$sender,$authHeader,$authPrefix,$recipientField,$messageField,$senderField,$payload,implode(',',$days),!empty($_POST['renewal_enabled'])?1:0,!empty($_POST['expired_enabled'])?1:0,!empty($_POST['reminder_enabled'])?1:0,!empty($_POST['renewed_enabled'])?1:0];
            if ($apiKey !== '') { $sql .= ',api_key=?'; $params[]=$apiKey; }
            if ($apiSecret !== '') { $sql .= ',api_secret=?'; $params[]=$apiSecret; }
            $sql .= ' WHERE id=1';
            $db->prepare($sql)->execute($params);
            audit('sms_settings_updated','sms_settings',1);
            $msg='SMS settings saved.';
        }

        if ($action === 'save_contacts') {
            $rows = $_POST['contacts'] ?? [];
            if (!is_array($rows)) $rows=[];
            $all = $db->query("SELECT u.id FROM users u WHERE u.role='reseller'")->fetchAll(PDO::FETCH_COLUMN);
            $db->beginTransaction();
            foreach ($all as $id) {
                $id=(int)$id; $row = is_array($rows[$id] ?? null) ? $rows[$id] : [];
                $phone=trim((string)($row['phone'] ?? '')) ?: null;
                $iso=strtoupper(trim((string)($row['country_iso'] ?? 'BD'))); $iso=preg_match('/^[A-Z]{2}$/',$iso)?$iso:'BD';
                $calling=trim((string)($row['calling_code'] ?? '')) ?: '+880';
                $timezone=trim((string)($row['timezone'] ?? '')) ?: 'Asia/Dhaka';
                $selected=!empty($row['selected'])?1:0;
                $optIn=!empty($row['opt_in'])?1:0;
                $db->prepare('INSERT INTO sms_contacts(user_id,phone,country_iso,country_calling_code,timezone,selected,sms_opt_in) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone),country_iso=VALUES(country_iso),country_calling_code=VALUES(country_calling_code),timezone=VALUES(timezone),selected=VALUES(selected),sms_opt_in=VALUES(sms_opt_in)')->execute([$id,$phone,$iso,$calling,$timezone,$selected,$optIn]);
            }
            $db->commit(); audit('sms_contacts_updated','sms_contacts',null); $msg='SMS recipients updated. Only selected + opted-in users can receive SMS.';
        }

        if ($action === 'send_bulk') {
            $ids = $_POST['user_ids'] ?? [];
            $message = trim((string)($_POST['bulk_message'] ?? ''));
            if (!is_array($ids)) $ids=[];
            if ($message==='') throw new RuntimeException('Bulk SMS message is required.');
            if (count($ids)>500) throw new RuntimeException('Maximum 500 recipients per manual bulk send.');
            $result=skynoc_sms_send_selected(array_map('intval',$ids),$message,'bulk_manual');
            audit('sms_bulk_sent','sms_logs',null,json_encode($result,JSON_UNESCAPED_UNICODE));
            $msg='Bulk SMS completed. Sent: '.$result['sent'].'; failed: '.$result['failed'].'; skipped: '.$result['skipped'].'; duplicate: '.$result['duplicate'].'.';
        }

        if ($action === 'save_country') {
            $iso=strtoupper(trim((string)($_POST['country_iso'] ?? ''))); $name=trim((string)($_POST['country_name'] ?? '')); $tz=trim((string)($_POST['timezone'] ?? 'UTC')); $enabled=!empty($_POST['enabled'])?1:0; $start=trim((string)($_POST['quiet_start'] ?? '21:00')); $end=trim((string)($_POST['quiet_end'] ?? '08:00'));
            if (!preg_match('/^[A-Z]{2}$/',$iso) || $name==='' || !in_array($tz, timezone_identifiers_list(), true)) throw new RuntimeException('Invalid country/timezone settings.');
            $db->prepare('INSERT INTO sms_country_settings(country_iso,country_name,timezone,enabled,quiet_start,quiet_end) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE country_name=VALUES(country_name),timezone=VALUES(timezone),enabled=VALUES(enabled),quiet_start=VALUES(quiet_start),quiet_end=VALUES(quiet_end)')->execute([$iso,$name,$tz,$enabled,$start,$end]);
            audit('sms_country_updated','sms_country_settings',null,$iso); $msg='Country SMS policy saved.';
        }
    }

    $settings = skynoc_sms_settings();
    $users = $db->query("SELECT u.id,u.name,u.email,r.id reseller_id,r.name reseller_name,c.phone,c.country_iso,c.country_calling_code,c.timezone,c.selected,c.sms_opt_in FROM users u JOIN resellers r ON r.user_id=u.id LEFT JOIN sms_contacts c ON c.user_id=u.id WHERE u.role='reseller' ORDER BY u.id DESC")->fetchAll();
    $countries = $db->query('SELECT * FROM sms_country_settings ORDER BY country_name')->fetchAll();
    $logs = $db->query('SELECT l.*,u.name FROM sms_logs l JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 50')->fetchAll();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $error=$e->getMessage();
    $settings=$settings ?? skynoc_sms_settings();
    $users=$users ?? $db->query("SELECT u.id,u.name,u.email,r.id reseller_id,r.name reseller_name,c.phone,c.country_iso,c.country_calling_code,c.timezone,c.selected,c.sms_opt_in FROM users u JOIN resellers r ON r.user_id=u.id LEFT JOIN sms_contacts c ON c.user_id=u.id WHERE u.role='reseller' ORDER BY u.id DESC")->fetchAll();
    $countries=$countries ?? $db->query('SELECT * FROM sms_country_settings ORDER BY country_name')->fetchAll();
    $logs=$logs ?? [];
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SMS Automation - SkyNoc Admin</title>
<style>
body{margin:0;background:#f6f7fb;color:#172033;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{max-width:1400px;margin:0 auto;padding:26px}.top{display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:18px}.top h1{margin:0;font-size:25px}.muted{color:#667085;font-size:13px}.card{background:#fff;border:1px solid #e4e7ef;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 3px 12px rgba(0,0,0,.04)}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px}.field{display:flex;flex-direction:column;gap:6px}.full{grid-column:1/-1}label{font-size:12px;font-weight:700;color:#475467}input,select,textarea{box-sizing:border-box;width:100%;padding:10px 11px;border:1px solid #d6dae3;border-radius:9px;background:#fff;font:inherit}textarea{min-height:90px;resize:vertical}.btn{display:inline-block;border:1px solid #d7dce7;background:#fff;color:#172033;border-radius:9px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}.primary{background:#172033;color:#fff;border-color:#172033}.alert{padding:12px 14px;border-radius:10px;margin-bottom:15px}.ok{background:#ecfdf3;color:#067647}.err{background:#fef3f2;color:#b42318}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:1050px}th,td{text-align:left;padding:10px;border-bottom:1px solid #edf0f5;font-size:13px;vertical-align:middle}th{font-size:11px;text-transform:uppercase;color:#667085;background:#fafbfc}.pill{display:inline-block;border-radius:99px;padding:4px 8px;background:#eef2ff;font-size:11px;font-weight:700}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.checkbox{width:auto}.switch{display:flex;gap:8px;align-items:center}.section-title{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:13px}.section-title h2{margin:0;font-size:18px}.hint{font-size:12px;color:#667085;line-height:1.5}.danger{color:#b42318}.success{color:#067647}
@media(max-width:850px){.wrap{padding:14px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.top{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="wrap">
<div class="top"><div><h1>SMS Automation</h1><div class="muted">Country-aware renewal reminders, expiry notices and manual bulk SMS. Only explicitly selected users receive messages.</div></div><a class="btn" href="/admin/dashboard">← Admin Dashboard</a></div>
<?php if($msg): ?><div class="alert ok"><?=sms_h($msg)?></div><?php endif; ?><?php if($error): ?><div class="alert err"><?=sms_h($error)?></div><?php endif; ?>

<div class="card"><div class="section-title"><h2>SMS Provider & Automation</h2><span class="pill"><?=!empty($settings['enabled'])?'Enabled':'Disabled'?></span></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_settings"><div class="grid">
<div class="field"><label><input class="checkbox" type="checkbox" name="enabled" value="1" <?=!empty($settings['enabled'])?'checked':''?>> Enable SMS automation</label></div>
<div class="field"><label>Provider Name</label><input name="provider_name" value="<?=sms_h($settings['provider_name']??'Generic JSON SMS')?>"></div>
<div class="field"><label>API URL</label><input name="api_url" value="<?=sms_h($settings['api_url']??'')?>" placeholder="https://sms-provider.example/api/send"></div>
<div class="field"><label>Sender ID</label><input name="sender_id" value="<?=sms_h($settings['sender_id']??'')?>"></div>
<div class="field"><label>API Key (leave blank to keep)</label><input type="password" name="api_key" autocomplete="new-password"></div>
<div class="field"><label>API Secret (leave blank to keep)</label><input type="password" name="api_secret" autocomplete="new-password"></div>
<div class="field"><label>Auth Header</label><input name="auth_header" value="<?=sms_h($settings['auth_header']??'Authorization')?>"></div>
<div class="field"><label>Auth Prefix</label><input name="auth_prefix" value="<?=sms_h($settings['auth_prefix']??'Bearer ')?>"></div>
<div class="field"><label>Recipient JSON Field</label><input name="recipient_field" value="<?=sms_h($settings['recipient_field']??'to')?>"></div>
<div class="field"><label>Message JSON Field</label><input name="message_field" value="<?=sms_h($settings['message_field']??'message')?>"></div>
<div class="field"><label>Sender JSON Field</label><input name="sender_field" value="<?=sms_h($settings['sender_field']??'sender')?>"></div>
<div class="field"><label>Reminder Days</label><input name="reminder_days" value="<?=sms_h($settings['reminder_days']??'30,7,1')?>" placeholder="30,7,1"></div>
<div class="field"><label><input class="checkbox" type="checkbox" name="renewal_enabled" value="1" <?=!empty($settings['renewal_enabled'])?'checked':''?>> Renewal due/renewal SMS</label></div>
<div class="field"><label><input class="checkbox" type="checkbox" name="reminder_enabled" value="1" <?=!empty($settings['reminder_enabled'])?'checked':''?>> Renewal reminder SMS</label></div>
<div class="field"><label><input class="checkbox" type="checkbox" name="expired_enabled" value="1" <?=!empty($settings['expired_enabled'])?'checked':''?>> License expired SMS</label></div>
<div class="field"><label><input class="checkbox" type="checkbox" name="renewed_enabled" value="1" <?=!empty($settings['renewed_enabled'])?'checked':''?>> Successful renewal SMS</label></div>
<div class="field full"><label>Optional JSON payload template</label><textarea name="payload_template" placeholder='{"to":"{to}","message":"{message}","sender":"{sender}"}'><?=sms_h($settings['payload_template']??'')?></textarea><div class="hint">If set, this replaces the basic payload fields. Supported placeholders: {to}, {message}, {sender}, {api_key}, {api_secret}.</div></div>
<div class="field full"><button class="btn primary" type="submit">Save SMS Settings</button></div></div></form></div>

<div class="card"><div class="section-title"><h2>Selected SMS Users</h2><span class="pill">Only checked users receive automatic SMS</span></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_contacts"><div class="table-wrap"><table><thead><tr><th>Select</th><th>User</th><th>Phone</th><th>Country</th><th>Calling Code</th><th>Timezone</th><th>Opt-in</th></tr></thead><tbody>
<?php foreach($users as $row): $id=(int)$row['id']; ?><tr><td><input class="checkbox" type="checkbox" name="contacts[<?=$id?>][selected]" value="1" <?=!empty($row['selected'])?'checked':''?>></td><td><strong><?=sms_h($row['name'])?></strong><div class="muted"><?=sms_h($row['email'])?></div></td><td><input name="contacts[<?=$id?>][phone]" value="<?=sms_h($row['phone']??'')?>" placeholder="+8801XXXXXXXXX"></td><td><input name="contacts[<?=$id?>][country_iso]" value="<?=sms_h($row['country_iso']??'BD')?>" maxlength="2" style="width:70px"></td><td><input name="contacts[<?=$id?>][calling_code]" value="<?=sms_h($row['country_calling_code']??'+880')?>" style="width:90px"></td><td><input name="contacts[<?=$id?>][timezone]" value="<?=sms_h($row['timezone']??'Asia/Dhaka')?>"></td><td><label><input class="checkbox" type="checkbox" name="contacts[<?=$id?>][opt_in]" value="1" <?=!empty($row['sms_opt_in'])?'checked':''?>> Allow</label></td></tr><?php endforeach; ?>
<?php if(!$users): ?><tr><td colspan="7" class="muted">No reseller users found.</td></tr><?php endif; ?></tbody></table></div><div style="margin-top:13px"><button class="btn primary" type="submit">Save Recipient Selection</button></div></form></div>

<div class="card"><div class="section-title"><h2>Manual Bulk SMS</h2><span class="hint">Select recipients above, then send only to those users.</span></div>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="send_bulk"><div class="grid"><div class="field full"><label>Message</label><textarea name="bulk_message" maxlength="1000" placeholder="Your SkyNoc renewal notice..."></textarea></div><div class="field full"><div class="hint">The server will re-check that every recipient is currently selected and opted in. This does not bypass country quiet hours.</div></div><div class="field full"><button class="btn primary" type="submit" onclick="return confirm('Send this SMS to the selected recipients?');">Send Bulk SMS</button></div></div></form></div>

<div class="card"><div class="section-title"><h2>Country Delivery Rules</h2><span class="hint">Quiet hours are evaluated in the recipient's country timezone.</span></div><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_country"><div class="grid"><div class="field"><label>Country ISO</label><input name="country_iso" maxlength="2" placeholder="BD" required></div><div class="field"><label>Country Name</label><input name="country_name" placeholder="Bangladesh" required></div><div class="field"><label>Timezone</label><input name="timezone" placeholder="Asia/Dhaka" required></div><div class="field"><label>Quiet Start</label><input type="time" name="quiet_start" value="21:00"></div><div class="field"><label>Quiet End</label><input type="time" name="quiet_end" value="08:00"></div><div class="field"><label><input class="checkbox" type="checkbox" name="enabled" value="1" checked> Country SMS enabled</label></div><div class="field full"><button class="btn" type="submit">Save Country Rule</button></div></div></form><div class="table-wrap" style="margin-top:15px"><table><thead><tr><th>Country</th><th>Timezone</th><th>Enabled</th><th>Quiet Hours</th></tr></thead><tbody><?php foreach($countries as $c): ?><tr><td><?=sms_h($c['country_name'])?> (<?=sms_h($c['country_iso'])?>)</td><td><?=sms_h($c['timezone'])?></td><td><?=!empty($c['enabled'])?'Yes':'No'?></td><td><?=sms_h($c['quiet_start'])?> – <?=sms_h($c['quiet_end'])?></td></tr><?php endforeach; ?></tbody></table></div></div>

<div class="card"><div class="section-title"><h2>SMS Delivery Log</h2><span class="pill"><?=count($logs)?> recent</span></div><div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Type</th><th>Phone</th><th>Status</th><th>Response</th></tr></thead><tbody><?php foreach($logs as $log): ?><tr><td><?=sms_h($log['created_at'])?></td><td><?=sms_h($log['name'])?></td><td><?=sms_h($log['type'])?></td><td><?=sms_h($log['phone'])?></td><td><?=sms_h($log['status'])?></td><td class="muted"><?=sms_h(mb_substr((string)$log['provider_response'],0,180))?></td></tr><?php endforeach; ?><?php if(!$logs): ?><tr><td colspan="6" class="muted">No SMS logs yet.</td></tr><?php endif; ?></tbody></table></div></div>
</div></body></html>
