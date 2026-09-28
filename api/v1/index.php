<?php
require __DIR__ . '/../../app/bootstrap.php';

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) json_response(['error'=>'missing_api_key'],401);

$hash = hash('sha256', trim($m[1]));
$s = $db->prepare('SELECT ak.*,r.name reseller_name FROM api_keys ak JOIN resellers r ON r.id=ak.reseller_id WHERE ak.key_hash=? AND ak.status="active" LIMIT 1');
$s->execute([$hash]);
$key = $s->fetch();

if (!$key || ($key['expires_at'] && strtotime($key['expires_at']) < time())) {
    json_response(['error'=>'invalid_api_key'],401);
}

/* 60 requests/minute per API key. */
$now = new DateTimeImmutable('now');
$rate = $db->prepare('SELECT window_started_at,request_count FROM api_rate_limits WHERE api_key_id=?');
$rate->execute([$key['id']]);
$rl = $rate->fetch();
if (!$rl) {
    $db->prepare('INSERT INTO api_rate_limits(api_key_id,window_started_at,request_count) VALUES(?,NOW(),1)')->execute([$key['id']]);
} else {
    $started = new DateTimeImmutable($rl['window_started_at']);
    if (($now->getTimestamp() - $started->getTimestamp()) >= 60) {
        $db->prepare('UPDATE api_rate_limits SET window_started_at=NOW(),request_count=1 WHERE api_key_id=?')->execute([$key['id']]);
    } elseif ((int)$rl['request_count'] >= 60) {
        header('Retry-After: 60');
        json_response(['error'=>'rate_limit_exceeded','message'=>'Maximum 60 requests per minute.'],429);
    } else {
        $db->prepare('UPDATE api_rate_limits SET request_count=request_count+1 WHERE api_key_id=?')->execute([$key['id']]);
    }
}
$db->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([$key['id']]);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && preg_match('#/licenses/?$#',$path)) {
    if (!api_has_scope($key,'licenses:read')) json_response(['error'=>'insufficient_scope'],403);
    $s=$db->prepare('SELECT id,license_key,domain,status,expires_at,created_at,updated_at FROM licenses WHERE reseller_id=? ORDER BY id DESC');
    $s->execute([$key['reseller_id']]);
    json_response(['data'=>$s->fetchAll()]);
}

if ($method === 'GET' && preg_match('#/licenses/(\d+)/?$#',$path,$m)) {
    if (!api_has_scope($key,'licenses:read')) json_response(['error'=>'insufficient_scope'],403);
    $s=$db->prepare('SELECT id,license_key,domain,status,purchase_date,cost,expires_at,created_at,updated_at FROM licenses WHERE id=? AND reseller_id=?');
    $s->execute([(int)$m[1],$key['reseller_id']]);
    $lic=$s->fetch();
    if(!$lic) json_response(['error'=>'license_not_found'],404);
    $h=$db->prepare('SELECT action,old_domain,new_domain,notes,created_at FROM license_history WHERE license_id=? ORDER BY id DESC');
    $h->execute([$lic['id']]);
    $lic['history']=$h->fetchAll();
    json_response(['data'=>$lic]);
}

if ($method === 'POST' && preg_match('#/licenses/(\d+)/reissue$#',$path,$m)) {
    if (!api_has_scope($key,'reissue:create')) json_response(['error'=>'insufficient_scope'],403);
    $data=json_decode(file_get_contents('php://input'),true) ?: [];
    $newDomain=trim((string)($data['new_domain'] ?? ''));
    if ($newDomain==='') json_response(['error'=>'new_domain_required'],422);
    $s=$db->prepare('SELECT id,domain,status FROM licenses WHERE id=? AND reseller_id=?');
    $s->execute([(int)$m[1],$key['reseller_id']]);
    $lic=$s->fetch();
    if(!$lic) json_response(['error'=>'license_not_found'],404);
    if(in_array($lic['status'],['cancelled','expired'],true)) json_response(['error'=>'license_not_reissuable'],422);
    $q=$db->prepare('INSERT INTO reissue_requests(license_id,reseller_id,current_domain,new_domain,reason,status) VALUES(?,?,?,?,?,"pending")');
    $q->execute([$lic['id'],$key['reseller_id'],$lic['domain'],$newDomain,trim((string)($data['reason'] ?? '')) ?: null]);
    $id=(int)$db->lastInsertId();
    telegram_notify("🔔 <b>NEW REISSUE REQUEST</b>\nReseller: ".e($key['reseller_name'])."\nRequest: ".$id."\nLicense: ".e((string)$lic['id'])."\nNew: ".e($newDomain));
    json_response(['message'=>'reissue_request_created','request_id'=>$id],201);
}

if ($method === 'GET' && preg_match('#/reissues/?$#',$path)) {
    if (!api_has_scope($key,'reissue:read')) json_response(['error'=>'insufficient_scope'],403);
    $s=$db->prepare('SELECT id,license_id,current_domain,new_domain,reason,status,created_at,updated_at FROM reissue_requests WHERE reseller_id=? ORDER BY id DESC');
    $s->execute([$key['reseller_id']]);
    json_response(['data'=>$s->fetchAll()]);
}

json_response(['error'=>'endpoint_not_found'],404);
