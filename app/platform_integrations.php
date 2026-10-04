<?php
declare(strict_types=1);

function skynoc_platform_integrations_migrate(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS smtp_settings (
        id TINYINT UNSIGNED PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        host VARCHAR(255) NOT NULL DEFAULT '',
        port INT UNSIGNED NOT NULL DEFAULT 587,
        encryption ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',
        username VARCHAR(255) NULL,
        password TEXT NULL,
        from_email VARCHAR(190) NULL,
        from_name VARCHAR(190) NOT NULL DEFAULT 'SkyNoc',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO smtp_settings(id,enabled,host,port,encryption,username,password,from_email,from_name) VALUES(1,0,'',587,'tls',NULL,NULL,'','SkyNoc')");

    $db->exec("CREATE TABLE IF NOT EXISTS whmcs_invoice_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reseller_id BIGINT UNSIGNED NOT NULL,
        invoice_id VARCHAR(100) NOT NULL,
        invoice_number VARCHAR(100) NULL,
        client_id VARCHAR(100) NULL,
        client_name VARCHAR(190) NULL,
        client_email VARCHAR(190) NULL,
        total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        currency VARCHAR(20) NULL,
        gateway VARCHAR(100) NULL,
        paid_at DATETIME NULL,
        raw_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_whmcs_invoice_event(reseller_id,invoice_id),
        INDEX idx_whmcs_invoice_reseller(reseller_id,created_at),
        FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $db->exec("ALTER TABLE reseller_notification_settings ADD COLUMN custom_email VARCHAR(190) NULL");
    } catch (PDOException $e) {
        if (!str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e;
    }
}

function skynoc_smtp_settings(): array
{
    global $db;
    $s=$db->query('SELECT * FROM smtp_settings WHERE id=1 LIMIT 1');
    return $s->fetch() ?: ['id'=>1,'enabled'=>0,'host'=>'','port'=>587,'encryption'=>'tls','username'=>null,'password'=>null,'from_email'=>'','from_name'=>'SkyNoc'];
}

function skynoc_smtp_save(array $data): void
{
    global $db;
    $existing=skynoc_smtp_settings();
    $password=trim((string)($data['password']??''));
    if($password==='') $password=(string)($existing['password']??'');
    $encryption=(string)($data['encryption']??'tls');
    if(!in_array($encryption,['none','ssl','tls'],true)) $encryption='tls';
    $host=trim((string)($data['host']??''));
    $port=max(1,min(65535,(int)($data['port']??587)));
    $from=trim((string)($data['from_email']??''));
    if($host!=='' && $from!=='' && !filter_var($from,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('SMTP From email is invalid.');
    $db->prepare('INSERT INTO smtp_settings(id,enabled,host,port,encryption,username,password,from_email,from_name) VALUES(1,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),host=VALUES(host),port=VALUES(port),encryption=VALUES(encryption),username=VALUES(username),password=VALUES(password),from_email=VALUES(from_email),from_name=VALUES(from_name)')
        ->execute([!empty($data['enabled'])?1:0,$host,$port,$encryption,trim((string)($data['username']??''))?:null,$password?:null,$from?:null,trim((string)($data['from_name']??'SkyNoc'))?:'SkyNoc']);
}

function skynoc_smtp_readline($fp): string
{
    $line=fgets($fp,515);
    if($line===false) throw new RuntimeException('SMTP connection closed unexpectedly.');
    return trim($line);
}

function skynoc_smtp_command($fp,string $command,array $accepted): void
{
    if($command!=='') fwrite($fp,$command."\r\n");
    $code=(int)substr(skynoc_smtp_readline($fp),0,3);
    if(!in_array($code,$accepted,true)) throw new RuntimeException('SMTP server rejected a command.');
}

function skynoc_smtp_send(string $to,string $subject,string $body): bool
{
    $cfg=skynoc_smtp_settings();
    if(!(int)$cfg['enabled'] || trim((string)$cfg['host'])==='' || trim($to)==='') return false;
    $host=(string)$cfg['host']; $port=(int)$cfg['port']; $encryption=(string)$cfg['encryption'];
    $target=$encryption==='ssl'?'ssl://'.$host:$host;
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
    $fp=@stream_socket_client($target.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$context);
    if(!$fp) throw new RuntimeException('SMTP connection failed.');
    stream_set_timeout($fp,15);
    try {
        skynoc_smtp_command($fp,'',[220]);
        skynoc_smtp_command($fp,'EHLO '.parse_url('https://'.preg_replace('/[^A-Za-z0-9.-]/','',$host),PHP_URL_HOST),[250]);
        if($encryption==='tls'){
            skynoc_smtp_command($fp,'STARTTLS',[220]);
            if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS negotiation failed.');
            skynoc_smtp_command($fp,'EHLO '.preg_replace('/[^A-Za-z0-9.-]/','',$host),[250]);
        }
        $username=trim((string)($cfg['username']??'')); $password=(string)($cfg['password']??'');
        if($username!==''){
            skynoc_smtp_command($fp,'AUTH LOGIN',[334]);
            skynoc_smtp_command($fp,base64_encode($username),[334]);
            skynoc_smtp_command($fp,base64_encode($password),[235]);
        }
        $from=trim((string)($cfg['from_email']??''));
        if($from==='') $from=$username;
        if(!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($to,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid SMTP email address.');
        skynoc_smtp_command($fp,'MAIL FROM:<'.$from.'>',[250]);
        skynoc_smtp_command($fp,'RCPT TO:<'.$to.'>',[250,251]);
        skynoc_smtp_command($fp,'DATA',[354]);
        $fromName=str_replace(["\r","\n"],' ',(string)$cfg['from_name']);
        $safeSubject=str_replace(["\r","\n"],' ', $subject);
        $message="From: ".($fromName!==''?$fromName.' ':'').'<'.$from.'>\r\nTo: <'.$to.">\r\nSubject: ".mb_encode_mimeheader($safeSubject,'UTF-8')."\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n".str_replace("\n","\r\n",str_replace("\r\n","\n",$body))."\r\n.";
        fwrite($fp,$message."\r\n");
        skynoc_smtp_command($fp,'',[250]);
        skynoc_smtp_command($fp,'QUIT',[221]);
        fclose($fp); return true;
    } catch(Throwable $e){ fclose($fp); throw $e; }
}

function skynoc_send_email(string $to,string $subject,string $body): bool
{
    try { if(skynoc_smtp_send($to,$subject,$body)) return true; } catch(Throwable $e){ error_log('SkyNoc SMTP: '.$e->getMessage()); }
    return false;
}
