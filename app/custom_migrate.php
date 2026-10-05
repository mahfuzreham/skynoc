<?php
declare(strict_types=1);

function skynoc_custom_migrate(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS skynoc_feature_migrations (version VARCHAR(120) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $migrations = [
        '2026_10_05_reseller_activation_pricing_billing' => [
            "ALTER TABLE packages ADD COLUMN buy_price DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER price",
            "UPDATE packages SET buy_price=0.00 WHERE buy_price IS NULL"
        ],
        '2026_10_05_sms_targeting_automation' => [
            "CREATE TABLE IF NOT EXISTS sms_settings (id TINYINT UNSIGNED PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,provider_name VARCHAR(120) NOT NULL DEFAULT 'Generic JSON SMS',api_url VARCHAR(500) NULL,api_key TEXT NULL,api_secret TEXT NULL,sender_id VARCHAR(80) NULL,auth_header VARCHAR(120) NOT NULL DEFAULT 'Authorization',auth_prefix VARCHAR(80) NOT NULL DEFAULT 'Bearer ',recipient_field VARCHAR(80) NOT NULL DEFAULT 'to',message_field VARCHAR(80) NOT NULL DEFAULT 'message',sender_field VARCHAR(80) NOT NULL DEFAULT 'sender',payload_template TEXT NULL,reminder_days VARCHAR(60) NOT NULL DEFAULT '30,7,1',renewal_enabled TINYINT(1) NOT NULL DEFAULT 1,expired_enabled TINYINT(1) NOT NULL DEFAULT 1,reminder_enabled TINYINT(1) NOT NULL DEFAULT 1,renewed_enabled TINYINT(1) NOT NULL DEFAULT 1,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "INSERT IGNORE INTO sms_settings(id) VALUES(1)",
            "CREATE TABLE IF NOT EXISTS sms_contacts (user_id BIGINT UNSIGNED PRIMARY KEY,phone VARCHAR(40) NULL,country_iso CHAR(2) NOT NULL DEFAULT 'BD',country_calling_code VARCHAR(8) NOT NULL DEFAULT '+880',timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Dhaka',selected TINYINT(1) NOT NULL DEFAULT 0,sms_opt_in TINYINT(1) NOT NULL DEFAULT 1,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,INDEX idx_sms_contacts_selected(selected),INDEX idx_sms_contacts_country(country_iso)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS sms_country_settings (country_iso CHAR(2) PRIMARY KEY,country_name VARCHAR(100) NOT NULL,timezone VARCHAR(64) NOT NULL,enabled TINYINT(1) NOT NULL DEFAULT 1,quiet_start TIME NULL,quiet_end TIME NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "INSERT IGNORE INTO sms_country_settings(country_iso,country_name,timezone,enabled,quiet_start,quiet_end) VALUES ('BD','Bangladesh','Asia/Dhaka',1,'21:00:00','08:00:00'),('US','United States','America/New_York',1,'21:00:00','08:00:00'),('GB','United Kingdom','Europe/London',1,'21:00:00','08:00:00'),('IN','India','Asia/Kolkata',1,'21:00:00','08:00:00'),('AE','United Arab Emirates','Asia/Dubai',1,'21:00:00','08:00:00'),('SA','Saudi Arabia','Asia/Riyadh',1,'21:00:00','08:00:00')",
            "CREATE TABLE IF NOT EXISTS sms_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,license_id BIGINT UNSIGNED NULL,type VARCHAR(60) NOT NULL,phone VARCHAR(40) NOT NULL,message TEXT NOT NULL,status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',provider_response TEXT NULL,dedupe_key VARCHAR(190) NULL,sent_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(license_id) REFERENCES licenses(id) ON DELETE SET NULL,UNIQUE KEY uq_sms_dedupe(dedupe_key),INDEX idx_sms_logs_user_created(user_id,created_at),INDEX idx_sms_logs_type_created(type,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ],
        '2026_10_05_smtp_email_delivery' => [
            "CREATE TABLE IF NOT EXISTS smtp_settings (id TINYINT UNSIGNED PRIMARY KEY,enabled TINYINT(1) NOT NULL DEFAULT 0,host VARCHAR(255) NOT NULL DEFAULT '',port SMALLINT UNSIGNED NOT NULL DEFAULT 587,encryption ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',username VARCHAR(255) NULL,password TEXT NULL,from_email VARCHAR(190) NOT NULL DEFAULT '',from_name VARCHAR(190) NOT NULL DEFAULT 'SkyNoc',updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "INSERT IGNORE INTO smtp_settings(id,port,encryption,from_name) VALUES(1,587,'tls','SkyNoc')",
            "ALTER TABLE reseller_notification_settings ADD COLUMN custom_email VARCHAR(190) NULL AFTER email_enabled"
        ]
    ];
    foreach($migrations as $version=>$queries){
        $q=$db->prepare('SELECT 1 FROM skynoc_feature_migrations WHERE version=? LIMIT 1'); $q->execute([$version]); if($q->fetchColumn()) continue;
        foreach($queries as $sql){
            try{$db->exec($sql);}catch(PDOException $e){$message=strtolower($e->getMessage());$idempotent=str_contains($message,'duplicate column')||str_contains($message,'duplicate field')||str_contains($message,'duplicate key name')||str_contains($message,'duplicate key')||str_contains($message,'already exists');if($idempotent)continue;throw $e;}
        }
        $db->prepare('INSERT INTO skynoc_feature_migrations(version) VALUES(?)')->execute([$version]);
    }
}
