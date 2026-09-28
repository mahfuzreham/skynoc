<?php
declare(strict_types=1);

/**
 * Small, dependency-free migration runner.
 * It supports both fresh databases and existing SkyNoc databases.
 */
function skynoc_migrate(PDO $db): void
{
    // Prevent two PHP requests from migrating the same database simultaneously.
    $lock = $db->query("SELECT GET_LOCK('skynoc_schema_migration', 15)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('Could not acquire database migration lock.');
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $hasUsers = (bool)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'")->fetchColumn();

        if (!$hasUsers) {
            $schemaFile = __DIR__ . '/../database/schema.sql';
            if (!is_file($schemaFile) || !is_readable($schemaFile)) {
                throw new RuntimeException('Core database schema is missing.');
            }

            $sql = file_get_contents($schemaFile);
            if ($sql === false) {
                throw new RuntimeException('Could not read core database schema.');
            }

            // schema.sql contains simple CREATE/INDEX statements separated by semicolons.
            foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $sql))) as $statement) {
                $db->exec($statement);
            }

            $db->prepare('INSERT IGNORE INTO schema_migrations(version) VALUES(?)')
               ->execute(['2026_09_28_core_schema']);
        } else {
            $db->prepare('INSERT IGNORE INTO schema_migrations(version) VALUES(?)')
               ->execute(['2026_09_28_core_schema']);
        }

        $migrations = [
            '2026_09_28_security_extensions' => [
                "ALTER TABLE users ADD COLUMN permissions JSON NULL",
                "ALTER TABLE api_keys ADD COLUMN scopes VARCHAR(255) NOT NULL DEFAULT 'licenses:read,reissue:create,reissue:read'",
                "CREATE TABLE IF NOT EXISTS notifications (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    reseller_id BIGINT UNSIGNED NOT NULL,
                    type VARCHAR(60) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    read_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,
                    INDEX idx_notifications_reseller_read (reseller_id,read_at),
                    INDEX idx_notifications_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS api_rate_limits (
                    api_key_id BIGINT UNSIGNED PRIMARY KEY,
                    window_started_at DATETIME NOT NULL,
                    request_count INT UNSIGNED NOT NULL DEFAULT 0,
                    FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ]
        ];

        $migrations['2026_09_28_reseller_billing'] = [
            "ALTER TABLE resellers MODIFY status ENUM('pending','active','suspended','disabled') NOT NULL DEFAULT 'pending'",
            "ALTER TABLE resellers ADD COLUMN wallet_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00",
            "CREATE TABLE IF NOT EXISTS wallet_transactions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reseller_id BIGINT UNSIGNED NOT NULL,type ENUM('activation_deposit','deposit','purchase','refund','adjustment') NOT NULL,amount DECIMAL(14,2) NOT NULL,reference VARCHAR(120) NULL,description VARCHAR(255) NULL,order_id BIGINT UNSIGNED NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,INDEX idx_wallet_reseller_created (reseller_id,created_at),INDEX idx_wallet_order (order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS deposit_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reseller_id BIGINT UNSIGNED NOT NULL,amount DECIMAL(14,2) NOT NULL,method VARCHAR(60) NOT NULL,reference VARCHAR(120) NULL,note TEXT NULL,status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',reviewed_by BIGINT UNSIGNED NULL,review_note TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,INDEX idx_deposit_status (status),INDEX idx_deposit_reseller (reseller_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS packages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,slug VARCHAR(120) NOT NULL UNIQUE,description TEXT NULL,price DECIMAL(14,2) NOT NULL,client_limit INT UNSIGNED NULL,billing_period VARCHAR(30) NOT NULL DEFAULT 'monthly',active TINYINT(1) NOT NULL DEFAULT 1,sort_order INT NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_packages_active_sort (active,sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reseller_id BIGINT UNSIGNED NOT NULL,package_id BIGINT UNSIGNED NOT NULL,license_id BIGINT UNSIGNED NULL,domain VARCHAR(255) NULL,amount DECIMAL(14,2) NOT NULL,status ENUM('pending','processing','completed','rejected','refunded') NOT NULL DEFAULT 'pending',source ENUM('portal','api','whmcs_module','admin') NOT NULL DEFAULT 'portal',notes TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,completed_at DATETIME NULL,FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,FOREIGN KEY(package_id) REFERENCES packages(id) ON DELETE RESTRICT,FOREIGN KEY(license_id) REFERENCES licenses(id) ON DELETE SET NULL,INDEX idx_orders_reseller (reseller_id,created_at),INDEX idx_orders_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        $migrations['2026_09_28_order_idempotency'] = [
            "ALTER TABLE orders ADD COLUMN external_ref VARCHAR(190) NULL",
            "ALTER TABLE orders ADD UNIQUE KEY uq_orders_reseller_external (reseller_id,external_ref)"
        ];

        foreach ($migrations as $version => $queries) {
            $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
            $check->execute([$version]);
            if ($check->fetchColumn()) continue;

            foreach ($queries as $sql) {
                try {
                    $db->exec($sql);
                } catch (PDOException $e) {
                    $message = strtolower($e->getMessage());
                    if (str_contains($message, 'duplicate column') || str_contains($message, 'duplicate field')) {
                        continue;
                    }
                    throw $e;
                }
            }

            $s = $db->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
            $s->execute([$version]);
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('skynoc_schema_migration')");
    }
}
