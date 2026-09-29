<?php
declare(strict_types=1);

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function current_user(): ?array { global $db; static $user = false; if ($user !== false) return $user; $id = $_SESSION['user_id'] ?? null; if (!$id) return $user = null; $s=$db->prepare('SELECT id,name,email,role,status,permissions FROM users WHERE id=? LIMIT 1'); $s->execute([$id]); return $user=$s->fetch() ?: null; }
function require_auth(): array { $u=current_user(); if (!$u || $u['status'] !== 'active') { $_SESSION=[]; session_destroy(); redirect('/login.php'); } return $u; }
function require_role(array $roles): array { $u=require_auth(); if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('Forbidden'); } return $u; }
function can(string $permission, array $user): bool {
    if (($user['role'] ?? '') === 'owner') return true;
    $overrides = [];
    if (!empty($user['permissions'])) {
        $decoded = json_decode((string)$user['permissions'], true);
        if (is_array($decoded)) $overrides = $decoded;
    }
    if (array_key_exists($permission, $overrides)) return (bool)$overrides[$permission];
    $map=[
      'provider.manage'=>['admin','manager'],
      'license.manage'=>['admin','manager'],
      'reseller.manage'=>['admin'],
      'reissue.manage'=>['admin','manager'],
      'api.manage'=>['admin','manager'],
      'staff.manage'=>['admin'],
      'ticket.manage'=>['admin','manager','staff'],
    ];
    return in_array($user['role'], $map[$permission] ?? [], true);
}
function notify_reseller(int $resellerId, string $type, string $title, string $message): void {
    global $db;
    $s=$db->prepare('INSERT INTO notifications(reseller_id,type,title,message) VALUES(?,?,?,?)');
    $s->execute([$resellerId,$type,$title,$message]);
}
function api_has_scope(array $key, string $scope): bool {
    $scopes=array_filter(array_map('trim',explode(',',(string)($key['scopes'] ?? ''))));
    return in_array($scope,$scopes,true);
}
function audit(string $action, ?string $entity=null, ?int $entityId=null, ?string $details=null): void { global $db; $u=current_user(); $s=$db->prepare('INSERT INTO audit_logs(user_id,action,entity,entity_id,details,ip_address) VALUES(?,?,?,?,?,?)'); $s->execute([$u['id'] ?? null,$action,$entity,$entityId,$details,$_SERVER['REMOTE_ADDR'] ?? null]); }
function json_response(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function telegram_notify(string $text, ?array $buttons=null): bool {
    global $db;
    $s=$db->query("SELECT bot_token,admin_chat_id FROM telegram_settings WHERE id=1 LIMIT 1");
    $cfg=$s->fetch();
    if (!$cfg || !$cfg['bot_token'] || !$cfg['admin_chat_id']) return false;
    $payload=['chat_id'=>$cfg['admin_chat_id'],'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
    if ($buttons) $payload['reply_markup']=json_encode(['inline_keyboard'=>$buttons]);
    $ch=curl_init('https://api.telegram.org/bot'.rawurlencode($cfg['bot_token']).'/sendMessage');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $result=curl_exec($ch); curl_close($ch);
    return is_string($result) && (json_decode($result,true)['ok'] ?? false) === true;
}

function reseller_wallet(int $resellerId): float {
    global $db;
    $s=$db->prepare('SELECT wallet_balance FROM resellers WHERE id=? LIMIT 1');
    $s->execute([$resellerId]);
    return (float)($s->fetchColumn() ?? 0);
}
function wallet_credit(int $resellerId, float $amount, string $type='deposit', ?string $reference=null, ?string $description=null, ?int $orderId=null, ?int $createdBy=null): void {
    global $db;
    if ($amount <= 0) throw new RuntimeException('Credit amount must be positive.');
    $db->prepare('UPDATE resellers SET wallet_balance=wallet_balance+? WHERE id=?')->execute([$amount,$resellerId]);
    $s=$db->prepare('INSERT INTO wallet_transactions(reseller_id,type,amount,reference,description,order_id,created_by) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$resellerId,$type,$amount,$reference,$description,$orderId,$createdBy]);
}
function wallet_debit(int $resellerId, float $amount, string $type='purchase', ?string $reference=null, ?string $description=null, ?int $orderId=null, ?int $createdBy=null): void {
    global $db;
    if ($amount <= 0) throw new RuntimeException('Debit amount must be positive.');
    $s=$db->prepare('UPDATE resellers SET wallet_balance=wallet_balance-? WHERE id=? AND wallet_balance>=?');
    $s->execute([$amount,$resellerId,$amount]);
    if ($s->rowCount() !== 1) throw new RuntimeException('Insufficient wallet balance.');
    $s=$db->prepare('INSERT INTO wallet_transactions(reseller_id,type,amount,reference,description,order_id,created_by) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$resellerId,$type,-$amount,$reference,$description,$orderId,$createdBy]);
}


function reseller_level_thresholds(): array {
    return [
        1 => ['min' => 0, 'max' => 10, 'name' => 'Starter Reseller'],
        2 => ['min' => 11, 'max' => 25, 'name' => 'Growing Reseller'],
        3 => ['min' => 26, 'max' => 50, 'name' => 'Pro Reseller'],
        4 => ['min' => 51, 'max' => 100, 'name' => 'Elite Reseller'],
        5 => ['min' => 101, 'max' => PHP_INT_MAX, 'name' => 'Top Reseller'],
    ];
}

function reseller_active_license_count(int $resellerId): int {
    global $db;
    $s = $db->prepare("SELECT COUNT(*) FROM licenses WHERE reseller_id=? AND status='active'");
    $s->execute([$resellerId]);
    return (int)$s->fetchColumn();
}

function reseller_calculated_level(int $activeLicenses): int {
    foreach (reseller_level_thresholds() as $level => $range) {
        if ($activeLicenses >= $range['min'] && $activeLicenses <= $range['max']) return $level;
    }
    return 1;
}

function reseller_level(int $resellerId): array {
    global $db;
    $s = $db->prepare('SELECT level_mode, custom_level FROM resellers WHERE id=? LIMIT 1');
    $s->execute([$resellerId]);
    $r = $s->fetch() ?: ['level_mode' => 'auto', 'custom_level' => null];
    $active = reseller_active_license_count($resellerId);
    $calculated = reseller_calculated_level($active);
    $assigned = (($r['level_mode'] ?? 'auto') === 'custom' && (int)($r['custom_level'] ?? 0) >= 1)
        ? (int)$r['custom_level'] : $calculated;
    $ranges = reseller_level_thresholds();
    $current = $ranges[$assigned] ?? $ranges[$calculated];
    $next = $assigned < 5 ? $ranges[$assigned + 1] : null;
    $nextLevel = $assigned < 5 ? $assigned + 1 : null;
    $needed = $next ? max(0, $next['min'] - $active) : 0;
    return [
        'active' => $active,
        'calculated_level' => $calculated,
        'assigned_level' => $assigned,
        'mode' => (($r['level_mode'] ?? 'auto') === 'custom') ? 'custom' : 'auto',
        'name' => $current['name'],
        'min' => $current['min'],
        'max' => $current['max'],
        'next_level' => $nextLevel,
        'next_name' => $next['name'] ?? null,
        'needed' => $needed,
        'progress' => $assigned >= 5 ? 100 : min(100, max(0, (int)round(($active / max(1, $next['min'])) * 100))),
    ];
}

function reseller_level_discount(int $level): float {
    global $db;
    $s=$db->prepare('SELECT discount_percent FROM reseller_level_discounts WHERE level=? LIMIT 1');
    $s->execute([$level]);
    return max(0.0,min(100.0,(float)($s->fetchColumn() ?? 0)));
}
function reseller_package_price(float $basePrice,int $level): float {
    $discount=reseller_level_discount($level);
    return round(max(0.0,$basePrice-($basePrice*$discount/100)),2);
}
