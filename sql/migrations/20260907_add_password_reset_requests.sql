-- Store one-time password reset codes sent by email.
-- Codes are persisted only as password hashes and expire after a short period.
CREATE TABLE IF NOT EXISTS password_reset_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  email VARCHAR(191) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  request_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_password_reset_email_created (email, created_at),
  INDEX idx_password_reset_user_active (user_id, used_at, expires_at)
);
