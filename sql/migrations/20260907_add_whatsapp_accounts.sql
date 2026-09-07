-- Add the central registry of WhatsApp accounts connected by each user.
-- Run once on existing Botora Admin databases after deploying the API change.
CREATE TABLE IF NOT EXISTS whatsapp_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  profile_key VARCHAR(191) NULL,
  phone_number VARCHAR(30) NOT NULL,
  display_name VARCHAR(150) NULL,
  is_connected TINYINT(1) NOT NULL DEFAULT 1,
  first_connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  disconnected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_whatsapp_account_user_phone (user_id, phone_number),
  INDEX idx_whatsapp_accounts_user (user_id, created_at),
  INDEX idx_whatsapp_accounts_profile (user_id, profile_key)
);
