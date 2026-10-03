<?php
require __DIR__ . '/../app/bootstrap.php';
$u=require_role(['owner','admin']);
$msg=null; $error=null;
try {
    if($_SERVER['REQUEST_METHOD']==='POST') {
        verify_csrf(); $action=$_POST['action'] ?? '';
        if($action==='sync') { $n=cloudflare_sync_zones(); $msg='Cloudflare zones synced: '.$n.'.'; }
        elseif($action==='zone_toggle') {
            $id=(int)$_POST['id']; $db->prepare('UPDATE cloudflare_zones SET sellable=IF(sellable=1,0,1) WHERE id=?')->execute([$id]); $msg='Domain availability updated.';
        } elseif($action==='approve') {
            $id=(int)$_POST['id'];
            $db->beginTransaction();
            $q=$db->prepare("SELECT o.*,z.zone_id AS cf_zone_id,z.domain FROM hostname_orders o JOIN cloudflare_zones z ON z.id=o.zone_id WHERE o.id=? AND o.status='pending_review' FOR UPDATE"); $q->execute([$id]); $o=$q->fetch();
            if(!$o) throw new RuntimeException('Order is no longer pending review.');
            $record=cloudflare_create_dns_record((string)$o['cf_zone_id'],(string)$o['record_type'],(string)$o['hostname'],(string)$o['record_content']);
            $db->prepare("UPDATE hostname_orders SET status='active',cloudflare_record_id=?,admin_note=?,next_due_at=DATE_ADD(NOW(),INTERVAL 1 MONTH) WHERE id=?")->execute([(string)$record['id'],'Payment verified and DNS record created.',$id]);
            $db->commit();
            notify_reseller((int)$o['reseller_id'],'hostname','Hostname activated','Your hostname '.(string)$o['hostname'].' is now active.');
            $msg='Hostname order #'.$id.' approved and Cloudflare DNS record created.';
        } elseif($action==='reject') {
            $id=(int)$_POST['id']; $db->prepare("UPDATE hostname_orders SET status='rejected',admin_note=? WHERE id=? AND status='pending_review'")->execute([trim((string)($_POST['note'] ?? 'Payment was not verified.')),$id]); $msg='Order rejected.';
        }
    }
} catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); $error=$e->getMessage(); }
$zones=$db->query('SELECT * FROM cloudflare_zones ORDER BY domain')->fetchAll();
$products=$db->query('SELECT * FROM hostname_products ORDER BY id')->fetchAll();
$orders=$db->query("SELECT o.*,u.name reseller_name,u.email,z.domain FROM hostname_orders o JOIN resellers r ON r.id=o.reseller_id JOIN users u ON u.id=r.user_id JOIN cloudflare_zones z ON z.id=o.zone_id ORDER BY o.id DESC LIMIT 100")->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hostname Admin — SkyNoc</title><style>body{font-family:Arial,sans-serif;background:#f6f8fb;color:#18212f;margin:0}.wrap{max-width:1200px;margin:auto;padding:24px}.top{display:flex;justify-content:space-between;align-items:center}.card{background:#fff;border:1px solid #e3e8ef;border-radius:14px;padding:20px;margin:18px 0;overflow:auto}h1{margin:0}.notice{padding:12px;border-radius:9px;background:#edf8ef;margin-top:15px}.err{background:#fff0f0}button{background:#111827;color:#fff;border:0;padding:9px 13px;border-radius:8px;font-weight:700}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:10px;border-bottom:1px solid #edf0f4;text-align:left;white-space:nowrap;font-size:14px}.on{color:#087443}.off{color:#9b1c1c}.muted{color:#667085}textarea{padding:8px;border:1px solid #d8dee8;border-radius:7px}</style></head><body><div class="wrap"><div class="top"><div><h1>Hostname Service</h1><div class="muted">Cloudflare DNS management</div></div><a href="/admin">Back to Admin</a></div><?php if($msg):?><div class="notice"><?=e($msg)?></div><?php endif;?><?php if($error):?><div class="notice err"><?=e($error)?></div><?php endif;?>
<div class="card"><h2>Cloudflare Domains</h2><p class="muted">Cloudflare API credentials are read from config/config.local.php and are never shown here.</p><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="sync"><button>Sync Cloudflare Zones</button></form><table class="table"><tr><th>Domain</th><th>Zone ID</th><th>Status</th><th>Sellable</th><th>Action</th></tr><?php foreach($zones as $z):?><tr><td><b><?=e($z['domain'])?></b></td><td><code><?=e($z['zone_id'])?></code></td><td><?=e($z['status'])?></td><td class="<?=((int)$z['sellable']?'on':'off')?>"><?=((int)$z['sellable']?'Available':'Hidden')?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="zone_toggle"><input type="hidden" name="id" value="<?=$z['id']?>"><button><?=((int)$z['sellable']?'Hide':'Sell')?></button></form></td></tr><?php endforeach;?></table></div>
<div class="card"><h2>Hostname Products</h2><table class="table"><tr><th>Product</th><th>Price</th><th>Billing</th><th>Record Limit</th><th>Status</th></tr><?php foreach($products as $p):?><tr><td><?=e($p['name'])?></td><td>৳<?=number_format((float)$p['price'],2)?> <?=e($p['currency'])?></td><td><?=e($p['billing_period'])?></td><td><?=e((string)$p['record_limit'])?></td><td><?=((int)$p['active']?'Active':'Disabled')?></td></tr><?php endforeach;?></table><p class="muted">Default product is Hostname Monthly — ৳10/month — exactly 1 DNS record.</p></div>
<div class="card"><h2>Orders</h2><table class="table"><tr><th>#</th><th>Reseller</th><th>Hostname</th><th>Record</th><th>Payment</th><th>Status</th><th>Action</th></tr><?php foreach($orders as $o):?><tr><td>#<?=$o['id']?></td><td><?=e($o['reseller_name'])?></td><td><?=e($o['hostname'])?><br><span class="muted"><?=e($o['domain'])?></span></td><td><?=e($o['record_type'])?> → <?=e($o['record_content'])?></td><td>৳<?=number_format((float)$o['amount'],2)?><br><code><?=e((string)$o['payment_reference'])?></code></td><td><?=e($o['status'])?></td><td><?php if($o['status']==='pending_review'):?><form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?=$o['id']?>"><button>Approve & Create DNS</button></form> <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?=$o['id']?>"><input name="note" placeholder="Reason" required><button>Reject</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></table></div></div></body></html>