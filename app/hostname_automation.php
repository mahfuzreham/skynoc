<?php
declare(strict_types=1);

/** Automatically renews active hostname orders from reseller wallet. */
function skynoc_hostname_renewals(int $limit = 50): array
{
    global $db;
    $renewed = 0;
    $expired = 0;
    $failed = 0;

    $q = $db->prepare("SELECT o.id,o.reseller_id,o.hostname,o.cloudflare_record_id,o.next_due_at,p.price,p.billing_period
        FROM hostname_orders o
        JOIN hostname_products p ON p.id=o.product_id
        WHERE o.status='active' AND o.next_due_at IS NOT NULL AND o.next_due_at<=NOW()
        ORDER BY o.next_due_at ASC LIMIT ?");
    $q->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $q->execute();

    foreach ($q->fetchAll() as $order) {
        $db->beginTransaction();
        try {
            $lock = $db->prepare("SELECT o.*,p.price,p.billing_period,z.zone_id
                FROM hostname_orders o
                JOIN hostname_products p ON p.id=o.product_id
                JOIN cloudflare_zones z ON z.id=o.zone_id
                WHERE o.id=? FOR UPDATE");
            $lock->execute([(int)$order['id']]);
            $o = $lock->fetch();
            if (!$o || $o['status'] !== 'active' || !$o['next_due_at'] || strtotime($o['next_due_at']) > time()) {
                $db->rollBack();
                continue;
            }

            $amount = (float)$o['price'];
            $balance = reseller_wallet((int)$o['reseller_id']);
            if ($balance < $amount) {
                $db->prepare("UPDATE hostname_orders SET status='suspended',admin_note=? WHERE id=? AND status='active'")
                    ->execute(['Automatic renewal failed: insufficient reseller wallet balance.', (int)$o['id']]);
                $db->commit();
                if (!empty($o['cloudflare_record_id'])) {
                    try { cloudflare_delete_dns_record((string)$o['zone_id'], (string)$o['cloudflare_record_id']); } catch (Throwable $cf) { error_log('SkyNoc hostname DNS delete failed #'.(int)$o['id'].': '.$cf->getMessage()); }
                }
                notify_reseller((int)$o['reseller_id'], 'hostname_renewal', 'Hostname suspended', 'Hostname '.$o['hostname'].' was suspended because the wallet did not have enough balance for the renewal.');
                $failed++;
                continue;
            }

            wallet_debit((int)$o['reseller_id'], $amount, 'purchase', 'HOSTNAME-RENEW-'.$o['id'], 'Monthly hostname renewal for #'.$o['id'], null, null);
            $next = (new DateTimeImmutable((string)$o['next_due_at']))->modify('+1 month')->format('Y-m-d H:i:s');
            $db->prepare("UPDATE hostname_orders SET next_due_at=?,admin_note=NULL,status='active' WHERE id=? AND status='active'")
                ->execute([$next, (int)$o['id']]);
            $db->commit();
            notify_reseller((int)$o['reseller_id'], 'hostname_renewal', 'Hostname renewed', 'Hostname '.$o['hostname'].' was automatically renewed for ৳'.number_format($amount, 2).'. Next due: '.$next.'.');
            $renewed++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('SkyNoc hostname renewal #'.(int)$order['id'].': '.$e->getMessage());
            $failed++;
        }
    }

    return ['renewed'=>$renewed,'failed'=>$failed,'expired'=>$expired];
}
