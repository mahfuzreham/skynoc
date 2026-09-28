<?php
declare(strict_types=1);

/**
 * Lightweight, idempotent migrations.
 * Every version is recorded so it runs only once.
 */
function skynoc_migrate(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $migrations = [
        '2026_09_28_core_extensions' => [
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

    foreach ($migrations as $version => $queries) {
        $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
        $check->execute([$version]);
        if ($check->fetchColumn()) {
            continue;
        }

        $db->beginTransaction();
        try {
            foreach ($queries as $sql) {
                try {
                    $db->exec($sql);
                } catch (PDOException $e) {
                    /*
                     * ALTER TABLE is idempotent at application level:
                     * if a deployment already contains a column, continue.
                     */
                    $message = strtolower($e->getMessage());
                    if (str_contains($message, 'duplicate column') || str_contains($message, 'duplicate field')) {
                        continue;
                    }
                    throw $e;
                }
            }

            $s = $db->prepare('INSERT INTO schema_migrations(version) VALUES(?)');
            $s->execute([$version]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
