<?php
declare(strict_types=1);

function skynoc_production_migrate(PDO $db): void
{
    $lock = $db->query("SELECT GET_LOCK('skynoc_production_migration', 15)")->fetchColumn();
    if ((int)$lock !== 1) throw new RuntimeException('Could not acquire production migration lock.');

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $version='2026_10_05_production_hardening';
        $check=$db->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
        $check->execute([$version]);
        if ($check->fetchColumn()) return;

        $db->exec("ALTER TABLE orders ADD COLUMN buy_cost DECIMAL(18,2) NULL AFTER amount");

        /* Snapshot the package buy cost so changing package pricing cannot rewrite history. */
        $db->exec("UPDATE orders o LEFT JOIN packages p ON p.id=o.package_id LEFT JOIN licenses l ON l.id=o.license_id SET o.buy_cost=COALESCE(l.cost,p.buy_price,0) WHERE o.buy_cost IS NULL");

        $db->exec("DROP TRIGGER IF EXISTS skynoc_orders_before_insert_cost");
        $db->exec("CREATE TRIGGER skynoc_orders_before_insert_cost BEFORE INSERT ON orders FOR EACH ROW BEGIN DECLARE v_cost DECIMAL(18,2); SELECT buy_price INTO v_cost FROM packages WHERE id=NEW.package_id LIMIT 1; SET NEW.buy_cost=COALESCE(NEW.buy_cost,v_cost,0); END");

        $db->exec("DROP TRIGGER IF EXISTS skynoc_orders_before_update_cost");
        $db->exec("CREATE TRIGGER skynoc_orders_before_update_cost BEFORE UPDATE ON orders FOR EACH ROW BEGIN DECLARE v_cost DECIMAL(18,2); IF NEW.buy_cost IS NULL OR (OLD.status <> 'completed' AND NEW.status = 'completed' AND NEW.buy_cost=0) THEN SELECT buy_price INTO v_cost FROM packages WHERE id=NEW.package_id LIMIT 1; SET NEW.buy_cost=COALESCE(NEW.buy_cost,v_cost,0); END IF; END");

        $db->prepare('INSERT INTO schema_migrations(version) VALUES(?)')->execute([$version]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('skynoc_production_migration')");
    }
}
