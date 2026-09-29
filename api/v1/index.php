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

if ($method === 'GET' && preg_match('#/packages/?$#',$path)) {
    if (!api_has_scope($key,'packages:read')) json_response(['error'=>'insufficient_scope'],403);
    $s=$db->query('SELECT id,name,slug,description,price,client_limit,billing_period FROM packages WHERE active=1 ORDER BY sort_order,id');
    $packages=$s->fetchAll(); $lv=reseller_level((int)$key['reseller_id']); foreach($packages as &$pkg){ $pkg['base_price']=(float)$pkg['price']; $pkg['discount_percent']=reseller_level_discount($lv['assigned_level']); $pkg['price']=reseller_package_price((float)$pkg['price'],$lv['assigned_level']); } unset($pkg); json_response(['data'=>$packages,'reseller_level'=>$lv['assigned_level']]);
}

if ($method === 'GET' && preg_match('#/orders/?$#',$path)) {
    if (!api_has_scope($key,'orders:read')) json_response(['error'=>'insufficient_scope'],403);
    $s=$db->prepare('SELECT o.id,o.package_id,p.name package_name,o.license_id,o.domain,o.amount,o.status,o.source,o.external_ref,o.created_at,o.updated_at,o.completed_at FROM orders o JOIN packages p ON p.id=o.package_id WHERE o.reseller_id=? ORDER BY o.id DESC');
    $s->execute([$key['reseller_id']]);
    json_response(['data'=>$s->fetchAll()]);
}

if ($method === 'POST' && preg_match('#/orders/?$#',$path)) {
    if (!api_has_scope($key,'orders:create')) json_response(['error'=>'insufficient_scope'],403);
    $data=json_decode(file_get_contents('php://input'),true) ?: [];
    $packageId=(int)($data['package_id'] ?? 0);
    $domain=trim((string)($data['domain'] ?? ''));
    $externalRef=trim((string)($data['external_ref'] ?? '')) ?: null;
    if($packageId<=0) json_response(['error'=>'package_id_required'],422);
    if($domain==='') json_response(['error'=>'domain_required'],422);
    try {
        $db->beginTransaction();
        $q=$db->prepare('SELECT id,name,price,active FROM packages WHERE id=? AND active=1 FOR UPDATE'); $q->execute([$packageId]); $package=$q->fetch();
        if(!$package) throw new RuntimeException('package_not_found');
        $q=$db->prepare('SELECT status FROM resellers WHERE id=? FOR UPDATE'); $q->execute([$key['reseller_id']]); $rs=$q->fetch();
        if(!$rs || $rs['status']!=='active') throw new RuntimeException('reseller_not_active');
        if($externalRef!==null){
            $q=$db->prepare('SELECT id,amount,status FROM orders WHERE reseller_id=? AND external_ref=? LIMIT 1'); $q->execute([$key['reseller_id'],$externalRef]); $existing=$q->fetch();
            if($existing){ $db->commit(); json_response(['message'=>'order_already_exists','order_id'=>(int)$existing['id'],'amount'=>(float)$existing['amount'],'status'=>$existing['status']],200); }
        }
        $q=$db->prepare('INSERT INTO orders(reseller_id,package_id,domain,amount,status,source,external_ref) VALUES(?,?,?,?,"pending","api",?)');
        $lv=reseller_level((int)$key['reseller_id']); $price=reseller_package_price((float)$package['price'],$lv['assigned_level']); $q->execute([$key['reseller_id'],$packageId,$domain,$price,$externalRef]); $orderId=(int)$db->lastInsertId();
        wallet_debit((int)$key['reseller_id'],$price,'purchase','ORDER-'.$orderId,'API package purchase: '.$package['name'],$orderId,null);
        $db->commit();
        telegram_notify("🛒 <b>NEW API ORDER</b>\\nReseller: ".e($key['reseller_name'])."\\nOrder: ".$orderId."\\nPackage: ".e($package['name'])."\\nAmount: $".number_format($price,2)."\\nDomain: ".e($domain));
        json_response(['message'=>'order_created','order_id'=>$orderId,'amount'=>$price,'status'=>'pending'],201);
    } catch (Throwable $e) {
        if($db->inTransaction()) $db->rollBack();
        $known=['package_not_found','reseller_not_active','Insufficient wallet balance.'];
        $msg=$e->getMessage();
        if($msg==='Insufficient wallet balance.') json_response(['error'=>'insufficient_balance'],422);
        if(in_array($msg,$known,true)) json_response(['error'=>$msg],422);
        error_log('SkyNoc API order error: '.$msg);
        json_response(['error'=>'order_failed'],500);
    }
}

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

