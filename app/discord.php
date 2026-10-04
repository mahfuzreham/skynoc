<?php
declare(strict_types=1);

function skynoc_discord_webhook_url(): string
{
    global $db;
    try {
        $q=$db->query("SELECT value FROM settings WHERE name='discord_webhook_url' LIMIT 1");
        return trim((string)($q?$q->fetchColumn():''));
    } catch(Throwable $e) { return ''; }
}

function discord_notify(string $title,string $message,string $type='info'): bool
{
    $url=skynoc_discord_webhook_url();
    if($url==='') return false;
    $payload=['content'=>"**{$title}**\n{$message}",'allowed_mentions'=>['parse'=>[]]];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),CURLOPT_TIMEOUT=>10]);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $body=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $body!==false && $code>=200 && $code<300;
}
