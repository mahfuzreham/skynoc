<?php
declare(strict_types=1);

function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function verify_csrf(): void { if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Invalid CSRF token.'); } }
function current_user(): ?array { global $db; static $user = false; if ($user !== false) return $user; $id = $_SESSION['user_id'] ?? null; if (!$id) return $user = null; $s=$db->prepare('SELECT id,name,email,role,status FROM users WHERE id=? LIMIT 1'); $s->execute([$id]); return $user=$s->fetch() ?: null; }
function require_auth(): array { $u=current_user(); if (!$u || $u['status'] !== 'active') { $_SESSION=[]; session_destroy(); redirect('/login.php'); } return $u; }
function require_role(array $roles): array { $u=require_auth(); if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('Forbidden'); } return $u; }
function can(string $permission, array $user): bool {
    if ($user['role'] === 'owner') return true;
    $map=[
      'provider.manage'=>['admin','manager'],
      'license.manage'=>['admin','manager'],
      'reseller.manage'=>['admin'],
      'reissue.manage'=>['admin','manager'],
      'api.manage'=>['admin','manager'],
      'staff.manage'=>['admin'],
    ];
    return in_array($user['role'], $map[$permission] ?? [], true);
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
