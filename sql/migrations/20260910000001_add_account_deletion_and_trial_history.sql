-- Account deletion requests remain reviewable before any account data is removed.
CREATE TABLE account_deletion_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(191) NOT NULL,
  reason_code VARCHAR(50) NOT NULL,
  reason_text TEXT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by INT UNSIGNED NULL,
  review_note TEXT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (reviewed_by) REFERENCES admins(id) ON DELETE SET NULL,
  INDEX idx_account_deletion_status_requested (status, requested_at),
  INDEX idx_account_deletion_email (email)
);

-- Immutable registry used to prevent reusing a WhatsApp number for another trial.
-- It intentionally has no foreign key to users, so it survives account deletion.
CREATE TABLE whatsapp_trial_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone_number VARCHAR(30) NOT NULL UNIQUE,
  first_user_id INT UNSIGNED NULL,
  first_user_email VARCHAR(191) NULL,
  first_connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (first_user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_trial_history_user (first_user_id)
);

-- Preserve every number already recorded before this migration.
INSERT IGNORE INTO whatsapp_trial_history (phone_number, first_user_id, first_user_email, first_connected_at, last_seen_at)
SELECT wa.phone_number, wa.user_id, u.email, wa.first_connected_at, wa.last_connected_at
FROM whatsapp_accounts wa
LEFT JOIN users u ON u.id = wa.user_id;
