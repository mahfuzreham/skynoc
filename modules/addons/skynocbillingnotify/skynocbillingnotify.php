<?php
/**
 * SkyNoc WHMCS Billing Notifications
 *
 * Install this addon in the reseller's WHMCS installation. It reports
 * paid WHMCS invoices to the reseller's SkyNoc account so SkyNoc can
 * deliver the event through the reseller's configured email/Discord/Telegram channels.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function skynocbillingnotify_config()
{
    return [
        'name' => 'SkyNoc Billing Notifications',
        'description' => 'Send paid WHMCS invoice events to the reseller\'s SkyNoc account.',
        'version' => '1.0.0',
        'author' => 'SkyNoc',
        'language' => 'english',
        'fields' => [
            'enabled' => [
                'FriendlyName' => 'Enable',
                'Type' => 'yesno',
                'Description' => 'Enable invoice-paid notifications.',
            ],
            'api_url' => [
                'FriendlyName' => 'SkyNoc API URL',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'https://SkyNoc.Net/api/v1',
                'Description' => 'Base API URL. Do not add /whmcs/invoice-paid.',
            ],
            'api_key' => [
                'FriendlyName' => 'SkyNoc API Key',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Paste a SkyNoc reseller API key with billing notification access.',
            ],
        ],
    ];
}

function skynocbillingnotify_activate()
{
    return [
        'status' => 'success',
        'description' => 'SkyNoc Billing Notifications activated. Configure the SkyNoc API URL and API key.',
    ];
}

function skynocbillingnotify_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'SkyNoc Billing Notifications deactivated. Existing settings were retained.',
    ];
}

function skynocbillingnotify_output($vars)
{
    $apiUrl = trim((string)($vars['api_url'] ?? 'https://SkyNoc.Net/api/v1'));
    $apiKey = trim((string)($vars['api_key'] ?? ''));
    echo '<div class="alert alert-info"><strong>SkyNoc Billing Notifications</strong><br>When a WHMCS invoice becomes paid, this addon sends the invoice event to your SkyNoc reseller account. Configure the fields above and save.</div>';
    echo '<p><strong>Endpoint:</strong> <code>'.htmlspecialchars(rtrim($apiUrl,'/').'/whmcs/invoice-paid',ENT_QUOTES,'UTF-8').'</code></p>';
    if ($apiKey === '') echo '<div class="alert alert-warning">SkyNoc API key is not configured yet.</div>';
}

function skynocbillingnotify_get_settings()
{
    $rows = Capsule::table('tbladdonmodules')
        ->where('module', 'skynocbillingnotify')
        ->pluck('value', 'setting');
    return [
        'enabled' => isset($rows['enabled']) && in_array(strtolower((string)$rows['enabled']), ['on','1','yes','true'], true),
        'api_url' => trim((string)($rows['api_url'] ?? 'https://SkyNoc.Net/api/v1')),
        'api_key' => trim((string)($rows['api_key'] ?? '')),
    ];
}

function skynocbillingnotify_post(array $payload): bool
{
    $cfg = skynocbillingnotify_get_settings();
    if (!$cfg['enabled'] || $cfg['api_key'] === '') {
        return false;
    }
    $base = rtrim($cfg['api_url'], '/');
    $url = $base . '/whmcs/invoice-paid';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $error !== '' || $http < 200 || $http >= 300) {
        logActivity('SkyNoc Billing Notifications: HTTP '.$http.' '.($error ?: 'remote API rejected the event.'));
        return false;
    }
    return true;
}

function skynocbillingnotify_invoice_paid($vars)
{
    try {
        $invoiceId = (int)($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) return;
        $cfg = skynocbillingnotify_get_settings();
        if (!$cfg['enabled'] || $cfg['api_key'] === '') return;

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) return;
        $client = Capsule::table('tblclients')->where('id', (int)$invoice->userid)->first();

        $payload = [
            'invoice_id' => (string)$invoiceId,
            'invoice_number' => (string)($invoice->invoicenum ?: $invoiceId),
            'client_id' => (string)($invoice->userid ?? ''),
            'client_name' => trim((string)(($client->firstname ?? '').' '.($client->lastname ?? ''))),
            'client_email' => (string)($client->email ?? ''),
            'total' => (float)$invoice->total,
            'currency' => (string)($invoice->currency ?? ''),
            'gateway' => (string)($invoice->paymentmethod ?? ''),
            'paid_at' => (string)($invoice->datepaid ?: date('Y-m-d H:i:s')),
            'whmcs_url' => rtrim((string)Capsule::table('tblconfiguration')->where('setting','SystemURL')->value('value'),'/').'/admin/invoices.php?action=edit&id='.$invoiceId,
        ];

        skynocbillingnotify_post($payload);
    } catch (Throwable $e) {
        logActivity('SkyNoc Billing Notifications error: '.$e->getMessage());
    }
}

add_hook('InvoicePaid', 10, 'skynocbillingnotify_invoice_paid');