if ($method === 'POST' && preg_match('#/licenses/(\\d+)/(suspend|unsuspend|terminate)$#',$path,$m)) {
    if (!api_has_scope($key,'licenses:manage')) json_response(['error'=>'insufficient_scope'],403);
    $id=(int)$m[1]; $action=$m[2];
    $s=$db->prepare('SELECT id,license_key,domain,status FROM licenses WHERE id=? AND reseller_id=? LIMIT 1');
    $s->execute([$id,$key['reseller_id']]); $lic=$s->fetch();
    if(!$lic) json_response(['error'=>'license_not_found'],404);
    $target=$action==='suspend'?'suspended':($action==='unsuspend'?'active':'cancelled');
    if($action==='suspend' && $lic['status']==='cancelled') json_response(['error'=>'license_cancelled'],422);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE licenses SET status=? WHERE id=? AND reseller_id=?')->execute([$target,$id,$key['reseller_id']]);
        $db->prepare('INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)')->execute([$id,$action,$lic['domain'],$lic['domain'],'API lifecycle action']);
        $db->commit();
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    notify_reseller((int)$key['reseller_id'],'license','License status updated','License '.$lic['license_key'].' is now '.$target.'.');
    json_response(['message'=>'license_updated','license_id'=>$id,'status'=>$target]);
}

if ($method === 'POST' && preg_match('#/licenses/(\\d+)/domain$#',$path,$m)) {
    if (!api_has_scope($key,'licenses:manage')) json_response(['error'=>'insufficient_scope'],403);
    $data=json_decode(file_get_contents('php://input'),true) ?: [];
    $domain=trim((string)($data['domain']??'')); if($domain==='') json_response(['error'=>'domain_required'],422);
    $s=$db->prepare('SELECT id,license_key,domain,status FROM licenses WHERE id=? AND reseller_id=? LIMIT 1');$s->execute([(int)$m[1],$key['reseller_id']]);$lic=$s->fetch();
    if(!$lic) json_response(['error'=>'license_not_found'],404);
    $db->beginTransaction();
    try{$db->prepare('UPDATE licenses SET domain=? WHERE id=? AND reseller_id=?')->execute([$domain,$lic['id'],$key['reseller_id']]);$db->prepare('INSERT INTO license_history(license_id,action,old_domain,new_domain,notes) VALUES(?,?,?,?,?)')->execute([$lic['id'],'domain_changed',$lic['domain'],$domain,'API domain change']);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    json_response(['message'=>'domain_updated','license_id'=>(int)$lic['id'],'domain'=>$domain]);
}

if ($method === 'GET' && preg_match('#/branding/?$#',$path)) {
    if (!api_has_scope($key,'licenses:read')) json_response(['error'=>'insufficient_scope'],403);
    $s=$db->prepare('SELECT company_name,logo_url,support_email,website_url,brand_color,custom_domain,enabled FROM white_label_settings WHERE reseller_id=? LIMIT 1');
    $s->execute([$key['reseller_id']]); $branding=$s->fetch()?:['enabled'=>0];
    json_response(['data'=>$branding]);
}

json_response(['error'=>'endpoint_not_found'],404);
