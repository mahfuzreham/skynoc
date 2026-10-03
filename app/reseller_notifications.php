<?php
declare(strict_types=1);

/**
 * Reseller notification channels.
 * Email is always enabled by default. Discord/Telegram are opt-in per reseller.
 */
function reseller_notification_settings(int $resellerId): array
{
    global $db;
    $s = $db->prepare('SELECT * FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);
    return $s->fetch() ?: [
        'reseller_id' => $resellerId,
        'email_enabled' => 1,
        'discord_enabled' => 0,
        'discord_webhook' => null,
        'telegram_enabled' => 0,
        'telegram_bot_token' => null,
        'telegram_chat_id' => null,
        'low_balance_threshold' => 5.00,
    ];
}

function reseller_notification_save(int $resellerId, array $data): void
{
    global $db;
    $s = $db->prepare('INSERT INTO reseller_notification_settings
        (reseller_id,email_enabled,discord_enabled,discord_webhook,telegram_enabled,telegram_bot_token,telegram_chat_id,low_balance_threshold)
        VALUES(?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        email_enabled=VALUES(email_enabled),discord_enabled=VALUES(discord_enabled),discord_webhook=VALUES(discord_webhook),
        telegram_enabled=VALUES(telegram_enabled),telegram_bot_token=VALUES(telegram_bot_token),telegram_chat_id=VALUES(telegram_chat_id),
        low_balance_threshold=VALUES(low_balance_threshold)');
    $s->execute([
        $resellerId,
        !empty($data['email_enabled']) ? 1 : 0,
        !empty($data['discord_enabled']) ? 1 : 0,
        trim((string)($data['discord_webhook'] ?? '')) ?: null,
        !empty($data['telegram_enabled']) ? 1 : 0,
        trim((string)($data['telegram_bot_token'] ?? '')) ?: null,
        trim((string)($data['telegram_chat_id'] ?? '')) ?: null,
        max(0.00, min(1000000.00, (float)($data['low_balance_threshold'] ?? 5.00))),
    ]);
}

function reseller_notify_email(int $resellerId, string $subject, string $body): bool
{
    global $db;
    $s = $db->prepare('SELECT u.email,u.name,n.email_enabled FROM resellers r JOIN users u ON u.id=r.user_id LEFT JOIN reseller_notification_settings n ON n.reseller_id=r.id WHERE r.id=? LIMIT 1');
    $s->execute([$resellerId]);
    $row = $s->fetch();
    if (!$row || empty($row['email']) || (isset($row['email_enabled']) && !(int)$row['email_enabled'])) return false;
    $config = $GLOBALS['config'] ?? [];
    $from = (string)($config['mail']['from'] ?? 'no-reply@skynoc.net');
    $headers = "From: SkyNoc <" . $from . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail((string)$row['email'], $subject, $body, $headers);
}

function reseller_notify_discord(int $resellerId, string $message): bool
{
    global $db;
    $s = $db->prepare('SELECT discord_enabled,discord_webhook FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);
    $row = $s->fetch();
    $url = trim((string)($row['discord_webhook'] ?? ''));
    if (!$row || !(int)$row['discord_enabled'] || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['content'=>$message], JSON_UNESCAPED_SLASHES), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $result !== false && $code >= 200 && $code < 300;
}

function reseller_notify_telegram(int $resellerId, string $message): bool
{
    global $db;
    $s = $db->prepare('SELECT telegram_enabled,telegram_bot_token,telegram_chat_id FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);
    $row = $s->fetch();
    $token = trim((string)($row['telegram_bot_token'] ?? ''));
    $chat = trim((string)($row['telegram_chat_id'] ?? ''));
    if (!$row || !(int)$row['telegram_enabled'] || $token === '' || $chat === '') return false;
    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>['chat_id'=>$chat,'text'=>$message,'parse_mode'=>'HTML','disable_web_page_preview'=>true], CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10]);
    $result = curl_exec($ch);
    curl_close($ch);
    $json = is_string($result) ? json_decode($result, true) : null;
    return is_array($json) && ($json['ok'] ?? false) === true;
}

function reseller_notify_all(int $resellerId, string $subject, string $message): void
{
    reseller_notify_email($resellerId, $subject, $message);
    reseller_notify_discord($resellerId, "**" . $subject . "**\n" . $message);
    reseller_notify_telegram($resellerId, '<b>' . e($subject) . '</b>\n' . nl2br(e($message)));
}

function reseller_notify_low_balance(int $resellerId, float $balance, float $required, ?int $orderId=null): void
{
    global $db;
    $s = $db->prepare('SELECT low_balance_threshold FROM reseller_notification_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$resellerId]);
    $threshold = (float)($s->fetchColumn() ?? 5.00);
    if ($balance > $threshold && $balance >= $required) return;
    $subject = 'SkyNoc wallet balance is too low';
    $message = "Your SkyNoc reseller wallet does not have enough funds for the requested purchase.\n\n" .
        'Current balance: $' . number_format($balance, 2) . "\n" .
        'Required: $' . number_format($required, 2) . "\n" .
        'Shortfall: $' . number_format(max(0, $required - $balance), 2) . "\n" .
        ($orderId ? 'Order: #' . $orderId . "\n" : '') .
        "Please add funds to continue automatically.";
    reseller_notify_all($resellerId, $subject, $message);
}
