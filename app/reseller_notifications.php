<?php
declare(strict_types=1);

function reseller_notification_settings(int $resellerId): array
{
    global $db;
    $s = $db->prepare('SELECT * FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);
    return $s->fetch() ?: [
        'reseller_id'=>$resellerId,'email_enabled'=>1,'custom_email'=>null,'discord_enabled'=>0,'discord_webhook'=>null,
        'telegram_enabled'=>0,'telegram_bot_token'=>null,'telegram_chat_id'=>null,'low_balance_threshold'=>5.00,
    ];
}

function reseller_notification_save(int $resellerId, array $data): void
{
    global $db;
    $existing = reseller_notification_settings($resellerId);
    $token = trim((string)($data['telegram_bot_token'] ?? ''));
    if ($token === '') $token = (string)($existing['telegram_bot_token'] ?? '');
    $chat = trim((string)($data['telegram_chat_id'] ?? '')) ?: null;
    $webhook = trim((string)($data['discord_webhook'] ?? '')) ?: null;
    $customEmail=trim((string)($data['custom_email']??'')) ?: null;
    if($customEmail!==null && !filter_var($customEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Custom notification email is invalid.');

    $s = $db->prepare('INSERT INTO reseller_notification_settings
        (reseller_id,email_enabled,custom_email,discord_enabled,discord_webhook,telegram_enabled,telegram_bot_token,telegram_chat_id,low_balance_threshold)
        VALUES(?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        email_enabled=VALUES(email_enabled),custom_email=VALUES(custom_email),discord_enabled=VALUES(discord_enabled),discord_webhook=VALUES(discord_webhook),
        telegram_enabled=VALUES(telegram_enabled),telegram_bot_token=VALUES(telegram_bot_token),telegram_chat_id=VALUES(telegram_chat_id),
        low_balance_threshold=VALUES(low_balance_threshold)');
    $s->execute([
        $resellerId,!empty($data['email_enabled'])?1:0,$customEmail,!empty($data['discord_enabled'])?1:0,$webhook,
        !empty($data['telegram_enabled'])?1:0,$token,$chat,
        max(0.00,min(1000000.00,(float)($data['low_balance_threshold']??5.00))),
    ]);
}

function reseller_notify_email(int $resellerId,string $subject,string $body): bool
{
    global $db;
    $s=$db->prepare('SELECT u.email,n.email_enabled,n.custom_email FROM resellers r JOIN users u ON u.id=r.user_id LEFT JOIN reseller_notification_settings n ON n.reseller_id=r.id WHERE r.id=? LIMIT 1');
    $s->execute([$resellerId]); $row=$s->fetch();
    if(!$row||empty($row['email'])||(isset($row['email_enabled'])&&!((int)$row['email_enabled'])))return false;
    $to=trim((string)($row['custom_email']??''));
    if($to===''||!filter_var($to,FILTER_VALIDATE_EMAIL)) $to=(string)$row['email'];
    if(function_exists('skynoc_send_email')) return skynoc_send_email($to,$subject,$body);
    $config=$GLOBALS['config']??[]; $from=(string)($config['mail']['from']??'no-reply@skynoc.net');
    $headers="From: SkyNoc <".$from.">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to,$subject,$body,$headers);
}

function reseller_notify_discord(int $resellerId,string $message): bool
{
    global $db;
    $s=$db->prepare('SELECT discord_enabled,discord_webhook FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]); $row=$s->fetch();
    $url=trim((string)($row['discord_webhook']??''));
    $host=$url!==''?(string)(parse_url($url,PHP_URL_HOST)??''):'';
    if(!$row||!(int)$row['discord_enabled']||$url===''||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower($host),['discord.com','discordapp.com'],true))return false;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['content'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $result=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    return $result!==false&&$code>=200&&$code<300;
}

function reseller_notify_telegram(int $resellerId,string $message): bool
{
    global $db;
    $s=$db->prepare('SELECT telegram_enabled,telegram_bot_token,telegram_chat_id FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);$row=$s->fetch();
    if(!$row||!(int)$row['telegram_enabled'])return false;
    $token=trim((string)($row['telegram_bot_token']??''));$chat=trim((string)($row['telegram_chat_id']??''));
    if($token===''||$chat===''||!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{20,}$/',$token))return false;
    $ch=curl_init('https://api.telegram.org/bot'.rawurlencode($token).'/sendMessage');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['chat_id'=>$chat,'text'=>$message,'parse_mode'=>'HTML','disable_web_page_preview'=>true],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $result=curl_exec($ch);curl_close($ch);$json=is_string($result)?json_decode($result,true):null;
    return is_array($json)&&($json['ok']??false)===true;
}

function reseller_notify_all(int $resellerId,string $subject,string $message): void
{
    try{reseller_notify_email($resellerId,$subject,$message);}catch(Throwable $e){error_log('SkyNoc reseller email notification: '.$e->getMessage());}
    try{reseller_notify_discord($resellerId,"**".$subject."**\n".$message);}catch(Throwable $e){error_log('SkyNoc reseller Discord notification: '.$e->getMessage());}
    try{reseller_notify_telegram($resellerId,'<b>'.e($subject).'</b>\n'.nl2br(e($message)));}catch(Throwable $e){error_log('SkyNoc reseller Telegram notification: '.$e->getMessage());}
}

function reseller_notify_low_balance(int $resellerId,float $balance,float $required,?int $orderId=null): void
{
    global $db;
    $s=$db->prepare('SELECT low_balance_threshold FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);$threshold=(float)($s->fetchColumn()??5.00);
    if($balance>$threshold&&$balance>=$required)return;
    $subject='SkyNoc wallet balance is too low';
    $message="Your SkyNoc reseller wallet does not have enough funds for the requested purchase.\n\n".
        'Current balance: $'.number_format($balance,2)."\n".
        'Required: $'.number_format($required,2)."\n".
        'Shortfall: $'.number_format(max(0,$required-$balance),2)."\n".
        ($orderId?'Order: #'.$orderId."\n":'')."Please add funds to continue automatically.";
    reseller_notify_all($resellerId,$subject,$message);
}
