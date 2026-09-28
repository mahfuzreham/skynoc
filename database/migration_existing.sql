-- Run this only if an older SkyNoc schema is already installed.
CREATE TABLE IF NOT EXISTS telegram_settings (id TINYINT UNSIGNED PRIMARY KEY,bot_token VARCHAR(255) NOT NULL,admin_chat_id VARCHAR(64) NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_api_reseller_status ON api_keys(reseller_id,status);

-- Safe additions for existing SkyNoc installations
ALTER TABLE users ADD COLUMN permissions JSON NULL;
ALTER TABLE api_keys ADD COLUMN scopes VARCHAR(255) NOT NULL DEFAULT 'licenses:read,reissue:create,reissue:read';
CREATE TABLE IF NOT EXISTS notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reseller_id BIGINT UNSIGNED NOT NULL,type VARCHAR(60) NOT NULL,title VARCHAR(255) NOT NULL,message TEXT NOT NULL,read_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(reseller_id) REFERENCES resellers(id) ON DELETE CASCADE,INDEX idx_notifications_reseller_read (reseller_id,read_at),INDEX idx_notifications_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS api_rate_limits (api_key_id BIGINT UNSIGNED PRIMARY KEY,window_started_at DATETIME NOT NULL,request_count INT UNSIGNED NOT NULL DEFAULT 0,FOREIGN KEY(api_key_id) REFERENCES api_keys(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
