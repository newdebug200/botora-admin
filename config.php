<?php
// Botora Admin — Configuration
// Rename this file or use environment variables on your VPS

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'botora_admin');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('APP_NAME', 'Botora Admin');
define('APP_URL',  getenv('APP_URL') ?: 'http://localhost');
define('APP_SECRET', getenv('APP_SECRET') ?: 'change-this-secret-key-in-production');

// API key that Botora sends in every request (must match demarrer.bat config)
define('API_KEY', getenv('BOTORA_API_KEY') ?: 'botora-api-key-change-me');

// FedaPay — use live keys only through server environment variables.
define('FEDAPAY_SECRET_KEY', getenv('FEDAPAY_SECRET_KEY') ?: '');
define('FEDAPAY_PUBLIC_KEY', getenv('FEDAPAY_PUBLIC_KEY') ?: '');
define('FEDAPAY_WEBHOOK_SECRET', getenv('FEDAPAY_WEBHOOK_SECRET') ?: '');
define('FEDAPAY_API_URL', rtrim(getenv('FEDAPAY_API_URL') ?: 'https://api.fedapay.com/v1', '/'));
define('CREDIT_TOKENS_PER_CREDIT', 100000);
define('CREDIT_VALUE_XOF', 120);

// Session lifetime (seconds)
define('SESSION_LIFETIME', 3600 * 8);

// Email notifications (optional)
define('MAIL_FROM', getenv('MAIL_FROM') ?: '');
define('MAIL_FROM_NAME', 'Botora Admin');

// Fedapay information
define('FEDAPAY_PUBLIC_KEY', getenv('sk_live_0BBv8DpE_J_vDnw7RTMji51T') ?: '');
define('FEDAPAY_SECRET_KEY', getenv('wh_live_9kJfHnYvh1asZKSjjFFvoG8o') ?: '');
