<?php
declare(strict_types=1);

function cloudflare_config(): array {
    global $config;
    $cfg = $config['cloudflare'] ?? [];
    return [
        'enabled' => (bool)($cfg['enabled'] ?? false),
        'api_token' => trim((string)($cfg['api_token'] ?? '')),
        'account_id' => trim((string)($cfg['account_id'] ?? '')),
    ];
}

function cloudflare_request(string $method, string $path, ?array $body=null): array {
    $cfg = cloudflare_config();
    if (!$cfg['enabled'] || $cfg['api_token'] === '') throw new RuntimeException('Cloudflare integration is not configured.');
    $ch = curl_init('https://api.cloudflare.com/client/v4'.$path);
    $headers = ['Authorization: Bearer '.$cfg['api_token'], 'Content-Type: application/json'];
    $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch); $errno=curl_errno($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($errno) throw new RuntimeException('Cloudflare request failed: '.$err);
    $data=json_decode((string)$raw,true);
    if (!is_array($data) || empty($data['success'])) {
        $messages=[]; foreach (($data['errors'] ?? []) as $e) $messages[]=(string)($e['message'] ?? 'Cloudflare API error');
        throw new RuntimeException('Cloudflare API error: '.implode('; ',$messages ?: ['HTTP '.$status]));
    }
    return $data;
}

function cloudflare_sync_zones(): int {
    global $db;
    $cfg=cloudflare_config();
    $page=1; $saved=0;
    do {
        $query='?per_page=100&page='.$page;
        if ($cfg['account_id'] !== '') $query .= '&account.id='.rawurlencode($cfg['account_id']);
        $data=cloudflare_request('GET','/zones'.$query);
        $result=$data['result'] ?? [];
        foreach ($result as $zone) {
            $s=$db->prepare('INSERT INTO cloudflare_zones(zone_id,domain,status,sellable) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE domain=VALUES(domain),status=VALUES(status)');
            $s->execute([(string)$zone['id'],(string)$zone['name'],(string)($zone['status'] ?? 'active')]);
            $saved++;
        }
        $info=$data['result_info'] ?? [];
        $totalPages=(int)($info['total_pages'] ?? $page);
        $page++;
    } while ($page <= $totalPages);
    return $saved;
}

function cloudflare_create_dns_record(string $zoneId,string $type,string $name,string $content,bool $proxied=false,int $ttl=120): array {
    return cloudflare_request('POST','/zones/'.rawurlencode($zoneId).'/dns_records',[
        'type'=>$type,'name'=>$name,'content'=>$content,'ttl'=>$ttl,'proxied'=>$proxied
    ])['result'];
}

function cloudflare_delete_dns_record(string $zoneId,string $recordId): void {
    cloudflare_request('DELETE','/zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($recordId));
}
