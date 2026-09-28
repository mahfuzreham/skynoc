-- Run this only if an older SkyNoc schema is already installed.
CREATE TABLE IF NOT EXISTS telegram_settings (id TINYINT UNSIGNED PRIMARY KEY,bot_token VARCHAR(255) NOT NULL,admin_chat_id VARCHAR(64) NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_api_reseller_status ON api_keys(reseller_id,status);