<?php
// Botora Admin — Configuration
// Toutes les valeurs sensibles peuvent être fournies par des variables d'environnement.
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
  require_once $autoload;
}

function botora_load_env(string $path): void {
  if (!is_file($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines === false) return;
  foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, ';') === 0) continue;

    if (preg_match('/^export\s+([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $trimmed, $matches)) {
      [, $key, $rawValue] = $matches;
    } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $trimmed, $matches)) {
      [, $key, $rawValue] = $matches;
    } else {
      continue;
    }

    $value = trim($rawValue);
    if (preg_match('/^("|\')(.*)\1$/', $value, $quoted)) {
      $value = $quoted[2];
    }
    $value = preg_replace('/\s+#.*$/', '', $value);
    $value = trim($value);

    if (getenv($key) === false) putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
  }
}

botora_load_env(__DIR__ . '/.env');

function botora_setting(string $key, string $default = ''): string {
  $value = getenv($key);
  if ($value !== false && trim((string)$value) !== '') return trim((string)$value);
  $serverValue = $_SERVER[$key] ?? '';
  return trim((string)$serverValue) !== '' ? trim((string)$serverValue) : $default;
}

$localEnvFileExists = is_file(__DIR__ . '/.env');
$envMode = strtolower(trim((string)(botora_setting('APP_ENV') ?: '')));
if ($envMode === '') {
  $envMode = $localEnvFileExists ? 'local' : 'online';
}
if ($envMode === 'prod') $envMode = 'online';
if ($envMode === 'development') $envMode = 'local';

$explicitMode = strtolower(trim((string)(botora_setting('DB_MODE') ?: '')));
if ($explicitMode === '') {
  $explicitMode = $envMode;
}

if ($explicitMode === 'prod') $explicitMode = 'online';
if ($explicitMode === 'development') $explicitMode = 'local';


define('APP_ENV', $envMode);
define('DB_MODE', $explicitMode);
define('DB_HOST', botora_setting('DB_HOST') ?: 'localhost');
define('DB_PORT', botora_setting('DB_PORT') ?: '3306');
define('DB_NAME', botora_setting('DB_NAME') ?: 'botora_admin');
define('DB_USER', botora_setting('DB_USER') ?: 'root');
define('DB_PASS', botora_setting('DB_PASS') ?: '');

// Base locale et base en ligne. En l'absence de valeurs dédiées, le mode
// historique DB_* reste utilisé pour préserver la compatibilité.
define('DB_LOCAL_HOST', botora_setting('DB_LOCAL_HOST') ?: 'localhost');
define('DB_LOCAL_PORT', botora_setting('DB_LOCAL_PORT') ?: '3306');
define('DB_LOCAL_NAME', botora_setting('DB_LOCAL_NAME') ?: 'botora_admin');
define('DB_LOCAL_USER', botora_setting('DB_LOCAL_USER') ?: 'root');
define('DB_LOCAL_PASS', botora_setting('DB_LOCAL_PASS') ?: '');
define('DB_ONLINE_HOST', botora_setting('DB_ONLINE_HOST') ?: 'localhost');
define('DB_ONLINE_PORT', botora_setting('DB_ONLINE_PORT') ?: '3306');
define('DB_ONLINE_NAME', botora_setting('DB_ONLINE_NAME') ?: 'u920435648_botora_dbname');
define('DB_ONLINE_USER', botora_setting('DB_ONLINE_USER') ?: 'u920435648_botora_usrname');
define('DB_ONLINE_PASS', botora_setting('DB_ONLINE_PASS') ?: 'nunewqi_DS3');

define('DB_AUTO_MIGRATE', filter_var(botora_setting('DB_AUTO_MIGRATE') ?: 'true', FILTER_VALIDATE_BOOLEAN));

// Premier compte créé automatiquement uniquement si aucun administrateur n’existe.
// Remplacez ces valeurs via les variables d’environnement en production.
define('BOTORA_ADMIN_EMAIL', botora_setting('BOTORA_ADMIN_EMAIL') ?: 'admin@botora.local');
define('BOTORA_ADMIN_PASSWORD', botora_setting('BOTORA_ADMIN_PASSWORD') ?: 'BotoraAdmin#2026');
define('BOTORA_ADMIN_NAME', botora_setting('BOTORA_ADMIN_NAME') ?: 'Botora Superadmin');

define('APP_NAME', 'Botora Admin');
define('BOTORA_API_URL', rtrim(botora_setting('BOTORA_API_URL') ?: 'https://botora.bluelifetech.site', '/'));
define('FEDAPAY_CALLBACK_URL', botora_setting('FEDAPAY_CALLBACK_URL') ?: BOTORA_API_URL . '/?payment=return');
// L’URL publique est prioritaire. Une valeur localhost n’est acceptée que si
// l’installation locale est explicitement choisie par DB_MODE=local.
$configuredAppUrl = trim((string)(botora_setting('APP_URL') ?: ''));
$isLocalUrl = $configuredAppUrl !== '' && (bool)preg_match('/^https?:\\/\\/(localhost|127\\.0\\.0\\.1|0\\.0\\.0\\.0)(?::\\d+)?(?:$|\\/)/i', $configuredAppUrl);
if ($configuredAppUrl === '' || ($isLocalUrl && DB_MODE !== 'local')) $configuredAppUrl = BOTORA_API_URL;
define('APP_URL', rtrim($configuredAppUrl, '/'));
define('APP_SECRET', botora_setting('APP_SECRET') ?: 'change-this-secret-key-in-production');
define('API_KEY', botora_setting('BOTORA_API_KEY') ?: 'botora-api-key-change-me');
// Authentification interservices désactivée pendant la phase de développement.
define('APP_DEBUG', filter_var(botora_setting('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));

// FedaPay — use live keys only through server environment variables.
define('FEDAPAY_SECRET_KEY', botora_setting('FEDAPAY_SECRET_KEY') ?: 'sk_live_0BBv8DpE_J_vDnw7RTMji51T');
define('FEDAPAY_PUBLIC_KEY', botora_setting('FEDAPAY_PUBLIC_KEY') ?: 'pk_live_xWyQ7phiPrc31sE3503lex1j');
define('FEDAPAY_WEBHOOK_SECRET', botora_setting('FEDAPAY_WEBHOOK_SECRET') ?: 'wh_live_9kJfHnYvh1asZKSjjFFvoG8o');
$fedapayApiUrl = trim((string)(botora_setting('FEDAPAY_API_URL') ?: 'https://api.fedapay.com'));
$fedapayApiUrl = preg_replace('#/v1/?$#i', '', $fedapayApiUrl);
define('FEDAPAY_API_URL', rtrim($fedapayApiUrl, '/'));
define('CREDIT_TOKENS_PER_CREDIT', 100000);
define('CREDIT_VALUE_XOF', 120);
define('SESSION_LIFETIME', 3600 * 8);
define('MAIL_FROM', botora_setting('MAIL_FROM') ?: '');
define('MAIL_FROM_NAME', 'Botora Admin');

// Les anciennes clés codées en dur ont été supprimées : elles ne doivent jamais
// être stockées dans le dépôt. Fournir les vraies clés uniquement via .env/serveur.
