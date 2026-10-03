<?php
require __DIR__ . '/../app/bootstrap.php';

$u = current_user();
if (!$u || $u['status'] !== 'active') {
    header('Location: https://SkyNoc.Net/login?hostname=1');
    exit;
}
if ($u['role'] !== 'reseller') { http_response_code(403); exit('Hostname service is available to resellers only.'); }

$s=$db->prepare('SELECT id,status FROM resellers WHERE user_id=? LIMIT 1');
$s->execute([$u['id']]); $reseller=$s->fetch();
if(!$reseller) exit('Reseller profile not found.');
$rid=(int)$reseller['id'];
$msg=null; $error=null;

function hostname_valid_fqdn(string $name,string $zone): bool {
    $name=strtolower(trim(rtrim($name,'.'))); $zone=strtolower(trim(rtrim($zone,'.')));
    if($name==='' || $name===$zone || !str_ends_with($name,'.'.$zone)) return false;
    if(strlen($name)>253 || preg_match('/[^a-z0-9.\-_]/',$name)) return false;
    foreach(explode('.',$name) as $label) if($label==='' || strlen($label)>63 || $label[0]==='-' || str_ends_with($label,'-')) return false;
    return true;
}

try {
    if($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf(); $action=$_POST['action'] ?? '';
        if($action==='sync') {
            $n=cloudflare_sync_zones(); $msg='Cloudflare zones synced: '.$n.'.';
        } elseif($action==='order') {
            if($reseller['status']!=='active') throw new RuntimeException('Your reseller account must be active before ordering a hostname.');
            $productId=(int)($_POST['product_id'] ?? 0); $zoneId=(int)($_POST['zone_id'] ?? 0);
            $hostname=strtolower(trim((string)($_POST['hostname'] ?? '')));
            $type=strtoupper(trim((string)($_POST['record_type'] ?? 'A')));
            $content=trim((string)($_POST['record_content'] ?? ''));
            $reference=trim((string)($_POST['payment_reference'] ?? ''));
            $p=$db->prepare('SELECT * FROM hostname_products WHERE id=? AND active=1 LIMIT 1'); $p->execute([$productId]); $product=$p->fetch();
            $z=$db->prepare('SELECT * FROM cloudflare_zones WHERE id=? AND sellable=1 AND status="active" LIMIT 1'); $z->execute([$zoneId]); $zone=$z->fetch();
            if(!$product || !$zone) throw new RuntimeException('Selected hostname product or domain is unavailable.');
            if((int)$product['record_limit']!==1) throw new RuntimeException('Hostname products currently support exactly 1 DNS record.');
            if(!hostname_valid_fqdn($hostname,(string)$zone['domain'])) throw new RuntimeException('Hostname must be a valid subdomain of '.e($zone['domain']).'.');
            if(!in_array($type,['A','AAAA','CNAME','TXT'],true)) throw new RuntimeException('Unsupported DNS record type.');
            if($content==='') throw new RuntimeException('DNS record content is required.');
            if(!$reference) throw new RuntimeException('Payment reference is required.');
            if($type==='A' && !filter_var($content,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new RuntimeException('Enter a valid IPv4 address.');
            if($type==='AAAA' && !filter_var($content,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) throw new RuntimeException('Enter a valid IPv6 address.');
            if($type==='CNAME' && !preg_match('/^[a-z0-9._-]+$/i',$content)) throw new RuntimeException('Invalid CNAME target.');
            if($type==='TXT' && strlen($content)>2048) throw new RuntimeException('TXT content is too long.');
            $q=$db->prepare("SELECT id FROM hostname_orders WHERE hostname=? AND status IN('pending_payment','pending_review','active') LIMIT 1"); $q->execute([$hostname]);
            if($q->fetchColumn()) throw new RuntimeException('This hostname is already in use.');
            $s=$db->prepare("INSERT INTO hostname_orders(reseller_id,product_id,zone_id,hostname,record_type,record_content,amount,currency,status,payment_reference,next_due_at) VALUES(?,?,?,?,?,?,?,?, 'pending_review', ?, DATE_ADD(NOW(),INTERVAL 1 MONTH))");
            $s->execute([$rid,$productId,$zoneId,$hostname,$type,$content,(float)$product['price'],'BDT',$reference]);
            $oid=(int)$db->lastInsertId();
            telegram_notify('🖥️ <b>NEW HOSTNAME ORDER</b>\n\nOrder: #'.$oid.'\nReseller: '.e($u['name']).' (#'.$rid.')\nHostname: <code>'.e($hostname).'</code>\nRecord: '.e($type).' → <code>'.e($content).'</code>\nAmount: ৳'.number_format((float)$product['price'],2).'\nPayment Ref: <code>'.e($reference).'</code>');
            $msg='Order #'.$oid.' submitted. Admin will verify the ৳10 payment and activate the hostname.';
        }
    }
} catch(Throwable $e) { $error=$e->getMessage(); }

$products=$db->query("SELECT * FROM hostname_products WHERE active=1 ORDER BY id ASC")->fetchAll();
$zones=$db->query("SELECT * FROM cloudflare_zones WHERE sellable=1 AND status='active' ORDER BY domain ASC")->fetchAll();
$q=$db->prepare('SELECT o.*,p.name product_name,z.domain FROM hostname_orders o JOIN hostname_products p ON p.id=o.product_id JOIN cloudflare_zones z ON z.id=o.zone_id WHERE o.reseller_id=? ORDER BY o.id DESC'); $q->execute([$rid]); $orders=$q->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hostname — SkyNoc</title><style>
body{margin:0;background:#f6f8fb;font-family:Arial,sans-serif;color:#18212f}.wrap{max-width:1050px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}.brand{font-size:25px;font-weight:800}.card{background:#fff;border:1px solid #e5e9ef;border-radius:14px;padding:20px;margin-bottom:18px;box-shadow:0 5px 20px #1720330a}h1{margin:0 0 8px;font-size:30px}h2{font-size:20px}.muted{color:#667085}.price{font-size:28px;font-weight:800;margin:12px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}label{display:block;font-weight:700;margin:10px 0 6px}input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #d8dee8;border-radius:9px;background:#fff}button{border:0;border-radius:9px;padding:11px 16px;font-weight:700;cursor:pointer;background:#111827;color:#fff}.notice{padding:12px;border-radius:9px;background:#edf8ef;margin-bottom:15px}.err{background:#fff0f0}.pill{display:inline-block;padding:5px 9px;border-radius:99px;background:#eef2f6;font-size:12px;font-weight:700}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:10px;border-bottom:1px solid #edf0f4;font-size:14px}@media(max-width:650px){.wrap{padding:14px}.top{align-items:flex-start;gap:10px;flex-direction:column}}
</style></head><body><div class="wrap">
<div class="top"><div><div class="brand">SkyNoc Hostname</div><div class="muted">hostname.skynoc.net</div></div><a href="https://SkyNoc.Net/reseller">Back to Reseller</a></div>
<div class="card"><h1>Hostname & DNS</h1><div class="muted">Cloudflare-powered hostname service. <b>৳10/month</b> with exactly <b>1 DNS record</b>.</div></div>
<?php if($msg): ?><div class="notice"><?=e($msg)?></div><?php endif; ?><?php if($error): ?><div class="notice err"><?=e($error)?></div><?php endif; ?>
<div class="card"><h2>Order Hostname</h2><?php if(!$products): ?><div class="muted">No hostname product is currently available.</div><?php elseif(!$zones): ?><div class="muted">No Cloudflare domain is currently available. Please try again later.</div><?php else: ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="order"><div class="grid"><div><label>Product</label><select name="product_id" required><?php foreach($products as $p): ?><option value="<?=$p['id']?>"><?=e($p['name'])?> — ৳<?=number_format((float)$p['price'],2)?>/month — 1 record</option><?php endforeach; ?></select></div><div><label>Cloudflare Domain</label><select name="zone_id" required><?php foreach($zones as $z): ?><option value="<?=$z['id']?>"><?=e($z['domain'])?></option><?php endforeach; ?></select></div><div><label>Full Hostname</label><input name="hostname" placeholder="server1.example.com" required></div><div><label>DNS Type</label><select name="record_type"><option>A</option><option>AAAA</option><option>CNAME</option><option>TXT</option></select></div><div><label>Record Content</label><input name="record_content" placeholder="1.2.3.4" required></div><div><label>bKash / Payment Reference</label><input name="payment_reference" placeholder="Transaction ID / reference" required></div></div><p class="muted">Payment: ৳10/month. Submit your payment reference; activation is completed after admin verification.</p><button type="submit">Place Order</button></form><?php endif; ?></div>
<div class="card"><h2>My Hostnames</h2><table class="table"><tr><th>Hostname</th><th>Record</th><th>Price</th><th>Status</th><th>Next Due</th></tr><?php foreach($orders as $o): ?><tr><td><b><?=e($o['hostname'])?></b><br><span class="muted"><?=e($o['domain'])?></span></td><td><?=e($o['record_type'])?> → <?=e($o['record_content'])?></td><td>৳<?=number_format((float)$o['amount'],2)?></td><td><span class="pill"><?=e($o['status'])?></span></td><td><?=e((string)$o['next_due_at'])?></td></tr><?php endforeach; ?><?php if(!$orders): ?><tr><td colspan="5" class="muted">No hostname orders yet.</td></tr><?php endif; ?></table></div>
</div></body></html>