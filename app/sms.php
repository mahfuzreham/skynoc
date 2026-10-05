<?php
declare(strict_types=1);

function skynoc_sms_settings(): array
{
    global $db;
    $row = $db->query('SELECT * FROM sms_settings WHERE id=1 LIMIT 1')->fetch();
    return $row ?: ['enabled' => 0];
}

function skynoc_sms_country(string $iso): ?array
{
    global $db;
    $q = $db->prepare('SELECT * FROM sms_country_settings WHERE country_iso=? LIMIT 1');
    $q->execute([strtoupper($iso)]);
    return $q->fetch() ?: null;
}

function skynoc_sms_in_quiet_hours(array $contact, ?array $country): bool
{
    if (!$country || empty($country['quiet_start']) || empty($country['quiet_end'])) return false;
    $tzName = $country['timezone'] ?: ($contact['timezone'] ?: 'UTC');
    try { $tz = new DateTimeZone($tzName); } catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
    $now = new DateTimeImmutable('now', $tz);
    $time = $now->format('H:i:s');
    $start = (string)$country['quiet_start'];
    $end = (string)$country['quiet_end'];
    if ($start === $end) return true;
    if ($start < $end) return $time >= $start && $time < $end;
    return $time >= $start || $time < $end;
}

function skynoc_sms_replace_placeholders(mixed $value, array $vars): mixed
{
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = skynoc_sms_replace_placeholders($v, $vars);
        return $value;
    }
    if (!is_string($value)) return $value;
    return strtr($value, [
        '{to}' => (string)($vars['to'] ?? ''),
        '{message}' => (string)($vars['message'] ?? ''),
        '{sender}' => (string)($vars['sender'] ?? ''),
        '{api_key}' => (string)($vars['api_key'] ?? ''),
        '{api_secret}' => (string)($vars['api_secret'] ?? ''),
    ]);
}

function skynoc_sms_send_user(int $userId, string $type, string $message, ?int $licenseId = null, ?string $dedupeKey = null): array
{
    global $db;
    $settings = skynoc_sms_settings();
    if (empty($settings['enabled'])) return ['status' => 'disabled'];

    $q = $db->prepare("SELECT c.*,u.name,u.email FROM sms_contacts c JOIN users u ON u.id=c.user_id WHERE c.user_id=? LIMIT 1");
    $q->execute([$userId]);
    $contact = $q->fetch();
    if (!$contact || empty($contact['selected']) || empty($contact['sms_opt_in']) || trim((string)$contact['phone']) === '') {
        return ['status' => 'skipped', 'reason' => 'User is not selected, has no phone, or has not opted in.'];
    }

    $country = skynoc_sms_country((string)$contact['country_iso']);
    if ($country && empty($country['enabled'])) return ['status' => 'skipped', 'reason' => 'Country SMS delivery is disabled.'];
    if (skynoc_sms_in_quiet_hours($contact, $country)) return ['status' => 'skipped', 'reason' => 'Recipient country is currently in quiet hours.'];

    if ($dedupeKey !== null) {
        $d = $db->prepare('SELECT id,status FROM sms_logs WHERE dedupe_key=? LIMIT 1');
        $d->execute([$dedupeKey]);
        $existing = $d->fetch();
        if ($existing) return ['status' => 'duplicate', 'log_id' => (int)$existing['id']];
    }

    $phone = trim((string)$contact['phone']);
    $payload = [
        (string)($settings['recipient_field'] ?: 'to') => $phone,
        (string)($settings['message_field'] ?: 'message') => $message,
        (string)($settings['sender_field'] ?: 'sender') => (string)($settings['sender_id'] ?? ''),
    ];
    if (!empty($settings['payload_template'])) {
        $template = json_decode((string)$settings['payload_template'], true);
        if (is_array($template)) {
            $payload = skynoc_sms_replace_placeholders($template, [
                'to' => $phone,
                'message' => $message,
                'sender' => (string)($settings['sender_id'] ?? ''),
                'api_key' => (string)($settings['api_key'] ?? ''),
                'api_secret' => (string)($settings['api_secret'] ?? ''),
            ]);
        }
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (!empty($settings['api_key'])) {
        $header = (string)($settings['auth_header'] ?: 'Authorization');
        $prefix = (string)($settings['auth_prefix'] ?? 'Bearer ');
        $headers[] = $header . ': ' . $prefix . (string)$settings['api_key'];
    }
    if (!empty($settings['api_secret'])) $headers[] = 'X-API-Secret: ' . (string)$settings['api_secret'];

    $log = $db->prepare('INSERT INTO sms_logs(user_id,license_id,type,phone,message,status,dedupe_key) VALUES(?,?,?,?,?,?,?)');
    try {
        $log->execute([$userId, $licenseId, $type, $phone, $message, 'queued', $dedupeKey]);
        $logId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        if ($dedupeKey !== null) return ['status' => 'duplicate'];
        throw $e;
    }

    $url = trim((string)($settings['api_url'] ?? ''));
    if ($url === '') {
        $db->prepare('UPDATE sms_logs SET status=?,provider_response=? WHERE id=?')->execute(['failed','SMS API URL is not configured.',$logId]);
        return ['status' => 'failed', 'log_id' => $logId, 'reason' => 'SMS API URL is not configured.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $providerResponse = $curlError !== '' ? $curlError : ('HTTP ' . $httpCode . ' ' . substr((string)$response, 0, 4000));
    $ok = $curlError === '' && $httpCode >= 200 && $httpCode < 300;
    $db->prepare('UPDATE sms_logs SET status=?,provider_response=?,sent_at=? WHERE id=?')
        ->execute([$ok ? 'sent' : 'failed', $providerResponse, $ok ? date('Y-m-d H:i:s') : null, $logId]);

    return ['status' => $ok ? 'sent' : 'failed', 'log_id' => $logId, 'http_code' => $httpCode, 'response' => $providerResponse];
}

function skynoc_sms_send_reseller(int $resellerId, string $type, string $message, ?int $licenseId = null, ?string $dedupeKey = null): array
{
    global $db;
    $q = $db->prepare('SELECT user_id FROM resellers WHERE id=? LIMIT 1');
    $q->execute([$resellerId]);
    $userId = (int)$q->fetchColumn();
    if ($userId <= 0) return ['status' => 'skipped', 'reason' => 'No reseller user account.'];
    return skynoc_sms_send_user($userId, $type, $message, $licenseId, $dedupeKey);
}

function skynoc_sms_send_selected(array $userIds, string $message, string $type = 'bulk_manual'): array
{
    $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'duplicate' => 0];
    foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
        if ($userId <= 0) continue;
        $result = skynoc_sms_send_user($userId, $type, $message);
        $status = $result['status'] ?? 'failed';
        if (isset($results[$status])) $results[$status]++;
    }
    return $results;
}
