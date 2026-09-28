<?php
require __DIR__ . '/../../app/bootstrap.php';
$auth=$_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.+)/i',$auth,$m)) json_response(['error'=>'missing_api_key'],401);
$hash=hash('sha256',trim($m[1]));
$s=$db->prepare('SELECT ak.*, r.name reseller_name FROM api_keys ak JOIN resellers r ON r.id=ak.reseller_id WHERE ak.key_hash=? AND ak.status="active" LIMIT 1'); $s->execute([$hash]); $key=$s->fetch();
if(!$key || ($key['expires_at'] && strtotime($key['expires_at'])<time())) json_response(['error'=>'invalid_api_key'],401);
$db->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([$key['id']]);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH); $method=$_SERVER['REQUEST_METHOD'];
if($method==='GET' && preg_match('#/licenses/?$#',$path)){ $s=$db->prepare('SELECT id,license_key,domain,status,expires_at FROM licenses WHERE reseller_id=? ORDER BY id DESC');$s->execute([$key['reseller_id']]);json_response(['data'=>$s->fetchAll()]); }
if($method==='POST' && preg_match('#/licenses/(\d+)/reissue$#',$path,$m)){ $data=json_decode(file_get_contents('php://input'),true) ?: []; if(empty($data['new_domain'])) json_response(['error'=>'new_domain_required'],422); $s=$db->prepare('SELECT id,domain FROM licenses WHERE id=? AND reseller_id=?');$s->execute([(int)$m[1],$key['reseller_id']]);$lic=$s->fetch();if(!$lic)json_response(['error'=>'license_not_found'],404);$q=$db->prepare('INSERT INTO reissue_requests(license_id,reseller_id,current_domain,new_domain,reason,status) VALUES(?,?,?,?,?,"pending")');$q->execute([$lic['id'],$key['reseller_id'],$lic['domain'],$data['new_domain'],$data['reason']??null]);$id=(int)$db->lastInsertId();telegram_notify("🔔 <b>NEW REISSUE REQUEST</b>\nReseller: ".e($key['reseller_name'])."\nLicense: ".e($lic['id'])."\nCurrent: ".e($lic['domain']??'-')."\nNew: ".e($data['new_domain'])."\nStatus: PENDING");json_response(['message'=>'reissue_request_created','request_id'=>$id],201); }
if($method==='GET' && preg_match('#/reissues/?$#',$path)){ $s=$db->prepare('SELECT id,license_id,current_domain,new_domain,reason,status,created_at,updated_at FROM reissue_requests WHERE reseller_id=? ORDER BY id DESC');$s->execute([$key['reseller_id']]);json_response(['data'=>$s->fetchAll()]); }
json_response(['error'=>'endpoint_not_found'],404);