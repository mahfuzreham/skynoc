<?php
declare(strict_types=1);

function payment_methods(): array {
    global $db;
    $rows=$db->query("SELECT code,name,enabled,min_deposit,instructions,sort_order,config_json FROM payment_methods ORDER BY sort_order,id")->fetchAll();
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
