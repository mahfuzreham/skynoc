<?php
require __DIR__ . '/../../app/bootstrap.php';

$auth=$_SERVER['HTTP_AUTHORIZATION']??'';
if(!preg_match('/Bearer\s+(.+)/i',$auth,$m)) json_response(['error'=>'missing_api_key'],401);
$hash=hash('sha256',trim($m[1]));
$s=$db->prepare('SELECT ak.*,r.name reseller_name,r.email reseller_email,r.status reseller_status FROM api_keys ak JOIN resellers r ON r.id=ak.reseller_id WHERE ak.key_hash=? AND ak.status="active" LIMIT 1');
$s->execute([$hash]);$key=$s->fetch();
if(!$key||($key['expires_at']&&strtotime((string)$key['expires_at'])<time())) json_response(['error'=>'invalid_api_key'],401);
if($key['reseller_status']!=='active') json_response(['error'=>'reseller_not_active'],403);
if(!api_has_scope($key,'billing:notify')) json_response(['error'=>'insufficient_scope','required_scope'=>'billing:notify'],403);
$db->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([$key['id']]);

if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['error'=>'method_not_allowed'],405);
$data=json_decode(file_get_contents('php://input'),true)?:[];
$invoiceId=trim((string)($data['invoice_id']??''));
if($invoiceId==='') json_response(['error'=>'invoice_id_required'],422);
$invoiceNumber=trim((string)($data['invoice_number']??''))?:null;
$clientId=trim((string)($data['client_id']??''))?:null;
$clientName=trim((string)($data['client_name']??''))?:null;
$clientEmail=trim((string)($data['client_email']??''))?:null;
$total=(float)($data['total']??0);
$currency=trim((string)($data['currency']??''))?:null;
$gateway=trim((string)($data['gateway']??''))?:null;
$paidAt=trim((string)($data['paid_at']??''))?:null;
if($clientEmail!==null&&!filter_var($clientEmail,FILTER_VALIDATE_EMAIL))$clientEmail=null;

try{
    $db->beginTransaction();
    $q=$db->prepare('INSERT INTO whmcs_invoice_events(reseller_id,invoice_id,invoice_number,client_id,client_name,client_email,total,currency,gateway,paid_at,raw_json) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $q->execute([(int)$key['reseller_id'],$invoiceId,$invoiceNumber,$clientId,$clientName,$clientEmail,$total,$currency,$gateway,$paidAt,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $eventId=(int)$db->lastInsertId();
    $db->commit();
}catch(PDOException $e){
    if($db->inTransaction())$db->rollBack();
    if($e->getCode()==='23000') json_response(['message'=>'invoice_already_reported','invoice_id'=>$invoiceId],200);
    error_log('SkyNoc WHMCS invoice event: '.$e->getMessage());
    json_response(['error'=>'event_store_failed'],500);
}

$subject='WHMCS invoice paid — #'.($invoiceNumber?:$invoiceId);
$message="A WHMCS invoice has been paid in your connected reseller installation.\n\n".
    'Invoice: #'.($invoiceNumber?:$invoiceId)."\n".
    'Amount: '.number_format($total,2).' '.($currency?:'')."\n".
    'Payment method: '.($gateway?:'Unknown')."\n".
    'Client: '.($clientName?:'Unknown')."\n".
    'Client ID: '.($clientId?:'Unknown')."\n".
    'Client email: '.($clientEmail?:'Not provided')."\n".
    'Paid at: '.($paidAt?:date('Y-m-d H:i:s'))."\n".
    'Source invoice ID: '.$invoiceId;
notify_reseller((int)$key['reseller_id'],'whmcs_invoice_paid',$subject,$message);

json_response(['message'=>'invoice_paid_received','event_id'=>$eventId,'invoice_id'=>$invoiceId,'notified'=>true],202);
