<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$u = require_role(['owner','admin']);

$msg = null;
$error = null;

try {
    $db->exec("CREATE TABLE IF NOT EXISTS invoice_settings (id TINYINT UNSIGNED PRIMARY KEY,company_name VARCHAR(160) NOT NULL DEFAULT 'SkyNoc',tagline VARCHAR(255) NULL,logo_url VARCHAR(500) NULL,address TEXT NULL,email VARCHAR(190) NULL,phone VARCHAR(60) NULL,website VARCHAR(500) NULL,currency VARCHAR(10) NOT NULL DEFAULT 'USD',invoice_prefix VARCHAR(30) NOT NULL DEFAULT 'SN-',footer_text TEXT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO invoice_settings(id,company_name,currency,invoice_prefix) VALUES(1,'SkyNoc','USD','SN-')");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $company = trim((string)($_POST['company_name'] ?? ''));
        $tagline = trim((string)($_POST['tagline'] ?? '')) ?: null;
        $logo = trim((string)($_POST['logo_url'] ?? '')) ?: null;
        $address = trim((string)($_POST['address'] ?? '')) ?: null;
        $email = strtolower(trim((string)($_POST['email'] ?? ''))) ?: null;
        $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
        $website = trim((string)($_POST['website'] ?? '')) ?: null;
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
        $prefix = trim((string)($_POST['invoice_prefix'] ?? 'SN-'));
        $footer = trim((string)($_POST['footer_text'] ?? '')) ?: null;

        if ($company === '') throw new RuntimeException('Company name is required.');
        if (strlen($company) > 160) throw new RuntimeException('Company name is too long.');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid invoice email address.');
        if ($website !== null && !filter_var($website, FILTER_VALIDATE_URL)) throw new RuntimeException('Website must be a valid URL.');
        if ($logo !== null && !filter_var($logo, FILTER_VALIDATE_URL)) throw new RuntimeException('Logo URL must be a valid URL.');
        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) throw new RuntimeException('Currency must use 3-10 uppercase letters.');
        if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $prefix)) throw new RuntimeException('Invoice prefix contains invalid characters.');

        $db->prepare('UPDATE invoice_settings SET company_name=?,tagline=?,logo_url=?,address=?,email=?,phone=?,website=?,currency=?,invoice_prefix=?,footer_text=? WHERE id=1')
            ->execute([$company,$tagline,$logo,$address,$email,$phone,$website,$currency,$prefix,$footer]);
        audit('invoice_settings_updated','invoice_settings',1);
        $msg = 'Invoice settings saved successfully.';
    }

    $s = $db->query('SELECT * FROM invoice_settings WHERE id=1 LIMIT 1');
    $settings = $s->fetch() ?: ['company_name'=>'SkyNoc','tagline'=>'WHMCS License Services','logo_url'=>null,'address'=>null,'email'=>null,'phone'=>null,'website'=>null,'currency'=>'USD','invoice_prefix'=>'SN-','footer_text'=>'Thank you for using SkyNoc.'];
} catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'Invoice settings could not be saved.';
    if (!($e instanceof RuntimeException)) error_log('SkyNoc invoice settings error: '.$e->getMessage());
    $settings = $settings ?? ['company_name'=>'SkyNoc','tagline'=>'WHMCS License Services','logo_url'=>null,'address'=>null,'email'=>null,'phone'=>null,'website'=>null,'currency'=>'USD','invoice_prefix'=>'SN-','footer_text'=>'Thank you for using SkyNoc.'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice Settings — SkyNoc Admin</title>
<link rel="stylesheet" href="/admin-page-ui.css">
<style>.hero{background:linear-gradient(135deg,#101828,#172554,#3641f5);color:#fff;border-radius:20px;padding:25px;margin-bottom:18px}.hero h1{margin:7px 0 5px;font-size:28px}.hero p{margin:0;color:#dbe4ff;line-height:1.6}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:15px}.preview{background:#f8fafc;border:1px solid #e4e7ec;border-radius:14px;padding:22px}.invoice-preview{max-width:680px;margin:auto;background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:24px}.invoice-head{display:flex;justify-content:space-between;gap:20px;border-bottom:1px solid #e4e7ec;padding-bottom:15px}.invoice-logo{max-width:160px;max-height:55px;object-fit:contain}.muted{color:#667085;font-size:12px}.small{font-size:11px;color:#667085}@media(max-width:700px){.grid2{grid-template-columns:1fr}.invoice-head{flex-direction:column}}
</style>
</head>
<body>
<div class="top"><strong>SkyNoc <span style="opacity:.5">/</span> Invoice Settings</strong><span><?=e($u['name'])?> · <a href="/admin">Dashboard</a> · <a href="/invoice?id=1">Invoice Preview</a> · <a href="/logout">Logout</a></span></div>
<div class="wrap">
<div class="hero"><div style="font-size:11px;font-weight:800;letter-spacing:.08em;opacity:.8">DOCUMENT BRANDING</div><h1>Invoice Page Settings</h1><p>Control the company identity, logo, contact details, currency, invoice prefix and footer shown on reseller invoices.</p></div>
<?php if($msg): ?><div class="msg">✓ <?=e($msg)?></div><?php endif; ?>
<?php if($error): ?><div class="err">! <?=e($error)?></div><?php endif; ?>
<section class="card"><div class="section-head"><div class="section-title"><div class="section-icon">🧾</div><div><h2>Invoice Configuration</h2><p class="hint">These settings apply when an invoice is viewed through the invoice page.</p></div></div></div>
<form method="post"><?=csrf_field()?><div class="grid2">
<div class="field"><label>Company / Brand Name *</label><input name="company_name" value="<?=e($settings['company_name'])?>" required></div>
<div class="field"><label>Tagline</label><input name="tagline" value="<?=e($settings['tagline'] ?? '')?>" placeholder="WHMCS License Services"></div>
<div class="field"><label>Logo URL</label><input type="url" name="logo_url" value="<?=e($settings['logo_url'] ?? '')?>" placeholder="https://skynoc.net/logo.png"><div class="hint">Use a public HTTPS image URL.</div></div>
<div class="field"><label>Website URL</label><input type="url" name="website" value="<?=e($settings['website'] ?? '')?>" placeholder="https://SkyNoc.Net"></div>
<div class="field"><label>Invoice Email</label><input type="email" name="email" value="<?=e($settings['email'] ?? '')?>" placeholder="billing@skynoc.net"></div>
<div class="field"><label>Phone</label><input name="phone" value="<?=e($settings['phone'] ?? '')?>" placeholder="Support phone"></div>
<div class="field"><label>Currency Code</label><input name="currency" maxlength="10" value="<?=e($settings['currency'] ?? 'USD')?>" placeholder="USD"></div>
<div class="field"><label>Invoice Prefix</label><input name="invoice_prefix" maxlength="30" value="<?=e($settings['invoice_prefix'] ?? 'SN-')?>" placeholder="SN-"><div class="hint">New invoices use this prefix. Existing invoice numbers remain unchanged.</div></div>
<div class="field wide"><label>Business Address</label><textarea name="address" placeholder="Company address"><?=e($settings['address'] ?? '')?></textarea></div>
<div class="field wide"><label>Invoice Footer</label><textarea name="footer_text" placeholder="Thank you for using SkyNoc."><?=e($settings['footer_text'] ?? '')?></textarea></div>
</div><div class="actions"><button type="submit">Save Invoice Settings</button><a class="button-link" href="/admin/settings">← Settings</a></div></form></section>
<section class="card"><h2>Preview</h2><p class="hint">A simplified live preview of the invoice header and footer.</p><div class="preview"><div class="invoice-preview"><div class="invoice-head"><div><?php if(!empty($settings['logo_url'])): ?><img class="invoice-logo" src="<?=e($settings['logo_url'])?>" alt="Logo"><br><?php endif; ?><strong><?=e($settings['company_name'])?></strong><div class="muted"><?=e($settings['tagline'] ?? '')?></div><div class="small"><?=nl2br(e($settings['address'] ?? ''))?></div></div><div><strong><?=e(($settings['invoice_prefix'] ?? 'SN-').'202610-0000001')?></strong><div class="muted">PAID</div></div></div><p class="small"><?=e($settings['email'] ?? '')?><?=($settings['phone']??'')?' · '.e($settings['phone']):''?><?=($settings['website']??'')?' · '.e($settings['website']):''?></p><hr><p class="muted">Invoice items and reseller details appear here.</p><p class="small"><?=nl2br(e($settings['footer_text'] ?? ''))?></p></div></div></section>
<div class="page-footer">SkyNoc Admin · <a href="/admin">Dashboard</a> · <a href="/admin/invoice-settings">Invoice Settings</a></div>
</div></body></html>
