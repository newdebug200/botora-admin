<?php
require_once __DIR__ . '/../config.php';

/** @var array<string, PDO> */
$GLOBALS['_botora_pdo'] = $GLOBALS['_botora_pdo'] ?? [];
$GLOBALS['_botora_db_status'] = $GLOBALS['_botora_db_status'] ?? [];

function db_config(string $source = 'active'): array {
  $source = strtolower($source);
  if ($source === 'online') {
    return ['host' => DB_ONLINE_HOST, 'port' => DB_ONLINE_PORT, 'name' => DB_ONLINE_NAME, 'user' => DB_ONLINE_USER, 'pass' => DB_ONLINE_PASS];
  }
  return ['host' => DB_LOCAL_HOST, 'port' => DB_LOCAL_PORT, 'name' => DB_LOCAL_NAME, 'user' => DB_LOCAL_USER, 'pass' => DB_LOCAL_PASS];
}

function db_dsn(array $cfg, bool $withDatabase = true): string {
  $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'];
  if ($withDatabase) $dsn .= ';dbname=' . $cfg['name'];
  return $dsn . ';charset=utf8mb4';
}

function db_pdo(array $cfg, bool $withDatabase = true): PDO {
  return new PDO(db_dsn($cfg, $withDatabase), $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}

function db_identifier(string $value): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) throw new RuntimeException('Nom de base de données invalide.');
  return '`' . $value . '`';
}

function db_schema_statements(string $sql): array {
  $sql = preg_replace('/^\s*--.*$/m', '', $sql);
  $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
  $statements = [];
  foreach (explode(';', (string)$sql) as $statement) {
    $statement = trim($statement);
    if ($statement === '' || preg_match('/^(CREATE DATABASE|USE)\b/i', $statement)) continue;
    $statement = preg_replace('/^CREATE TABLE\s+(?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
    $statement = preg_replace('/^INSERT INTO\s+plans\b/i', 'INSERT IGNORE INTO plans', $statement);
    $statements[] = $statement;
  }
  return $statements;
}

function db_ensure_admin(PDO $pdo): void {
  $count = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
  if ($count > 0) return;
  if (trim(BOTORA_ADMIN_EMAIL) === '' || trim(BOTORA_ADMIN_PASSWORD) === '') return;
  $stmt = $pdo->prepare('INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
  $stmt->execute([BOTORA_ADMIN_NAME, BOTORA_ADMIN_EMAIL, password_hash(BOTORA_ADMIN_PASSWORD, PASSWORD_DEFAULT), 'superadmin']);
}

function db_ensure_schema(PDO $pdo, array $cfg): void {
  $schemaFile = __DIR__ . '/../sql/schema.sql';
  if (!DB_AUTO_MIGRATE || !is_readable($schemaFile)) return;

  // La connexion avec `dbname` a déjà confirmé que la base existe. La création
  // éventuelle est réalisée uniquement dans db() après une erreur "base absente",
  // afin de ne pas exiger CREATE DATABASE pour chaque utilisateur MySQL.
  foreach (db_schema_statements(file_get_contents($schemaFile)) as $statement) {
    try {
      $pdo->exec($statement);
    } catch (PDOException $e) {
      // Les index ou colonnes déjà présents ne doivent pas empêcher le panneau
      // de démarrer. Les erreurs de connexion restent, elles, bloquantes.
      if (!preg_match('/already exists|duplicate column|duplicate key name|duplicate entry/i', $e->getMessage())) throw $e;
    }
  }
  foreach ([
    'ALTER TABLE plans ADD COLUMN price_xof DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER price_eur',
    'UPDATE plans SET price_xof = ROUND(price_eur * 655.957, 2) WHERE price_xof = 0 AND price_eur > 0',
    'ALTER TABLE users MODIFY credits_balance DECIMAL(20,10) NOT NULL DEFAULT 0',
    'ALTER TABLE credit_logs MODIFY amount DECIMAL(20,10) NOT NULL',
    'ALTER TABLE credit_logs MODIFY balance_after DECIMAL(20,10) NOT NULL',
    'ALTER TABLE usage_logs MODIFY credits_used DECIMAL(20,10) DEFAULT 0'
  ] as $migration) {
    try { $pdo->exec($migration); } catch (PDOException $e) {
      if (!preg_match('/unknown column|doesn.t exist|no such table/i', $e->getMessage())) throw $e;
    }
  }
  $pdo->exec('CREATE TABLE IF NOT EXISTS botora_schema_versions (id TINYINT UNSIGNED PRIMARY KEY, schema_hash CHAR(64) NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)');
  $hash = hash_file('sha256', $schemaFile);
  $stmt = $pdo->prepare('INSERT INTO botora_schema_versions (id, schema_hash) VALUES (1, ?) ON DUPLICATE KEY UPDATE schema_hash = VALUES(schema_hash)');
  $stmt->execute([$hash]);
  db_ensure_admin($pdo);
}

function db(string $source = 'active'): PDO {
  $mode = DB_MODE === 'dual' ? 'local' : DB_MODE;
  if ($source === 'active') $source = $mode === 'online' ? 'online' : 'local';
  $source = strtolower($source) === 'online' ? 'online' : 'local';
  if (isset($GLOBALS['_botora_pdo'][$source])) return $GLOBALS['_botora_pdo'][$source];

  $cfg = db_config($source);
  try {
    $pdo = db_pdo($cfg, true);
  } catch (PDOException $firstError) {
    if (!DB_AUTO_MIGRATE) throw $firstError;
    // Si la base n'existe pas encore, la connexion serveur permet de la créer.
    try {
      $pdo = db_pdo($cfg, false);
      $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . db_identifier($cfg['name']) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
      $pdo = db_pdo($cfg, true);
    } catch (Throwable $e) {
      $GLOBALS['_botora_db_status'][$source] = ['ok' => false, 'message' => $e->getMessage()];
      throw $firstError;
    }
  }

  db_ensure_schema($pdo, $cfg);
  $GLOBALS['_botora_pdo'][$source] = $pdo;
  $GLOBALS['_botora_db_status'][$source] = ['ok' => true, 'message' => 'Base disponible et schéma vérifié.'];
  return $pdo;
}

function db_status(bool $initialize = false): array {
  $sources = DB_MODE === 'online' ? ['online'] : (DB_MODE === 'dual' ? ['local', 'online'] : ['local']);
  $result = [];
  foreach ($sources as $source) {
    try {
      if ($initialize) db($source);
      else db_pdo(db_config($source), true)->query('SELECT 1');
      $result[$source] = ['ok' => true, 'message' => 'Connexion disponible.'];
    } catch (Throwable $e) {
      $result[$source] = ['ok' => false, 'message' => $e->getMessage()];
    }
  }
  return $result;
}

function db_active_source(): string {
  return DB_MODE === 'online' ? 'online' : 'local';
}
