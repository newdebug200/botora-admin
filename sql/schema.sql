-- Botora Admin Panel — Database Schema
-- MySQL 8.0+

CREATE DATABASE IF NOT EXISTS botora_admin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE botora_admin;

-- Admin users (panel access)
CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin','admin','viewer') DEFAULT 'admin',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME NULL
);

-- Plans
CREATE TABLE plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(40) NOT NULL UNIQUE,
  credits_per_month INT NOT NULL DEFAULT 0,
  max_profiles INT NOT NULL DEFAULT 1,
  campaigns_enabled TINYINT(1) DEFAULT 0,
  ia_enabled TINYINT(1) DEFAULT 1,
  trial_days INT NOT NULL DEFAULT 14,
  price_eur DECIMAL(8,2) DEFAULT 0.00,
  is_active TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Default plans
INSERT INTO plans (name, slug, credits_per_month, max_profiles, campaigns_enabled, ia_enabled, trial_days, price_eur) VALUES
('Gratuit', 'free', 50, 1, 0, 1, 14, 0.00),
('Starter', 'starter', 500, 1, 1, 1, 14, 9.90),
('Pro', 'pro', 2000, 3, 1, 1, 14, 29.90),
('Partenaire', 'partner', 10000, 10, 1, 1, 30, 79.90);

-- Clients (Botora users)
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(191) NOT NULL UNIQUE,
  company VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  plan_id INT UNSIGNED NULL,
  license_key CHAR(36) NOT NULL UNIQUE,
  status ENUM('trial','active','suspended','expired','banned') DEFAULT 'trial',
  credits_balance DECIMAL(20,10) NOT NULL DEFAULT 0,
  trial_ends_at DATE NULL,
  activated_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
);

-- License activations (track each PC/installation)
CREATE TABLE activations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  machine_id VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  botora_version VARCHAR(20) NULL,
  first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Credit transactions
CREATE TABLE credit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(20,10) NOT NULL,
  type ENUM('add','consume','reset','expire') NOT NULL,
  reason VARCHAR(255) NULL,
  admin_id INT UNSIGNED NULL,
  balance_after DECIMAL(20,10) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

-- Usage logs (reported by Botora)
CREATE TABLE usage_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  credits_used DECIMAL(20,10) DEFAULT 0,
  meta JSON NULL,
  logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Indexes
CREATE INDEX idx_users_license ON users(license_key);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_credit_logs_user ON credit_logs(user_id, created_at);
CREATE INDEX idx_usage_logs_user ON usage_logs(user_id, logged_at);


-- FedaPay payments initiated by whatsapp-grok-platform
CREATE TABLE payment_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  external_id VARCHAR(100) NULL UNIQUE,
  amount_xof DECIMAL(14,2) NOT NULL,
  credits DECIMAL(20,10) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending',
  description VARCHAR(255) NULL,
  metadata JSON NULL,
  approved_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_payment_user (user_id, created_at),
  INDEX idx_payment_status (status)
);

CREATE TABLE payment_webhook_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id INT UNSIGNED NULL,
  event_id VARCHAR(191) NOT NULL UNIQUE,
  event_type VARCHAR(80) NOT NULL,
  payload JSON NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payment_transactions(id) ON DELETE SET NULL
);


-- Platform feature switches managed centrally
CREATE TABLE platform_features (
  feature_key VARCHAR(100) PRIMARY KEY,
  label VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO platform_features (feature_key,label,description,enabled) VALUES
('whatsapp_discussions_enabled','Discussions WhatsApp','Messagerie et discussions WhatsApp',1),
('ia_enabled_global','Bot IA','Traitement IA général',1),
('auto_replies_enabled','Réponses automatiques','Réponses automatiques du bot',1),
('faq_enabled','FAQ automatique','Gestion des FAQ automatiques',1),
('quick_replies_enabled','Réponses rapides','Modèles de réponses rapides',1),
('funnel_enabled','Entonnoir de contacts','Suivi des prospects',1),
('sentiments_enabled','Traitement des sentiments','Analyse des sentiments clients',1),
('sensitive_keywords_enabled','Mots-clés sensibles','Alertes et mots-clés sensibles',1),
('campaigns_enabled','Campagnes groupées','Campagnes marketing',1),
('stats_enabled','Statistiques','Statistiques de la plateforme',1),
('maintenance_enabled','Mode maintenance','État de maintenance global',0),
('verification_triggers_enabled','Vérification WhatsApp','Déclencheurs de vérification',1),
('credits_enabled','Système de crédits','Facturation à la consommation',1);


-- Activity telemetry reported by whatsapp-grok-platform
CREATE TABLE activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  tokens_used INT UNSIGNED NULL,
  credits_used DECIMAL(20,10) NULL,
  payload JSON NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_activity_user_date (user_id, created_at),
  INDEX idx_activity_type_date (event_type, created_at)
);
