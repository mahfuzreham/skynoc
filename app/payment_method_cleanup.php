<?php
declare(strict_types=1);

function skynoc_remove_unused_payment_methods(PDO $db): void
{
    // These methods are no longer offered. Keep historical deposit records intact.
    $db->exec("DELETE FROM payment_methods WHERE code IN ('bKash','Bank_Transfer','Manual')");
}

try {
    skynoc_remove_unused_payment_methods($db);
} catch (Throwable $e) {
    error_log('SkyNoc payment method cleanup: '.$e->getMessage());
}
