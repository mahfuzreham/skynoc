<?php
declare(strict_types=1);

function skynoc_hostname_migrate(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cloudflare_zones (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        zone_id CHAR(32) NOT NULL UNIQUE,
        domain VARCHAR(255) NOT NULL UNIQUE,
        status VARCHAR(30) NOT NULL DEFAULT 'active',
        sellable TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cf_zones_sellable (sellable,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS hostname_products (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        slug VARCHAR(120) NOT NULL UNIQUE,
        price DECIMAL(14,2) NOT NULL DEFAULT 10.00,
        currency CHAR(3) NOT NULL DEFAULT 'BDT',
        billing_period VARCHAR(20) NOT NULL DEFAULT 'monthly',
        record_limit TINYINT UNSIGNED NOT NULL DEFAULT 1,
        description TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hostname_products_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS hostname_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        reseller_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        zone_id BIGINT UNSIGNED NOT NULL,
        hostname VARCHAR(255) NOT NULL,
        record_type VARCHAR(10) NOT NULL,
        record_content VARCHAR(2048) NOT NULL,
        cloudflare_record_id VARCHAR(64) NULL,
        amount DECIMAL(14,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'BDT',
        status ENUM('pending_payment','pending_review','active','suspended','expired','rejected','cancelled') NOT NULL DEFAULT 'pending_payment',
        payment_reference VARCHAR(190) NULL,
        admin_note TEXT NULL,
        next_due_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES hostname_products(id) ON DELETE RESTRICT,
        FOREIGN KEY(zone_id) REFERENCES cloudflare_zones(id) ON DELETE RESTRICT,
        UNIQUE KEY uq_hostname_active (hostname,status),
        INDEX idx_hostname_reseller (reseller_id,created_at),
        INDEX idx_hostname_status_due (status,next_due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $exists=$db->query("SELECT COUNT(*) FROM hostname_products WHERE slug='hostname-monthly-10' AND active=1")->fetchColumn();
    if (!(int)$exists) {
        $s=$db->prepare("INSERT INTO hostname_products(name,slug,price,currency,billing_period,record_limit,description,active) VALUES(?,?,?,?,?,?,?,1)");
        $s->execute(['Hostname Monthly','hostname-monthly-10',10.00,'BDT','monthly',1,'1 hostname with exactly 1 Cloudflare DNS record. Monthly charge: ৳10.',]);
    }
}
