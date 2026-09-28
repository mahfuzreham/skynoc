<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    $u = current_user();
    redirect($u['role'] === 'reseller' ? '/reseller' : '/dashboard');
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SkyNoc — WHMCS License Management</title>
<style>
body{margin:0;font-family:Arial,sans-serif;background:#f5f7fa;color:#111827}
nav{padding:20px 7%;display:flex;justify-content:space-between;align-items:center;background:#111827;color:#fff}
nav a{color:#fff;text-decoration:none;margin-left:20px}
.hero{max-width:1100px;margin:80px auto;padding:0 24px;text-align:center}
.hero h1{font-size:48px;margin:0 0 18px}.hero p{font-size:19px;color:#4b5563;max-width:720px;margin:0 auto 30px;line-height:1.6}
.btn{display:inline-block;padding:13px 24px;border-radius:7px;background:#111827;color:#fff;text-decoration:none;margin:5px}
.btn.alt{background:#fff;color:#111827;border:1px solid #d1d5db}
.features{max-width:1100px;margin:30px auto 80px;padding:0 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px}
.card{background:#fff;padding:24px;border-radius:12px;box-shadow:0 2px 12px #0001}.card h3{margin-top:0}
footer{text-align:center;padding:30px;color:#6b7280}
</style>
</head>
<body>
<nav><b>SkyNoc</b><div><a href="/api-docs">API Docs</a><a href="/login">Login</a></div></nav>
<section class="hero">
<h1>SkyNoc License Management</h1>
<p>Manage WHMCS licenses, reseller assignments, reissue requests and API access from one secure platform.</p>
<a class="btn" href="/login">Login</a>
<a class="btn alt" href="/reseller">Reseller Portal</a>
</section>
<section class="features">
<div class="card"><h3>License Management</h3><p>Track license status, domain assignments and reseller ownership.</p></div>
<div class="card"><h3>Reseller Portal</h3><p>Resellers can view their own licenses and submit reissue requests.</p></div>
<div class="card"><h3>API Access</h3><p>Connect your own systems to SkyNoc through the reseller API.</p></div>
<div class="card"><h3>Secure Provider Data</h3><p>Upstream provider details remain internal and are never exposed to resellers.</p></div>
</section>
<footer>© <?=date('Y')?> SkyNoc. All rights reserved.</footer>
</body>
</html>
