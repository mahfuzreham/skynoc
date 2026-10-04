<?php
declare(strict_types=1);

function payment_methods(): array {
    global $db;
    $rows=$db->query("SELECT code,name,enabled,min_deposit,instructions,sort_order,config_json FROM payment_methods ORDER BY sort_order,id")->fetchAll();

    // Resellers need to see where to send a payment before submitting a deposit.
    // Keep admin/payment-management labels unchanged; only enrich the reseller-facing
    // method label with a configured public receiving address when available.
    $isResellerDeposit = strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/reseller/manage') !== false;
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

            if ($address !== '') {
                $row['name'] .= ' · Wallet: '.$address;
            }
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
