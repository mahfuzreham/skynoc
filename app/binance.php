<?php
declare(strict_types=1);

function skynoc_binance_config(): array
{
    $m = function_exists('payment_method_config') ? payment_method_config('USDT_BEP20') : [];
    $b = is_array($m['binance'] ?? null) ? $m['binance'] : [];
    return array_merge([
        'enabled' => false,
        'api_key' => '',
        'api_secret' => '',
        'base_url' => 'https://api.binance.com',
        'network' => 'BSC',
        'coin' => 'USDT',
        'auto_scan' => true,
        'scan_minutes' => 30,
    ], $b);
}

function skynoc_binance_request(string $method, string $path, array $params = []): array
{
    $cfg = skynoc_binance_config();
    if (empty($cfg['enabled']) || trim((string)$cfg['api_key']) === '' || trim((string)$cfg['api_secret']) === '') {
        throw new RuntimeException('Binance API integration is not configured.');
    }

    $params['timestamp'] = (int)round(microtime(true) * 1000);
    $params['recvWindow'] = 5000;
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $signature = hash_hmac('sha256', $query, (string)$cfg['api_secret']);
    $url = rtrim((string)$cfg['base_url'], '/') . $path . '?' . $query . '&signature=' . $signature;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . (string)$cfg['api_key'], 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) throw new RuntimeException('Binance API connection failed: ' . ($error ?: 'unknown error'));
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('Invalid response from Binance API.');
    if ($status >= 400 || isset($data['code']) && (int)$data['code'] < 0) {
        throw new RuntimeException('Binance API error: ' . (string)($data['msg'] ?? ('HTTP ' . $status)));
    }
    return $data;
}

function skynoc_binance_test(): array
{
    return skynoc_binance_request('GET', '/sapi/v1/capital/deposit/hisrec', [
        'coin' => 'USDT',
        'network' => 'BSC',
        'limit' => 1,
    ]);
}

function skynoc_binance_deposits(int $startTime = 0, int $limit = 100): array
{
    $cfg = skynoc_binance_config();
    $params = [
        'coin' => (string)$cfg['coin'],
        'network' => (string)$cfg['network'],
        'limit' => max(1, min(100, $limit)),
    ];
    if ($startTime > 0) $params['startTime'] = $startTime;
    return skynoc_binance_request('GET', '/sapi/v1/capital/deposit/hisrec', $params);
}

function skynoc_binance_find_deposit(string $txHash, ?string $expectedAmount = null): ?array
{
    $txHash = strtolower(trim($txHash));
    if (!preg_match('/^0x[a-f0-9]{64}$/i', $txHash)) return null;
    $rows = skynoc_binance_deposits((int)(microtime(true) * 1000) - 180 * 24 * 60 * 60 * 1000, 100);
    foreach (($rows ?? []) as $row) {
        if (strtolower((string)($row['txId'] ?? '')) !== $txHash) continue;
        if ((string)($row['network'] ?? '') !== 'BSC') continue;
        if ((string)($row['coin'] ?? '') !== 'USDT') continue;
        if ((int)($row['status'] ?? 0) !== 1) throw new RuntimeException('Binance has not credited this deposit yet.');
        if ($expectedAmount !== null && bccomp((string)($row['amount'] ?? '0'), $expectedAmount, 8) < 0) {
            throw new RuntimeException('Binance deposit amount is lower than the required amount.');
        }
        return $row;
    }
    return null;
}

function skynoc_binance_find_recent_matching_deposit(string $expectedAmount, int $minutes = 30): ?array
{
    $start = (int)(microtime(true) * 1000) - max(1, min(1440, $minutes)) * 60 * 1000;
    $rows = skynoc_binance_deposits($start, 100);
    foreach (($rows ?? []) as $row) {
        if ((string)($row['network'] ?? '') !== 'BSC' || (string)($row['coin'] ?? '') !== 'USDT') continue;
        if ((int)($row['status'] ?? 0) !== 1) continue;
        if (bccomp((string)($row['amount'] ?? '0'), $expectedAmount, 8) >= 0) return $row;
    }
    return null;
}
