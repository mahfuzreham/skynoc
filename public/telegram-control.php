<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$cfg = skynoc_telegram_settings();
$token = trim((string)($cfg['license_bot_token'] ?? $cfg['bot_token'] ?? ''));
$adminChat = trim((string)($cfg['license_admin_chat_id'] ?? $cfg['admin_chat_id'] ?? ''));

if ($token === '' || $adminChat === '') {
    http_response_code(503);
    exit('Telegram control bot is not configured.');
}

$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);
if (!is_array($update)) {
    http_response_code(400);
    exit('Invalid Telegram update.');
}

function skynoc_telegram_authorized_chat(array $update, string $adminChat): bool
{
    $chatId = '';
    if (!empty($update['callback_query']['message']['chat']['id'])) {
        $chatId = (string)$update['callback_query']['message']['chat']['id'];
    } elseif (!empty($update['message']['chat']['id'])) {
        $chatId = (string)$update['message']['chat']['id'];
    }
    return $chatId !== '' && hash_equals($adminChat, $chatId);
}

if (!skynoc_telegram_authorized_chat($update, $adminChat)) {
    http_response_code(403);
    exit('Forbidden.');
}

$owner = $db->query("SELECT id,name FROM users WHERE role='owner' AND status='active' ORDER BY id ASC LIMIT 1")->fetch();
if (!$owner) {
    http_response_code(500);
    exit('Active owner account not found.');
}

function skynoc_reissue_fetch(PDO $db, int $id): ?array
{
    $s = $db->prepare('SELECT rr.*,l.license_key,r.name reseller_name,r.email reseller_email
                       FROM reissue_requests rr
                       JOIN licenses l ON l.id=rr.license_id
                       JOIN resellers r ON r.id=rr.reseller_id
                       WHERE rr.id=? LIMIT 1');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function skynoc_reissue_render(array $rr): string
{
    return "📌 <b>LICENSE REISSUE</b>\n\n"
        . "🆔 Request: #" . (int)$rr['id'] . "\n"
        . "👤 Reseller: " . e((string)$rr['reseller_name']) . " (#" . (int)$rr['reseller_id'] . ")\n"
        . "📧 Email: " . e((string)$rr['reseller_email']) . "\n"
        . "🔑 License: <code>" . e((string)$rr['license_key']) . "</code>\n"
        . "🌐 Current: " . e((string)($rr['current_domain'] ?: '-')) . "\n"
        . "➡️ New: " . e((string)$rr['new_domain']) . "\n"
        . "📝 Reason: " . e((string)($rr['reason'] ?: '-')) . "\n"
        . "📌 Status: <b>" . e(strtoupper((string)$rr['status'])) . "</b>"
        . ($rr['admin_note'] ? "\n🗒 Note: " . e((string)$rr['admin_note']) : '');
}

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = (string)($callback['data'] ?? '');
    $callbackId = (string)($callback['id'] ?? '');
    skynoc_telegram_answer_callback($token, $callbackId);

    if (!preg_match('/^reissue:(\d+):(review|manual_reissue|completed|rejected)$/', $data, $m)) {
        exit('OK');
    }

    $requestId = (int)$m[1];
    $newStatus = $m[2];
    $rr = skynoc_reissue_fetch($db, $requestId);
    if (!$rr) {
        skynoc_telegram_answer_callback($token, $callbackId, 'Request not found.');
        exit('OK');
    }

    if (in_array($rr['status'], ['completed','rejected'], true)) {
        skynoc_telegram_answer_callback($token, $callbackId, 'This request is already closed.');
        exit('OK');
    }

    $db->beginTransaction();
    try {
        $note = 'Updated from Telegram admin control.';
        $db->prepare('UPDATE reissue_requests SET status=?,handled_by=?,admin_note=? WHERE id=?')
           ->execute([$newStatus,(int)$owner['id'],$note,$requestId]);

        if ($newStatus === 'completed') {
            $db->prepare('UPDATE licenses SET domain=?,status="active" WHERE id=?')
               ->execute([$rr['new_domain'],$rr['license_id']]);
            $db->prepare('INSERT INTO license_history(license_id,user_id,action,old_domain,new_domain,notes)
                          VALUES(?,?,?,?,?,?)')
               ->execute([(int)$rr['license_id'],(int)$owner['id'],'reissue_completed',
                          $rr['current_domain'],$rr['new_domain'],$note]);
        }

        notify_reseller(
            (int)$rr['reseller_id'],
            'reissue',
            'Reissue ' . $newStatus,
            'License ' . $rr['license_key'] . ' reissue request is now ' . strtoupper($newStatus) . '.'
        );

        $db->commit();
        audit('telegram_reissue_' . $newStatus, 'reissue_requests', $requestId, 'Telegram admin control');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        skynoc_telegram_answer_callback($token, $callbackId, 'Update failed.');
        error_log('SkyNoc Telegram reissue error: ' . $e->getMessage());
        exit('OK');
    }

    $rr = skynoc_reissue_fetch($db, $requestId);
    if ($rr) {
        $buttons = in_array($rr['status'], ['completed','rejected'], true) ? null : skynoc_reissue_buttons($requestId);
        $chatId = (string)$update['callback_query']['message']['chat']['id'];
        $messageId = (int)$update['callback_query']['message']['message_id'];
        skynoc_telegram_edit($token, $chatId, $messageId, skynoc_reissue_render($rr), $buttons);
    }
    exit('OK');
}

$message = $update['message'] ?? [];
$text = trim((string)($message['text'] ?? ''));
if ($text === '/start' || $text === '/help') {
    skynoc_telegram_send(
        $token,
        $adminChat,
        "🛡 <b>SkyNoc License Control Bot</b>\n\n"
        . "/reissues — show pending license reissue requests\n"
        . "/reissue 123 — show one request"
    );
    exit('OK');
}

if ($text === '/reissues') {
    $rows = $db->query("SELECT rr.*,l.license_key,r.name reseller_name,r.email reseller_email
                        FROM reissue_requests rr
                        JOIN licenses l ON l.id=rr.license_id
                        JOIN resellers r ON r.id=rr.reseller_id
                        WHERE rr.status IN ('pending','review','manual_reissue')
                        ORDER BY rr.id DESC LIMIT 10")->fetchAll();

    if (!$rows) {
        skynoc_telegram_send($token, $adminChat, '✅ No open license reissue requests.');
    } else {
        foreach ($rows as $rr) {
            skynoc_telegram_send($token, $adminChat, skynoc_reissue_render($rr), skynoc_reissue_buttons((int)$rr['id']));
        }
    }
    exit('OK');
}

if (preg_match('/^\/reissue\s+(\d+)$/', $text, $m)) {
    $rr = skynoc_reissue_fetch($db, (int)$m[1]);
    if (!$rr) {
        skynoc_telegram_send($token, $adminChat, '❌ Reissue request not found.');
    } else {
        $buttons = in_array($rr['status'], ['completed','rejected'], true) ? null : skynoc_reissue_buttons((int)$rr['id']);
        skynoc_telegram_send($token, $adminChat, skynoc_reissue_render($rr), $buttons);
    }
    exit('OK');
}

skynoc_telegram_send($token, $adminChat, "Use /reissues to view open license reissue requests.");
echo 'OK';
