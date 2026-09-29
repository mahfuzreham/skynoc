<?php
/**
 * SkyNoc WHMCS License Provisioning Module
 *
 * Store the reseller's scoped SkyNoc API key in WHMCS module settings.
 * This module never stores or requests upstream WHMCS provider credentials.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function skynoclicense_MetaData()
{
    return [
        'DisplayName' => 'SkyNoc WHMCS License',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function skynoclicense_ConfigOptions()
{
    return [
        'package_id' => [
            'FriendlyName' => 'SkyNoc Package ID',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Package ID from SkyNoc reseller portal.',
        ],
        'api_key' => [
            'FriendlyName' => 'SkyNoc API Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Scoped reseller API key. Keep this private.',
        ],
        'api_url' => [
            'FriendlyName' => 'SkyNoc API URL',
            'Type' => 'text',
            'Size' => '60',
            'Default' => 'https://SkyNoc.Net/api/v1',
        ],
    ];
}

function skynoclicense_call($params, $method, $endpoint, $body = null)
{
    $base = rtrim((string)($params['configoption3'] ?: 'https://SkyNoc.Net/api/v1'), '/');
    $apiKey = trim((string)($params['configoption2'] ?? ''));

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'SkyNoc API key is not configured.'];
    }

    $ch = curl_init($base . '/' . ltrim($endpoint, '/'));
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        return ['ok' => false, 'error' => 'SkyNoc API connection failed.'];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'SkyNoc returned an invalid response.'];
    }

    if ($http < 200 || $http >= 300) {
        return ['ok' => false, 'error' => (string)($data['error'] ?? $data['message'] ?? 'SkyNoc API request failed.'), 'status' => $http, 'data' => $data];
    }

    return ['ok' => true, 'data' => $data, 'status' => $http];
}

function skynoclicense_CreateAccount($params)
{
    $packageId = (int)($params['configoption1'] ?? 0);
    $domain = trim((string)($params['domain'] ?? ''));

    if ($packageId <= 0) {
        return 'SkyNoc Package ID is not configured.';
    }
    if ($domain === '') {
        return 'WHMCS domain is required.';
    }

    $result = skynoclicense_call($params, 'POST', 'orders', [
        'package_id' => $packageId,
        'domain' => $domain,
        'external_ref' => 'whmcs_service_' . (int)($params['serviceid'] ?? 0),
    ]);

    if (!$result['ok']) {
        return $result['error'];
    }

    $orderId = $result['data']['order_id'] ?? null;
    return 'success';
}

function skynoclicense_find_license($params)
{
    $ref='whmcs_service_'.(int)($params['serviceid']??0);
    $result=skynoclicense_call($params,'GET','orders');
    if(!$result['ok']) return $result;
    foreach(($result['data']['data']??[]) as $o) if(($o['external_ref']??'')===$ref && !empty($o['license_id'])) return ['ok'=>true,'license_id'=>(int)$o['license_id']];
    return ['ok'=>false,'error'=>'SkyNoc license is not assigned to this WHMCS service yet.'];
}

function skynoclicense_TerminateAccount($params)
{
    $l=skynoclicense_find_license($params); if(!$l['ok']) return $l['error'];
    $r=skynoclicense_call($params,'POST','licenses/'.$l['license_id'].'/terminate'); return $r['ok']?'success':$r['error'];
}

function skynoclicense_SuspendAccount($params)
{
    $l=skynoclicense_find_license($params); if(!$l['ok']) return $l['error'];
    $r=skynoclicense_call($params,'POST','licenses/'.$l['license_id'].'/suspend'); return $r['ok']?'success':$r['error'];
}

function skynoclicense_UnsuspendAccount($params)
{
    $l=skynoclicense_find_license($params); if(!$l['ok']) return $l['error'];
    $r=skynoclicense_call($params,'POST','licenses/'.$l['license_id'].'/unsuspend'); return $r['ok']?'success':$r['error'];
}

function skynoclicense_ChangePackage($params)
{
    $l=skynoclicense_find_license($params); if(!$l['ok']) return $l['error'];
    $packageId=(int)($params['configoption1']??0);
    if($packageId<=0) return 'SkyNoc Package ID is not configured.';
    $r=skynoclicense_call($params,'POST','licenses/'.$l['license_id'].'/package',['package_id'=>$packageId]);
    return $r['ok']?'success':$r['error'];
}

function skynoclicense_TestConnection($params)
{
    $result = skynoclicense_call($params, 'GET', 'packages');
    if (!$result['ok']) {
        return $result['error'];
    }
    return 'success';
}
