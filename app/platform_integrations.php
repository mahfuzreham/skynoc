<?php
declare(strict_types=1);

function skynoc_platform_integrations_migrate(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS smtp_settings (id TINYINT UNSIGNED PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,host VARCHAR(255) NOT NULL DEFAULT '',port INT UNSIGNED NOT NULL DEFAULT 587,encryption ENUM('none','ssl','tls') NOT NULL DEFAULT 'tls',username VARCHAR(255) NULL,password TEXT NULL,from_email VARCHAR(190) NULL,from_name VARCHAR(190) NOT NULL DEFAULT 'SkyNoc',updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("INSERT IGNORE INTO smtp_settings(id,enabled,host,port,encryption,username,password,from_email,from_name) VALUES(1,0,'',587,'tls',NULL,NULL,'','SkyNoc')");
    $db->exec("CREATE TABLE IF NOT EXISTS whmcs_invoice_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reseller_id BIGINT UNSIGNED NOT NULL,invoice_id VARCHAR(100) NOT NULL,invoice_number VARCHAR(100) NULL,client_id VARCHAR(100) NULL,client_name VARCHAR(190) NULL,client_email VARCHAR(190) NULL,total DECIMAL(14,2) NOT NULL DEFAULT 0.00,currency VARCHAR(20) NULL,gateway VARCHAR(100) NULL,paid_at DATETIME NULL,raw_json LONGTEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_whmcs_invoice_event(reseller_id,invoice_id),INDEX idx_whmcs_invoice_reseller(reseller_id,created_at),FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try {
        $db->exec("ALTER TABLE reseller_notification_settings ADD COLUMN custom_email VARCHAR(190) NULL");
    } catch (PDOException $e) {
        if (!str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e;
    }
    foreach ([
        "ALTER TABLE telegram_settings ADD COLUMN admin_chat_ids TEXT NULL",
        "ALTER TABLE telegram_settings ADD COLUMN support_enabled TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE telegram_settings ADD COLUMN support_welcome TEXT NULL",
    ] as $sql) {
        try { $db->exec($sql); }
        catch (PDOException $e) { if (!str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e; }
    }
}
