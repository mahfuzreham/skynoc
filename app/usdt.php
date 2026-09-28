<?php
declare(strict_types=1);

/**
 * Read-only BSC USDT (BEP-20) verification.
 * No private key is ever used here; the server only reads public blockchain data.
 */

function skynoc_usdt_config(): array
{
    global $config;
    $defaults = [
        'enabled' => true,
        'chain_id' => 56,
        'rpc_url' => 'https://bsc-dataseed.bnbchain.org',
        'token_contract' => '0x55d398326f99059ff775485246999027b3197955',
        'decimals' => 18,
        'receiving_address' => '',
        'min_confirmations' => 12,
    ];
    $local = is_array($config['usdt_bep20'] ?? null) ? $config['usdt_bep20'] : [];
    return array_merge($defaults, $local);
}

function skynoc_bsc_rpc(string $method, array $params = []): mixed
{
    $cfg = skynoc_usdt_config();
    $ch = curl_init((string)$cfg['rpc_url']);
    if ($ch === false) throw new RuntimeException('Could not initialize BSC RPC client.');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        throw new RuntimeException('BSC RPC connection failed: ' . ($error ?: 'unknown error'));
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('Invalid response from BSC RPC.');
    if (isset($json['error'])) throw new RuntimeException('BSC RPC error: ' . (string)($json['error']['message'] ?? 'unknown error'));
    return $json['result'] ?? null;
}

function skynoc_hex_to_decimal(string $hex): string
{
    $hex = strtolower(ltrim($hex, '0x'));
    if ($hex === '') return '0';
    if (!preg_match('/^[0-9a-f]+$/', $hex)) throw new RuntimeException('Invalid blockchain amount.');
    $dec = '0';
    $digits = array_flip(str_split('0123456789abcdef'));
    foreach (str_split($hex) as $char) {
        $carry = $digits[$char];
        $out = '';
        $n = strlen($dec);
        for ($i = $n - 1; $i >= 0; $i--) {
            $v = ((int)$dec[$i] * 16) + $carry;
            $out = ($v % 10) . $out;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $out = ($carry % 10) . $out;
            $carry = intdiv($carry, 10);
        }
        $dec = ltrim($out, '0') ?: '0';
    }
    return $dec;
}

function skynoc_decimal_to_units(string $amount, int $decimals): string
{
    $amount = trim($amount);
    if (!preg_match('/^\d+(?:\.\d{1,' . $decimals . '})?$/', $amount)) {
        throw new RuntimeException('Invalid USDT amount.');
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
    $fraction = str_pad($fraction, $decimals, '0');
    $units = ltrim($whole . $fraction, '0');
    return $units === '' ? '0' : $units;
}

function skynoc_units_to_decimal(string $units, int $decimals): string
{
    $units = ltrim($units, '0') ?: '0';
    if ($decimals === 0) return $units;

    if (strlen($units) <= $decimals) {
        $units = str_pad($units, $decimals + 1, '0', STR_PAD_LEFT);
    }
    $whole = substr($units, 0, -$decimals);
    $fraction = rtrim(substr($units, -$decimals), '0');
    return $whole . ($fraction !== '' ? '.' . $fraction : '');
}

function verify_bsc_usdt_tx(string $txHash, string $expectedAmount): array
{
    $cfg = skynoc_usdt_config();

    if (empty($cfg['enabled'])) throw new RuntimeException('USDT BEP-20 deposits are currently disabled.');
    $receiver = strtolower(trim((string)$cfg['receiving_address']));
    $contract = strtolower(trim((string)$cfg['token_contract']));
    if (!preg_match('/^0x[a-f0-9]{40}$/i', $receiver)) {
        throw new RuntimeException('USDT receiving address is not configured.');
    }
    if (!preg_match('/^0x[a-f0-9]{40}$/i', $contract)) {
        throw new RuntimeException('USDT token contract is not configured.');
    }
    if (!preg_match('/^0x[a-f0-9]{64}$/i', $txHash)) {
        throw new RuntimeException('Invalid BSC transaction hash.');
    }

    $chainId = skynoc_bsc_rpc('eth_chainId');
    if (hexdec((string)$chainId) !== (int)$cfg['chain_id']) {
        throw new RuntimeException('Connected RPC is not BNB Smart Chain mainnet.');
    }

    $receipt = skynoc_bsc_rpc('eth_getTransactionReceipt', [$txHash]);
    if (!$receipt) throw new RuntimeException('Transaction not found or still pending.');
    if (strtolower((string)($receipt['status'] ?? '0x0')) !== '0x1') {
        throw new RuntimeException('Blockchain transaction failed.');
    }

    $blockHex = (string)($receipt['blockNumber'] ?? '');
    if ($blockHex === '') throw new RuntimeException('Transaction block is unavailable.');
    $blockNumber = hexdec($blockHex);
    $latestHex = (string)skynoc_bsc_rpc('eth_blockNumber');
    $latestBlock = hexdec($latestHex);
    $confirmations = max(0, $latestBlock - $blockNumber + 1);
    $required = max(1, (int)$cfg['min_confirmations']);
    if ($confirmations < $required) {
        throw new RuntimeException("Transaction has {$confirmations} confirmation(s); {$required} required.");
    }

    $expectedUnits = skynoc_decimal_to_units($expectedAmount, (int)$cfg['decimals']);
    $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    foreach (($receipt['logs'] ?? []) as $log) {
        $topics = $log['topics'] ?? [];
        if (strtolower((string)($log['address'] ?? '')) !== $contract) continue;
        if (strtolower((string)($topics[0] ?? '')) !== $transferTopic) continue;
        if (count($topics) < 3) continue;

        $toTopic = strtolower((string)$topics[2]);
        $toAddress = '0x' . substr($toTopic, -40);
        if ($toAddress !== $receiver) continue;

        $fromTopic = strtolower((string)$topics[1]);
        $fromAddress = '0x' . substr($fromTopic, -40);
        $units = skynoc_hex_to_decimal((string)($log['data'] ?? '0x0'));

        if ($units !== $expectedUnits) {
            continue;
        }

        return [
            'tx_hash' => strtolower($txHash),
            'block_number' => $blockNumber,
            'confirmations' => $confirmations,
            'from_address' => $fromAddress,
            'to_address' => $receiver,
            'token_contract' => $contract,
            'token_amount' => skynoc_units_to_decimal($units, (int)$cfg['decimals']),
        ];
    }

    throw new RuntimeException('No matching USDT BEP-20 transfer to the configured receiving address was found.');
}
