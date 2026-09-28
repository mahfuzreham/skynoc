<?php
declare(strict_types=1);

/**
 * SkyNoc Telegram integrations.
 * License control and USDT deposit notifications use separate bots.
 */

function skynoc_telegram_settings(): array
{
    global $db;
    $s = $db->query('SELECT * FROM telegram_settings WHERE id=1 LIMIT 1');
    return $s->fetch() ?: [];
}

function skynoc_telegram_send(string $botToken, string $chatId, string $text, ?array $buttons = null): bool
{
    $botToken = trim($botToken);
    $chatId = trim($chatId);
    if ($botToken === '' || $chatId === '') return false;

    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($buttons) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($botToken) . '/sendMessage');
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if (!is_string($result)) return false;
    $json = json_decode($result, true);
    return is_array($json) && ($json['ok'] ?? false) === true;
}

function skynoc_telegram_edit(string $botToken, string $chatId, int $messageId, string $text, ?array $buttons = null): bool
{
    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($buttons) {
        $payload['reply_markup'] = json_encode(['inline_keyboard' => $buttons], JSON_UNESCAPED_SLASHES);
    }

    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($botToken) . '/editMessageText');
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if (!is_string($result)) return false;
    $json = json_decode($result, true);
    return is_array($json) && ($json['ok'] ?? false) === true;
}

function skynoc_telegram_answer_callback(string $botToken, string $callbackId, string $text = ''): bool
{
    if ($botToken === '' || $callbackId === '') return false;
    $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($botToken) . '/answerCallbackQuery');
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['callback_query_id' => $callbackId, 'text' => $text],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    if (!is_string($result)) return false;
    $json = json_decode($result, true);
    return is_array($json) && ($json['ok'] ?? false) === true;
}

function skynoc_license_telegram_send(string $text, ?array $buttons = null): bool
{
    $cfg = skynoc_telegram_settings();
    $token = trim((string)($cfg['license_bot_token'] ?? $cfg['bot_token'] ?? ''));
    $chat = trim((string)($cfg['license_admin_chat_id'] ?? $cfg['admin_chat_id'] ?? ''));
    return skynoc_telegram_send($token, $chat, $text, $buttons);
}

function skynoc_deposit_telegram_send(string $text): bool
{
    $cfg = skynoc_telegram_settings();
    $token = trim((string)($cfg['deposit_bot_token'] ?? ''));
    $chat = trim((string)($cfg['deposit_admin_chat_id'] ?? ''));
    return skynoc_telegram_send($token, $chat, $text);
}

function skynoc_reissue_buttons(int $requestId): array
{
    return [[
        ['text' => '🔎 Review', 'callback_data' => 'reissue:' . $requestId . ':review'],
        ['text' => '🛠 Manual Reissue', 'callback_data' => 'reissue:' . $requestId . ':manual_reissue'],
    ],[
        ['text' => '✅ Completed', 'callback_data' => 'reissue:' . $requestId . ':completed'],
        ['text' => '❌ Rejected', 'callback_data' => 'reissue:' . $requestId . ':rejected'],
    ]];
}

function skynoc_notify_reissue_request(array $rr): bool
{
    $text = "🔔 <b>NEW LICENSE REISSUE REQUEST</b>\n\n"
        . "🆔 Request: #" . (int)$rr['id'] . "\n"
        . "👤 Reseller: " . e((string)$rr['reseller_name']) . " (#" . (int)$rr['reseller_id'] . ")\n"
        . "📧 Email: " . e((string)$rr['reseller_email']) . "\n"
        . "🔑 License: <code>" . e((string)$rr['license_key']) . "</code>\n"
        . "🌐 Current: " . e((string)($rr['current_domain'] ?: '-')) . "\n"
        . "➡️ New: " . e((string)$rr['new_domain']) . "\n"
        . "📝 Reason: " . e((string)($rr['reason'] ?: '-')) . "\n"
        . "📌 Status: <b>" . e(strtoupper((string)$rr['status'])) . "</b>";

    return skynoc_license_telegram_send($text, skynoc_reissue_buttons((int)$rr['id']));
}
