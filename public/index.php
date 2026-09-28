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
<meta name="description" content="SkyNoc WHMCS license sales and reseller platform.">
<title>SkyNoc — WHMCS Licenses</title>
<style>
:root{--navy:#0b1730;--navy2:#10254a;--blue:#1769e0;--blue2:#0e56c7;--sky:#eef5ff;--ink:#14213d;--muted:#65738a;--line:#dfe6ef;--soft:#f7f9fc;--green:#159a69;--white:#fff}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.55;background:#fff}a{text-decoration:none;color:inherit}.container{width:min(1160px,92%);margin:auto}
.top{background:var(--navy);color:#c8d4e7;font-size:12px;padding:8px 0}.top .container{display:flex;justify-content:space-between;gap:20px}.top strong{color:#fff}
nav{height:72px;background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50}.navin{height:100%;display:flex;align-items:center;justify-content:space-between}.brand{display:flex;align-items:center;gap:11px;font-weight:900;font-size:22px;letter-spacing:-.8px}.brandmark{width:34px;height:34px;border-radius:10px;background:var(--blue);color:#fff;display:grid;place-items:center;font-weight:900;font-size:15px}.brand small{display:block;font-size:9px;letter-spacing:.5px;color:#7a8799;font-weight:700;margin-top:-2px}.links{display:flex;align-items:center;gap:25px;color:#536176;font-size:13px;font-weight:700}.links a:hover{color:var(--blue)}.login{border:1px solid #ccd6e4;border-radius:8px;padding:9px 14px;color:var(--ink)!important}.get{background:var(--blue);color:#fff!important;border-radius:8px;padding:10px 15px}.mobile{display:none}
.hero{background:linear-gradient(135deg,#f7faff 0%,#fff 60%,#f2f7ff 100%);padding:72px 0 76px;border-bottom:1px solid #edf1f6}.hero-grid{display:grid;grid-template-columns:1.04fr .96fr;gap:65px;align-items:center}.kicker{font-size:12px;color:#42658e;font-weight:900;letter-spacing:1.1px;text-transform:uppercase}.hero h1{font-size:clamp(40px,5vw,60px);line-height:1.05;letter-spacing:-2.6px;margin:13px 0 18px}.hero h1 em{font-style:normal;color:var(--blue)}.hero p{font-size:17px;color:var(--muted);max-width:640px;margin:0 0 27px}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:8px;padding:12px 18px;font-weight:800;font-size:13px;border:1px solid transparent;cursor:pointer}.primary{background:var(--blue);color:#fff}.primary:hover{background:var(--blue2)}.outline{background:#fff;border-color:#ccd6e4}.trust{display:flex;flex-wrap:wrap;gap:17px;margin-top:24px;font-size:12px;color:#56657a}.trust span:before{content:"✓";color:var(--green);font-weight:900;margin-right:6px}
.mock{background:#fff;border:1px solid #d8e1ed;border-radius:15px;box-shadow:0 24px 65px rgba(25,53,92,.13);overflow:hidden}.mockbar{height:46px;background:#f8fafc;border-bottom:1px solid #e5eaf1;display:flex;align-items:center;gap:6px;padding:0 15px}.mockbar i{width:7px;height:7px;border-radius:50%;background:#c8d2df}.mockbody{padding:22px}.mocktop{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.mocktitle b{font-size:17px}.mocktitle small{display:block;color:#8995a7;font-size:10px;margin-top:2px}.status{font-size:10px;font-weight:800;background:#e9f8f1;color:#16845c;border-radius:20px;padding:6px 9px}.metricgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.metric{border:1px solid #e5eaf1;border-radius:9px;padding:12px}.metric small{display:block;color:#8995a7;font-size:9px}.metric b{font-size:18px}.bar{height:9px;background:#edf2f7;border-radius:20px;margin-top:7px;overflow:hidden}.bar span{display:block;width:72%;height:100%;background:#4c8dea;border-radius:20px}.mocklist{margin-top:12px;border:1px solid #e5eaf1;border-radius:9px}.row{display:grid;grid-template-columns:1.2fr 1fr .6fr;padding:11px 12px;border-bottom:1px solid #edf1f5;font-size:10px}.row:last-child{border:0}.row b{font-size:10px}.row small{color:#8a96a8}.ok{color:#16845c;font-weight:800}.mocknote{margin-top:12px;padding:10px 12px;background:#f5f8fc;border-radius:8px;color:#65738a;font-size:10px}
.section{padding:74px 0}.section.soft{background:var(--soft)}.head{max-width:700px;margin:0 auto 35px;text-align:center}.head .label{color:#54729b;font-size:11px;font-weight:900;letter-spacing:1px;text-transform:uppercase}.head h2{font-size:34px;letter-spacing:-1.2px;margin:7px 0}.head p{color:var(--muted);font-size:14px;margin:0}
.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:17px}.plan{background:#fff;border:1px solid var(--line);border-radius:12px;padding:24px}.plan.pop{border:2px solid var(--blue);padding:23px;box-shadow:0 10px 30px rgba(23,105,224,.09)}.pill{display:inline-block;background:#eaf2ff;color:var(--blue);font-size:9px;font-weight:900;border-radius:20px;padding:5px 8px;margin-bottom:9px}.plan h3{font-size:20px;margin:0 0 5px}.plan .desc{font-size:12px;color:var(--muted);min-height:38px}.price{font-size:30px;font-weight:900;letter-spacing:-1px;margin:17px 0 2px}.price small{font-size:12px;font-weight:600;color:#78859a;letter-spacing:0}.plan ul{list-style:none;padding:0;margin:17px 0 21px}.plan li{font-size:12px;color:#536176;padding:7px 0;border-top:1px solid #eef2f6}.plan li:before{content:"✓";color:var(--green);font-weight:900;margin-right:7px}.full{width:100%}.reference{text-align:center;color:#78859a;font-size:11px;margin:24px auto 0}.reference a{color:var(--blue);font-weight:700}
.configure{background:var(--navy);padding:70px 0}.config-grid{display:grid;grid-template-columns:.75fr 1.25fr;gap:45px;align-items:center}.config-copy{color:#fff}.config-copy .label{font-size:11px;letter-spacing:1px;color:#8db7f5;font-weight:900}.config-copy h2{font-size:34px;line-height:1.12;letter-spacing:-1px;margin:9px 0 13px}.config-copy p{color:#aebed4;font-size:14px;max-width:440px}.points{margin-top:25px}.point{display:flex;gap:11px;margin:15px 0;color:#d8e2f0;font-size:12px}.point b{display:block;color:#fff;font-size:13px}.point span{width:27px;height:27px;border-radius:8px;background:#17365f;color:#83b5fa;display:grid;place-items:center;font-weight:900;flex:none}
.order{background:#fff;border-radius:14px;padding:26px;box-shadow:0 20px 55px rgba(0,0,0,.18)}.order h3{margin:0;font-size:20px}.order>p{font-size:12px;color:var(--muted);margin:4px 0 20px}.field{margin-bottom:13px}.field label{display:block;font-size:11px;font-weight:800;color:#3b4a61;margin-bottom:6px}.field input,.field select{width:100%;padding:11px 12px;border:1px solid #cfd9e6;border-radius:7px;background:#fff;color:var(--ink);font:inherit;font-size:13px;outline:none}.field input:focus,.field select:focus{border-color:#70a5ef;box-shadow:0 0 0 3px #e5f0ff}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.summary{display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--line);padding-top:14px;margin-top:3px;margin-bottom:14px}.summary small{display:block;color:#8a96a8;font-size:10px}.summary strong{font-size:23px}.note{font-size:10px!important;color:#8793a6;margin:10px 0 0!important;text-align:center}
.features{display:grid;grid-template-columns:repeat(4,1fr);gap:15px}.feature{border:1px solid var(--line);border-radius:11px;padding:21px;background:#fff}.num{font-size:10px;color:var(--blue);font-weight:900;margin-bottom:13px}.feature h3{font-size:15px;margin:0 0 6px}.feature p{font-size:12px;color:var(--muted);margin:0}
.faq{max-width:820px;margin:auto}.faq details{border-top:1px solid var(--line);padding:18px 0}.faq summary{font-weight:800;font-size:14px;cursor:pointer}.faq p{font-size:13px;color:var(--muted);margin:9px 0 0}
footer{background:#081225;color:#8d9bb0;padding:31px 0;font-size:11px}.foot{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}.foot a{color:#bdc9da}.legal{margin-top:13px;padding-top:13px;border-top:1px solid #1b2a42;color:#6f7d92;font-size:10px;line-height:1.7}
@media(max-width:900px){.hero-grid,.config-grid{grid-template-columns:1fr}.mock{max-width:650px;margin:auto}.plans{grid-template-columns:1fr}.features{grid-template-columns:1fr 1fr}.hero{padding-top:55px}.links a.hide{display:none}}
@media(max-width:600px){.top .container{display:block;text-align:center}.top .container span+span{display:none}.links a:not(.login):not(.get){display:none}.links{gap:6px}.mobile{display:block}.hero h1{letter-spacing:-1.8px}.hero p{font-size:15px}.actions .btn{width:100%}.features{grid-template-columns:1fr}.two{grid-template-columns:1fr}.order{padding:20px}.config-grid{gap:28px}.head h2,.config-copy h2{font-size:29px}}
</style>
</head>
<body>
<div class="top"><div class="container"><span><strong>SkyNoc</strong> — WHMCS License Reseller</span><span>Secure licensing • Account support • Reseller access</span></div></div>

<nav><div class="container navin">
<a class="brand" href="/"><span class="brandmark">SN</span><span>SkyNoc<small>WHMCS LICENSES</small></span></a>
<div class="links"><a href="#plans">Licenses</a><a href="#why" class="hide">Why SkyNoc</a><a href="#faq" class="hide">FAQ</a><a href="/api-docs" class="hide">API Docs</a><a href="/login" class="login">Login</a><a href="/reseller/register" class="get">Register as Reseller</a></div>
</div></nav>

<main>
<section class="hero"><div class="container hero-grid">
<div>
<div class="kicker">WHMCS license sales</div>
<h1>WHMCS licensing, <em>without the hassle.</em></h1>
<p>Choose a license that fits your business, send us your installation details and manage everything from one simple SkyNoc account.</p>
<div class="actions"><a class="btn primary" href="/reseller/register">Become a SkyNoc Reseller →</a><a class="btn outline" href="https://www.whmcs.com/members/aff.php?aff=42837" target="_blank" rel="noopener noreferrer">Buy Direct from WHMCS ↗</a></div>
<div class="trust"><span>Genuine license</span><span>Fast setup</span><span>Human support</span></div>
</div>
<div class="mock" aria-label="SkyNoc license dashboard preview">
<div class="mockbar"><i></i><i></i><i></i></div><div class="mockbody">
<div class="mocktop"><div class="mocktitle"><b>License overview</b><small>SkyNoc reseller account</small></div><span class="status">ACTIVE</span></div>
<div class="metricgrid"><div class="metric"><small>Plan</small><b>Professional</b></div><div class="metric"><small>Clients</small><b>342</b></div><div class="metric"><small>Renewal</small><b>30 days</b></div></div>
<div class="mocklist"><div class="row"><b>billing.example.com</b><small>WHMCS License</small><span class="ok">Active</span></div><div class="row"><b>support.example.com</b><small>Reissue request</small><span>Review</span></div><div class="row"><b>api.example.com</b><small>WHMCS License</small><span class="ok">Active</span></div></div>
<div class="mocknote">Your license details, reissue requests and account activity stay inside your SkyNoc portal.</div>
</div></div>
</div></section>

<section class="section" id="plans"><div class="container">
<div class="head"><div class="label">License plans</div><h2>Pick the plan your business needs</h2><p>Simple monthly reference pricing. Confirm current availability and final SkyNoc pricing before purchase.</p></div>
<div class="plans">
<div class="plan"><h3>Plus</h3><div class="desc">For smaller businesses and new WHMCS installations.</div><div class="price">$34.95 <small>/ month</small></div><ul><li>Self-hosted WHMCS</li><li>Up to 250 active clients</li><li>Standard support</li></ul><a class="btn outline full" href="/reseller/register">Register as Reseller</a></div>
<div class="plan pop"><span class="pill">MOST REQUESTED</span><h3>Professional</h3><div class="desc">A practical choice for growing hosting and service businesses.</div><div class="price">$54.95 <small>/ month</small></div><ul><li>Self-hosted WHMCS</li><li>Up to 500 active clients</li><li>Standard support</li></ul><a class="btn primary full" href="/reseller/register">Register as Reseller</a></div>
<div class="plan"><h3>Business</h3><div class="desc">Higher client limits for established operations.</div><div class="price">From $84.95 <small>/ month</small></div><ul><li>Self-hosted WHMCS</li><li>1,000+ active clients</li><li>Priority support options</li></ul><a class="btn outline full" href="/reseller/register">Register as Reseller</a></div>
</div>
<div class="reference">Want to buy directly? <a href="https://www.whmcs.com/members/aff.php?aff=42837" target="_blank" rel="noopener noreferrer">Buy Direct from WHMCS</a> · <a href="https://www.whmcs.com/pricing/" target="_blank" rel="noopener noreferrer">View WHMCS pricing</a></div>
</div></section>

<section class="section soft" id="why"><div class="container">
<div class="head"><div class="label">Why SkyNoc</div><h2>A straightforward way to manage licenses</h2><p>The portal is designed around real license operations rather than a complicated storefront.</p></div>
<div class="features"><div class="feature"><div class="num">01 / LICENSES</div><h3>Clear ownership</h3><p>Track domains, status, expiry and reseller assignment from one place.</p></div><div class="feature"><div class="num">02 / REISSUE</div><h3>Simple requests</h3><p>Submit a domain reissue request and follow its review status.</p></div><div class="feature"><div class="num">03 / API</div><h3>Built for billing</h3><p>Scoped API keys let resellers connect their own billing systems.</p></div><div class="feature"><div class="num">04 / ACCESS</div><h3>Private provider data</h3><p>Internal provider account details are kept away from reseller-facing pages.</p></div></div>
</div></section>

<section class="section" id="faq"><div class="container"><div class="head"><div class="label">FAQ</div><h2>Questions before you buy?</h2><p>Some common things customers ask about WHMCS licensing.</p></div>
<div class="faq"><details open><summary>Are the prices on this page final?</summary><p>No. They are public reference prices used to help compare plans. WHMCS pricing and SkyNoc reseller pricing can change, so verify the final amount before purchase.</p></details><details><summary>Can I buy directly from WHMCS?</summary><p>Yes. Use the direct WHMCS link on this page if you prefer to purchase directly from WHMCS.</p></details><details><summary>Can I request a license reissue?</summary><p>Yes. Reseller accounts can submit reissue requests through the portal. Requests are reviewed before completion.</p></details><details><summary>Is SkyNoc the official WHMCS website?</summary><p>No. SkyNoc is a separate license sales and reseller platform. WHMCS is a trademark of its respective owner. For official licensing terms, always verify information with WHMCS.</p></details></div>
</div></section>
</main>

<footer><div class="container foot"><span>© <?=date('Y')?> SkyNoc. All rights reserved.</span><span><a href="/login">Account</a> · <a href="/reseller">Reseller Portal</a> · <a href="/api-docs">API Docs</a></span><div class="legal">SkyNoc is an independent WHMCS license reseller/platform and is not the official WHMCS website. WHMCS is a trademark of its respective owner. Pricing shown on this page is for reference and should be verified before purchase.</div></div></footer>


</body>
</html>
