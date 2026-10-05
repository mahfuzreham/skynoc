<?php
declare(strict_types=1);

/** Dependency-free SMTP delivery for cPanel/shared hosting. */
function skynoc_smtp_settings(): array
{
    global $db;
    try { $row = $db->query('SELECT * FROM smtp_settings WHERE id=1 LIMIT 1')->fetch(); }
    catch (Throwable $e) { $row = null; }
    return $row ?: ['enabled'=>0,'host'=>'','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from_email'=>'','from_name'=>'SkyNoc'];
}

function skynoc_smtp_save(array $data): void
{
    global $db;
    $host=trim((string)($data['host']??'')); $port=max(1,min(65535,(int)($data['port']??587)));
    $encryption=strtolower(trim((string)($data['encryption']??'tls')));
    $username=trim((string)($data['username']??'')); $password=(string)($data['password']??'');
    $fromEmail=trim((string)($data['from_email']??'')); $fromName=trim((string)($data['from_name']??'SkyNoc'));
    if($host==='') throw new RuntimeException('SMTP host is required.');
    if(!in_array($encryption,['tls','ssl','none'],true)) throw new RuntimeException('Invalid SMTP encryption mode.');
    if($fromEmail===''||!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('A valid From Email is required.');
    if($fromName==='') $fromName='SkyNoc';
    $existing=skynoc_smtp_settings(); if($password==='') $password=(string)($existing['password']??'');
    $db->prepare('INSERT INTO smtp_settings (id,enabled,host,port,encryption,username,password,from_email,from_name) VALUES (1,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),host=VALUES(host),port=VALUES(port),encryption=VALUES(encryption),username=VALUES(username),password=VALUES(password),from_email=VALUES(from_email),from_name=VALUES(from_name)')->execute([!empty($data['enabled'])?1:0,$host,$port,$encryption,$username,$password,$fromEmail,$fromName]);
}

function skynoc_smtp_read_response($socket): string
{
    $response='';
    while(($line=fgets($socket,515))!==false){ $response.=$line; if(strlen($line)>=4&&$line[3]===' ') break; if(strlen($response)>20000) break; }
    return trim($response);
}
function skynoc_smtp_expect($socket,array $codes): string
{
    $response=skynoc_smtp_read_response($socket); $code=(int)substr($response,0,3);
    if(!in_array($code,$codes,true)) throw new RuntimeException('SMTP server rejected command: '.substr($response,0,500));
    return $response;
}
function skynoc_smtp_cmd($socket,string $command,array $codes): string
{
    if(fwrite($socket,$command."\r\n")===false) throw new RuntimeException('SMTP connection write failed.');
    return skynoc_smtp_expect($socket,$codes);
}
function skynoc_smtp_encode_header(string $value): string
{
    $value=trim(str_replace(["\r","\n"],'',$value)); if($value==='') return '';
    return preg_match('/[^\x20-\x7E]/',$value)?'=?UTF-8?B?'.base64_encode($value).'?=':$value;
}
function skynoc_send_email(string $to,string $subject,string $body): bool
{
    $cfg=skynoc_smtp_settings();
    if(!(int)$cfg['enabled']) return false;
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid recipient email.');
    if(!filter_var((string)$cfg['from_email'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From Email is invalid.');
    $host=trim((string)$cfg['host']); if($host==='') throw new RuntimeException('SMTP host is not configured.');
    $port=(int)$cfg['port']; $enc=(string)$cfg['encryption']; $transport=$enc==='ssl'?'ssl://'.$host:$host;
    $errno=0;$errstr=''; $socket=@stream_socket_client($transport.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT);
    if(!$socket) throw new RuntimeException('SMTP connection failed: '.($errstr?:'connection refused'));
    stream_set_timeout($socket,15);
    try{
        skynoc_smtp_expect($socket,[220]); $hello=gethostname()?:'skynoc.net'; skynoc_smtp_cmd($socket,'EHLO '.$hello,[250]);
        if($enc==='tls'){ skynoc_smtp_cmd($socket,'STARTTLS',[220]); if(@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true) throw new RuntimeException('SMTP STARTTLS negotiation failed.'); skynoc_smtp_cmd($socket,'EHLO '.$hello,[250]); }
        $username=(string)$cfg['username']; $password=(string)$cfg['password'];
        if($username!==''){
            try{
                skynoc_smtp_cmd($socket,'AUTH LOGIN',[334]); skynoc_smtp_cmd($socket,base64_encode($username),[334]); skynoc_smtp_cmd($socket,base64_encode($password),[235]);
            }catch(Throwable $loginError){
                try{ skynoc_smtp_cmd($socket,'AUTH PLAIN '.base64_encode("\0".$username."\0".$password),[235]); }
                catch(Throwable $plainError){ throw new RuntimeException('SMTP authentication failed.'); }
            }
        }
        skynoc_smtp_cmd($socket,'MAIL FROM:<'.trim((string)$cfg['from_email']).'>',[250]);
        skynoc_smtp_cmd($socket,'RCPT TO:<'.$to.'>',[250,251]); skynoc_smtp_cmd($socket,'DATA',[354]);
        $body=str_replace(["\r\n","\r"],"\n",$body); $body=preg_replace('/^\./m','..',$body)??$body;
        $headers=['Date: '.date(DATE_RFC2822),'From: '.(($n=skynoc_smtp_encode_header((string)$cfg['from_name']))!==''?$n.' ':'').'<'.trim((string)$cfg['from_email']).'>','To: <'.$to.'>','Subject: '.skynoc_smtp_encode_header($subject),'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: 8bit','X-Mailer: SkyNoc SMTP'];
        $message=implode("\r\n",$headers)."\r\n\r\n".str_replace("\n","\r\n",$body)."\r\n.";
        if(fwrite($socket,$message."\r\n")===false) throw new RuntimeException('SMTP message write failed.');
        skynoc_smtp_expect($socket,[250]); @fwrite($socket,"QUIT\r\n"); fclose($socket); return true;
    }catch(Throwable $e){ if(is_resource($socket)) fclose($socket); error_log('SkyNoc SMTP: '.$e->getMessage()); throw $e; }
}
