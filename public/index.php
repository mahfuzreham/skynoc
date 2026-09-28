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
<title>SkyNoc — WHMCS License Store</title>
<style>
:root{--ink:#111827;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc;--brand:#2563eb;--brand2:#1d4ed8;--white:#fff;--green:#047857}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#fff;color:var(--ink);line-height:1.55}a{text-decoration:none;color:inherit}
.container{width:min(1180px,92%);margin:auto}.top{background:#0f172a;color:#cbd5e1;font-size:13px;padding:9px 0}.top .container{display:flex;justify-content:space-between;gap:20px}
nav{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}nav .container{height:72px;display:flex;align-items:center;justify-content:space-between}.logo{font-weight:900;font-size:23px;letter-spacing:-.7px}.logo span{color:var(--brand)}.navlinks{display:flex;align-items:center;gap:24px;font-size:14px;color:#475569}.navlinks a:hover{color:var(--brand)}.navbtn{padding:10px 16px;border:1px solid var(--line);border-radius:9px;color:var(--ink)!important;font-weight:700}
.hero{background:linear-gradient(180deg,#f8fbff 0,#fff 100%);padding:78px 0 62px}.hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:55px;align-items:center}.eyebrow{display:inline-flex;align-items:center;gap:8px;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:800}.dot{width:7px;height:7px;border-radius:50%;background:#22c55e}.hero h1{font-size:clamp(39px,5vw,62px);line-height:1.04;letter-spacing:-2.5px;margin:18px 0}.hero h1 span{color:var(--brand)}.hero p{font-size:18px;color:var(--muted);max-width:690px;margin:0 0 28px}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;border-radius:9px;font-weight:800;border:1px solid transparent}.btn.primary{background:var(--brand);color:#fff}.btn.primary:hover{background:var(--brand2)}.btn.secondary{background:#fff;border-color:var(--line)}
.orderbox{background:#fff;border:1px solid var(--line);border-radius:18px;padding:25px;box-shadow:0 18px 55px rgba(15,23,42,.09)}.orderbox h2{margin:0 0 4px;font-size:21px}.sub{font-size:13px;color:var(--muted);margin:0 0 20px}.field{margin:0 0 14px}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#334155}.field input,.field select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font:inherit;outline:none}.field input:focus,.field select:focus{border-color:#60a5fa;box-shadow:0 0 0 3px #dbeafe}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.price{display:flex;align-items:end;justify-content:space-between;padding:15px 0;border-top:1px solid var(--line);margin-top:6px}.price strong{font-size:25px}.price small{color:var(--muted)}.note{font-size:11px;color:#64748b;margin:12px 0 0}.full{width:100%}
.section{padding:72px 0}.section.alt{background:var(--soft)}.heading{text-align:center;max-width:720px;margin:0 auto 38px}.heading h2{font-size:36px;letter-spacing:-1px;margin:0 0 8px}.heading p{color:var(--muted);margin:0}.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.plan{position:relative;background:#fff;border:1px solid var(--line);border-radius:15px;padding:25px}.plan.featured{border:2px solid var(--brand);padding:24px;box-shadow:0 12px 35px rgba(37,99,235,.1)}.badge{position:absolute;right:16px;top:16px;background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:10px;font-weight:900}.plan h3{margin:0 0 6px;font-size:21px}.limit{color:var(--muted);font-size:13px}.plan .amount{font-size:31px;font-weight:900;margin:20px 0 2px}.plan .amount small{font-size:13px;color:var(--muted);font-weight:600}.plan ul{padding:0;margin:20px 0 0;list-style:none}.plan li{padding:8px 0;border-top:1px solid #f1f5f9;font-size:13px;color:#475569}.plan li:before{content:"✓";color:var(--green);font-weight:900;margin-right:8px}
.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.info{border:1px solid var(--line);border-radius:13px;padding:22px;background:#fff}.icon{width:38px;height:38px;border-radius:10px;background:#eff6ff;color:#1d4ed8;display:grid;place-items:center;font-weight:900;margin-bottom:13px}.info h3{font-size:16px;margin:0 0 5px}.info p{font-size:13px;color:var(--muted);margin:0}
.faq{max-width:820px;margin:auto}.faq details{border-top:1px solid var(--line);padding:18px 0}.faq summary{cursor:pointer;font-weight:800}.faq p{color:var(--muted);font-size:14px;margin:10px 0 0}
.source{font-size:12px;color:#64748b;text-align:center;margin-top:24px}.source a{color:#2563eb}
footer{background:#0f172a;color:#94a3b8;padding:35px 0}footer .container{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;font-size:13px}
@media(max-width:900px){.hero-grid{grid-template-columns:1fr}.plans{grid-template-columns:1fr}.info-grid{grid-template-columns:1fr 1fr}.hero{padding-top:55px}.navlinks a.hide{display:none}}
@media(max-width:560px){.info-grid{grid-template-columns:1fr}.two{grid-template-columns:1fr}.navlinks{gap:8px}.navlinks a:not(.navbtn){display:none}.top .container{display:block;text-align:center}}
</style>
</head>
<body>
<div class="top"><div class="container"><span>WHMCS License Store &amp; Reseller Platform</span><span>Monthly licensing • Secure account access</span></div></div>

<nav><div class="container">
<a class="logo" href="/">Sky<span>Noc</span></a>
<div class="navlinks">
<a href="#plans">Licenses</a><a href="#features">Features</a><a href="#faq">FAQ</a><a href="/api-docs" class="hide">API Docs</a><a href="/login" class="navbtn">Login</a>
</div>
</div></nav>

<main>
<section class="hero"><div class="container hero-grid">
<div>
<div class="eyebrow"><span class="dot"></span> WHMCS LICENSE SALES</div>
<h1>Choose the right <span>WHMCS license</span> for your business.</h1>
<p>Browse license tiers, configure your deployment details and continue through your SkyNoc account. Built for hosting providers, agencies and WHMCS resellers.</p>
<div class="actions"><a class="btn primary" href="#plans">View License Plans</a><a class="btn secondary" href="#configure">Buy With SkyNoc</a><a class="btn secondary" href="https://www.whmcs.com/members/aff.php?aff=42837" target="_blank" rel="noopener noreferrer">Buy Direct from WHMCS</a></div>
</div>

<div class="orderbox" id="configure">
<h2>Configure your license</h2>
<p class="sub">Enter the details you plan to use for your WHMCS installation.</p>
<form onsubmit="saveConfig(event)">
<div class="field"><label>License plan</label><select id="plan" onchange="updatePrice()">
<option value="Plus" data-price="34.95">Plus — up to 250 clients</option>
<option value="Professional" data-price="54.95">Professional — up to 500 clients</option>
<option value="Business 1000" data-price="84.95">Business 1000 — up to 1,000 clients</option>
<option value="Business 2500" data-price="179.95">Business 2500 — up to 2,500 clients</option>
<option value="Business 5000" data-price="284.95">Business 5000 — up to 5,000 clients</option>
<option value="Business 10000" data-price="399.95">Business 10000 — up to 10,000 clients</option>
</select></div>
<div class="two">
<div class="field"><label>Your name</label><input id="name" type="text" autocomplete="name" placeholder="Full name"></div>
<div class="field"><label>Business / company</label><input id="company" type="text" autocomplete="organization" placeholder="Company name"></div>
</div>
<div class="field"><label>WHMCS installation domain</label><input id="domain" type="text" inputmode="url" placeholder="billing.example.com"></div>
<div class="field"><label>Account email</label><input id="email" type="email" autocomplete="email" placeholder="you@example.com"></div>
<div class="price"><div><small>Billing cycle</small><br><b>Monthly</b></div><div><small>Reference price</small><br><strong id="price">$34.95</strong></div></div>
<button class="btn primary full" type="submit">Continue to SkyNoc Account</button>
<p class="note">License configuration is saved only in this browser until you continue. Final availability and SkyNoc resale pricing may differ.</p>
</form>
</div>
</div></section>

<section class="section" id="plans"><div class="container">
<div class="heading"><h2>WHMCS license plans</h2><p>Current WHMCS self-hosted monthly tiers used as a public pricing reference. SkyNoc can apply its own reseller pricing and availability.</p></div>
<div class="plans">
<div class="plan"><h3>Plus</h3><div class="limit">Up to 250 active clients</div><div class="amount">$34.95 <small>/ month</small></div><ul><li>Self-hosted</li><li>Email support</li><li>No branding</li></ul><a class="btn secondary full" href="#configure" onclick="pick('Plus')">Configure Plus</a></div>
<div class="plan featured"><span class="badge">POPULAR</span><h3>Professional</h3><div class="limit">Up to 500 active clients</div><div class="amount">$54.95 <small>/ month</small></div><ul><li>Self-hosted</li><li>Email support</li><li>No branding</li></ul><a class="btn primary full" href="#configure" onclick="pick('Professional')">Configure Professional</a></div>
<div class="plan"><h3>Business</h3><div class="limit">1,000+ active clients</div><div class="amount">From $84.95 <small>/ month</small></div><ul><li>Self-hosted</li><li>Email &amp; live chat support</li><li>Priority support access</li></ul><a class="btn secondary full" href="#configure" onclick="pick('Business 1000')">Configure Business</a></div>
</div>
<p class="source">Want to purchase directly from WHMCS? <a href="https://www.whmcs.com/members/aff.php?aff=42837" target="_blank" rel="noopener noreferrer">Buy Direct from WHMCS</a> &nbsp;•&nbsp; Pricing reference: <a href="https://www.whmcs.com/pricing/" target="_blank" rel="noopener">WHMCS official pricing</a>. Prices can change; verify before purchase.</p>
</div></section>

<section class="section alt" id="features"><div class="container">
<div class="heading"><h2>Built around license operations</h2><p>SkyNoc separates reseller access from internal provider and license management.</p></div>
<div class="info-grid">
<div class="info"><div class="icon">01</div><h3>License assignment</h3><p>Track license keys, domains, status, expiry and reseller ownership.</p></div>
<div class="info"><div class="icon">02</div><h3>Reissue workflow</h3><p>Submit domain changes and follow the review and manual reissue process.</p></div>
<div class="info"><div class="icon">03</div><h3>Reseller API</h3><p>Connect your billing platform using scoped API keys and rate limits.</p></div>
<div class="info"><div class="icon">04</div><h3>Protected provider data</h3><p>Upstream account credentials are not exposed through the reseller portal.</p></div>
</div>
</div></section>

<section class="section" id="faq"><div class="container">
<div class="heading"><h2>Frequently asked questions</h2><p>Key licensing details for customers evaluating a WHMCS deployment.</p></div>
<div class="faq">
<details open><summary>Are WHMCS licenses monthly?</summary><p>WHMCS currently offers monthly licensing for the license tiers shown here. Confirm current terms before purchase.</p></details>
<details><summary>What determines the license tier?</summary><p>WHMCS uses active-client limits to distinguish the main self-hosted license tiers.</p></details>
<details><summary>Can I move my WHMCS installation?</summary><p>WHMCS documentation states that licenses can be moved; after relocation, the license may need to be reissued through the appropriate licensing account.</p></details>
<details><summary>Is SkyNoc the official WHMCS website?</summary><p>No. SkyNoc is a separate license sales and reseller platform. WHMCS is a trademark of its respective owner. Official pricing and licensing information should be verified on WHMCS directly.</p></details>
</div>
</div></section>
</main>

<footer><div class="container"><span>© <?=date('Y')?> SkyNoc. All rights reserved.</span><span><a href="/login">Account</a> &nbsp;•&nbsp; <a href="/reseller">Reseller Portal</a> &nbsp;•&nbsp; <a href="/api-docs">API</a></span></div></footer>

<script>
const plans={
"Plus":"34.95","Professional":"54.95","Business 1000":"84.95","Business 2500":"179.95","Business 5000":"284.95","Business 10000":"399.95"
};
function pick(name){document.getElementById('plan').value=name;updatePrice()}
function updatePrice(){const p=document.getElementById('plan').value;document.getElementById('price').textContent='$'+plans[p]}
function saveConfig(e){
 e.preventDefault();
 const data={plan:document.getElementById('plan').value,name:document.getElementById('name').value,company:document.getElementById('company').value,domain:document.getElementById('domain').value,email:document.getElementById('email').value,billing_cycle:'monthly'};
 if(!data.name||!data.domain||!data.email){alert('Please enter your name, WHMCS domain and account email.');return}
 localStorage.setItem('skynoc_license_config',JSON.stringify(data));
 window.location.href='/login';
}
</script>
</body>
</html>
