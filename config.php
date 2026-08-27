<?php
// Botora Admin — Configuration
// Toutes les valeurs sensibles peuvent être fournies par des variables d'environnement.

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'botora_admin');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// Base locale et base en ligne. En l'absence de valeurs dédiées, le mode
// historique DB_* reste utilisé pour préserver la compatibilité.
define('DB_LOCAL_HOST', getenv('DB_LOCAL_HOST') ?: 'localhost');
define('DB_LOCAL_PORT', getenv('DB_LOCAL_PORT') ?: '3306');
define('DB_LOCAL_NAME', getenv('DB_LOCAL_NAME') ?: 'botora_admin');
define('DB_LOCAL_USER', getenv('DB_LOCAL_USER') ?: 'root');
define('DB_LOCAL_PASS', getenv('DB_LOCAL_PASS') ?: '');
define('DB_ONLINE_HOST', getenv('DB_ONLINE_HOST') ?: 'localhost');
define('DB_ONLINE_PORT', getenv('DB_ONLINE_PORT') ?: '3306');
define('DB_ONLINE_NAME', getenv('DB_ONLINE_NAME') ?: 'u920435648_botora_dbname');
define('DB_ONLINE_USER', getenv('DB_ONLINE_USER') ?: 'u920435648_botora_usrname');
define('DB_ONLINE_PASS', getenv('DB_ONLINE_PASS') ?: 'nunewqi_DS3');
define('DB_MODE', strtolower(getenv('DB_MODE') ?: 'online'));
define('DB_AUTO_MIGRATE', filter_var(getenv('DB_AUTO_MIGRATE') ?: 'true', FILTER_VALIDATE_BOOLEAN));

define('APP_NAME', 'Botora Admin');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');
define('BOTORA_API_URL', rtrim(getenv('BOTORA_API_URL') ?: 'https://botora.bluelifetech.site', '/'));
define('APP_SECRET', getenv('APP_SECRET') ?: 'change-this-secret-key-in-production');
define('API_KEY', getenv('BOTORA_API_KEY') ?: 'botora-api-key-change-me');

// FedaPay — use live keys only through server environment variables.
define('FEDAPAY_SECRET_KEY', getenv('FEDAPAY_SECRET_KEY') ?: '');
define('FEDAPAY_PUBLIC_KEY', getenv('FEDAPAY_PUBLIC_KEY') ?: '');
define('FEDAPAY_WEBHOOK_SECRET', getenv('FEDAPAY_WEBHOOK_SECRET') ?: '');
define('FEDAPAY_API_URL', rtrim(getenv('FEDAPAY_API_URL') ?: 'https://api.fedapay.com/v1', '/'));
define('CREDIT_TOKENS_PER_CREDIT', 100000);
define('CREDIT_VALUE_XOF', 120);
define('SESSION_LIFETIME', 3600 * 8);
define('MAIL_FROM', getenv('MAIL_FROM') ?: '');
define('MAIL_FROM_NAME', 'Botora Admin');

// Les anciennes clés codées en dur ont été supprimées : elles ne doivent jamais
// être stockées dans le dépôt. Fournir les vraies clés uniquement via .env/serveur.
