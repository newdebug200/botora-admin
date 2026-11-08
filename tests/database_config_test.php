<?php

function expect_database_config(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function load_database_config(
  array $overrides = [],
  string $serverName = 'botora.bluelifetech.site',
  array $environmentOnly = []
): array {
  $environment = array_merge([
    'APP_ENV' => 'online',
    'APP_URL' => 'https://botora.example',
    'DB_MODE' => 'auto',
    'DB_HOST' => 'legacy-host',
    'DB_PORT' => '3307',
    'DB_NAME' => 'legacy-database',
    'DB_USER' => 'legacy-user',
    'DB_PASS' => 'legacy-password',
  ], $overrides);
  $bootstrap = '$_SERVER["SERVER_NAME"] = ' . var_export($serverName, true) . ';'
    . ' $_ENV = array_merge($_ENV, ' . var_export($environmentOnly, true) . ');'
    . ' require ' . var_export(__DIR__ . '/../includes/db.php', true) . ';'
    . '$dbError = "";'
    . 'if (DB_ONLINE_HOST === "" || DB_ONLINE_NAME === "" || DB_ONLINE_USER === "") {'
    . ' try { db_pdo(db_config("online"), false); }'
    . ' catch (RuntimeException $error) { $dbError = $error->getMessage(); }'
    . '}'
    . ' echo json_encode(["mode" => DB_MODE, "host" => DB_ONLINE_HOST, "port" => DB_ONLINE_PORT,'
    . ' "name" => DB_ONLINE_NAME, "user" => DB_ONLINE_USER, "pass" => DB_ONLINE_PASS,'
    . ' "localHost" => DB_LOCAL_HOST, "localUser" => DB_LOCAL_USER, "dbError" => $dbError]);';
  $pipes = [];
  $process = proc_open([PHP_BINARY, '-r', $bootstrap], [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ], $pipes, dirname(__DIR__), $environment);
  if (!is_resource($process)) throw new RuntimeException('Could not start isolated PHP configuration test.');
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exitCode = proc_close($process);
  if ($exitCode !== 0) throw new RuntimeException('Configuration subprocess failed: ' . $stderr);
  $config = json_decode($stdout, true);
  if (!is_array($config)) throw new RuntimeException('Configuration subprocess returned invalid JSON.');
  return $config;
}

$legacy = load_database_config();
expect_database_config($legacy['mode'] === 'online', 'Public production hostname should select the online database.');
expect_database_config($legacy['host'] === 'legacy-host', 'Online host should fall back to legacy DB_HOST.');
expect_database_config($legacy['port'] === '3307', 'Online port should fall back to legacy DB_PORT.');
expect_database_config($legacy['name'] === 'legacy-database', 'Online name should fall back to legacy DB_NAME.');
expect_database_config($legacy['user'] === 'legacy-user', 'Online user should fall back to legacy DB_USER.');
expect_database_config($legacy['pass'] === 'legacy-password', 'Online password should fall back to legacy DB_PASS.');

$dedicated = load_database_config([
  'DB_ONLINE_HOST' => 'dedicated-host',
  'DB_ONLINE_PORT' => '3308',
  'DB_ONLINE_NAME' => 'dedicated-database',
  'DB_ONLINE_USER' => 'dedicated-user',
  'DB_ONLINE_PASS' => 'dedicated-password',
]);
expect_database_config($dedicated['host'] === 'dedicated-host', 'Dedicated online host should take precedence.');
expect_database_config($dedicated['port'] === '3308', 'Dedicated online port should take precedence.');
expect_database_config($dedicated['name'] === 'dedicated-database', 'Dedicated online name should take precedence.');
expect_database_config($dedicated['user'] === 'dedicated-user', 'Dedicated online user should take precedence.');
expect_database_config($dedicated['pass'] === 'dedicated-password', 'Dedicated online password should take precedence.');

$environmentOnly = load_database_config([], 'botora.bluelifetech.site', [
  'DB_ONLINE_HOST' => 'host-from-env-superglobal',
  'DB_ONLINE_NAME' => 'database-from-env-superglobal',
  'DB_ONLINE_USER' => 'user-from-env-superglobal',
  'DB_ONLINE_PASS' => 'password-from-env-superglobal',
]);
expect_database_config(
  $environmentOnly['host'] === 'host-from-env-superglobal',
  'Online host should be read from $_ENV when not available through getenv or $_SERVER.'
);
expect_database_config(
  $environmentOnly['name'] === 'database-from-env-superglobal',
  'Online database should be read from $_ENV when not available through getenv or $_SERVER.'
);
expect_database_config(
  $environmentOnly['user'] === 'user-from-env-superglobal',
  'Online user should be read from $_ENV when not available through getenv or $_SERVER.'
);
expect_database_config(
  $environmentOnly['pass'] === 'password-from-env-superglobal',
  'Online password should be read from $_ENV when not available through getenv or $_SERVER.'
);

$missingOnline = load_database_config([
  'DB_HOST' => '',
  'DB_PORT' => '',
  'DB_NAME' => '',
  'DB_USER' => '',
  'DB_PASS' => '',
]);
expect_database_config($missingOnline['mode'] === 'online', 'Public hostname should remain online without database credentials.');
expect_database_config($missingOnline['host'] === '', 'Missing online host must not fall back to localhost.');
expect_database_config($missingOnline['name'] === '', 'Missing online database must not fall back to a local database.');
expect_database_config($missingOnline['user'] === '', 'Missing online user must not fall back to root.');
expect_database_config($missingOnline['localHost'] === 'localhost', 'Local mode should retain the local host default.');
expect_database_config($missingOnline['localUser'] === 'root', 'Local mode should retain the local user default.');
expect_database_config(
  $missingOnline['dbError'] === 'Configuration de base de données incomplète : hôte, nom ou utilisateur manquant.',
  'Missing online credentials should fail clearly before attempting a local connection.'
);

$local = load_database_config([], 'localhost');
expect_database_config($local['mode'] === 'local', 'Local hostname should select the local database.');

echo "Database configuration tests passed.\n";
