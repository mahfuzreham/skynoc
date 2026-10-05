<?php
declare(strict_types=1);
function skynoc_custom_migrate(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS skynoc_feature_migrations (version VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $version='2026_10_05_reseller_activation_pricing_billing';
    $q=$db->prepare('SELECT 1 FROM skynoc_feature_migrations WHERE version=? LIMIT 1');$q->execute([$version]);
    if($q->fetchColumn()) return;
    $has=$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='packages' AND column_name='buy_price'")->fetchColumn();
    if(!(int)$has){$db->exec("ALTER TABLE packages ADD COLUMN buy_price DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER price");}
    $db->exec("UPDATE packages SET buy_price=0.00 WHERE buy_price IS NULL");
    $db->prepare('INSERT INTO skynoc_feature_migrations(version) VALUES(?)')->execute([$version]);
}
