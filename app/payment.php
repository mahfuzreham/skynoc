<?php
declare(strict_types=1);

function payment_methods(): array {
    global $db;
    $rows=$db->query("SELECT code,name,enabled,min_deposit,instructions,sort_order,config_json FROM payment_methods ORDER BY sort_order,id")->fetchAll();

    // Expose only the public receiving address to reseller-facing pages.
    // API credentials/secrets are never returned as public payment data.
    $isResellerDeposit = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/reseller') !== false;
    if ($isResellerDeposit) {
        foreach ($rows as &$row) {
            $cfg = [];
            if (!empty($row['config_json'])) {
                $decoded = json_decode((string)$row['config_json'], true);
                if (is_array($decoded)) $cfg = $decoded;
            }

            $address = '';
            foreach (['receiving_address','wallet_address','payment_address','deposit_address'] as $key) {
                if (!empty($cfg[$key]) && is_string($cfg[$key])) {
                    $address = trim($cfg[$key]);
                    break;
                }
            }

            if ($address === '') {
                foreach ($cfg as $nested) {
                    if (!is_array($nested)) continue;
                    foreach (['receiving_address','wallet_address','payment_address','deposit_address'] as $key) {
                        if (!empty($nested[$key]) && is_string($nested[$key])) {
                            $address = trim($nested[$key]);
                            break 2;
                        }
                    }
                }
            }

            $row['public_address'] = $address;
            // Keep the current UI backward-compatible until the reseller
            // Add Funds card renders public_address in its own address box.
            if ($address !== '') $row['name'] .= ' · Wallet: '.$address;
        }
        unset($row);
    }

    return $rows;
}
function payment_method(string $code): ?array {
    global $db;
    $s=$db->prepare("SELECT code,name,enabled,min_deposit,instructions,sort_order,config_json FROM payment_methods WHERE code=? LIMIT 1");
    $s->execute([$code]);
    return $s->fetch() ?: null;
}
function payment_method_min(string $code, bool $activation=false): float {
    $m=payment_method($code);
    if(!$m || !(int)$m['enabled']) throw new RuntimeException('This payment method is currently unavailable.');
    $min=(float)$m['min_deposit'];
    if($activation) $min=max(15.00,$min);
    return $min;
}
function payment_method_config(string $code): array {
    $m=payment_method($code);
    if(!$m || empty($m['config_json'])) return [];
    $v=json_decode((string)$m['config_json'],true);
    return is_array($v)?$v:[];
}
