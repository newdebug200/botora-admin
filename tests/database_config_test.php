<?php

function expect_database_config(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function load_database_config(
  array $overrides = [],
  string $serverName = 'botora.bluelifetech.site',
  array $environmentOnly = [],
  string $httpHost = '',
  string $serverAddress = '192.0.2.10'
): array {
  $environment = array_merge([
    'APP_ENV' => 'auto',
    'APP_URL' => 'https://botora.example',
    'DB_MODE' => 'auto',
    'DB_HOST' => 'legacy-host',
    'DB_PORT' => '3307',
    'DB_NAME' => 'legacy-database',
    'DB_USER' => 'legacy-user',
    'DB_PASS' => 'legacy-password',
  ], $overrides);
  $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'botora-db-config-' . bin2hex(random_bytes(8));
  $fixtureIncludes = $fixtureRoot . DIRECTORY_SEPARATOR . 'includes';
  if (!mkdir($fixtureIncludes, 0700, true)) {
    throw new RuntimeException('Could not create isolated configuration fixture.');
  }
  if (!copy(__DIR__ . '/../config.php', $fixtureRoot . DIRECTORY_SEPARATOR . 'config.php')
    || !copy(__DIR__ . '/../includes/db.php', $fixtureIncludes . DIRECTORY_SEPARATOR . 'db.php')) {
    throw new RuntimeException('Could not prepare isolated configuration fixture.');
  }
  $bootstrap = '$_SERVER["SERVER_NAME"] = ' . var_export($serverName, true) . ';'
    . ' $_SERVER["HTTP_HOST"] = ' . var_export($httpHost, true) . ';'
    . ' $_SERVER["SERVER_ADDR"] = ' . var_export($serverAddress, true) . ';'
    . ' $_ENV = array_merge($_ENV, ' . var_export($environmentOnly, true) . ');'
    . ' require ' . var_export($fixtureIncludes . DIRECTORY_SEPARATOR . 'db.php', true) . ';'
    . '$dbError = "";'
    . 'if (DB_ONLINE_HOST === "" || DB_ONLINE_NAME === "" || DB_ONLINE_USER === "") {'
    . ' try { db_pdo(db_config("online"), false); }'
    . ' catch (RuntimeException $error) { $dbError = $error->getMessage(); }'
    . '}'
    . ' echo json_encode(["mode" => DB_MODE, "appEnv" => APP_ENV, "host" => DB_ONLINE_HOST, "port" => DB_ONLINE_PORT,'
    . ' "name" => DB_ONLINE_NAME, "user" => DB_ONLINE_USER, "pass" => DB_ONLINE_PASS,'
    . ' "localHost" => DB_LOCAL_HOST, "localName" => DB_LOCAL_NAME, "localUser" => DB_LOCAL_USER,'
    . ' "localPass" => DB_LOCAL_PASS,'
    . ' "dbError" => $dbError, "activeSource" => db_active_source(),'
    . ' "activeName" => db_config(db_active_source())["name"],'
    . ' "activeUser" => db_config(db_active_source())["user"]]);';
  try {
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $bootstrap], [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes, $fixtureRoot, $environment);
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
  } finally {
    @unlink($fixtureIncludes . DIRECTORY_SEPARATOR . 'db.php');
    @unlink($fixtureRoot . DIRECTORY_SEPARATOR . 'config.php');
    @rmdir($fixtureIncludes);
    @rmdir($fixtureRoot);
  }
}

$legacy = load_database_config();
expect_database_config($legacy['mode'] === 'online', 'Public production hostname should select the online database.');
expect_database_config($legacy['appEnv'] === 'online', 'APP_ENV=auto should follow the public production hostname.');
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
expect_database_config($missingOnline['host'] === 'localhost', 'Online MySQL host should default to localhost.');
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
expect_database_config($local['appEnv'] === 'local', 'APP_ENV=auto should follow the local hostname.');

$httpHost = load_database_config([], 'localhost', [], 'botora.bluelifetech.site');
expect_database_config($httpHost['mode'] === 'online', 'Public HTTP_HOST should select the online database when SERVER_NAME is empty.');

$localServerSpoof = load_database_config([], 'localhost', [], 'botora.bluelifetech.site', '127.0.0.1');
expect_database_config(
  $localServerSpoof['mode'] === 'local',
  'A public Host header must not switch a loopback development server to the production database.'
);

$explicitOnline = load_database_config(['DB_MODE' => 'online'], 'localhost', [], '', '127.0.0.1');
expect_database_config($explicitOnline['mode'] === 'online', 'Explicit DB_MODE=online should override local host detection.');
expect_database_config($explicitOnline['appEnv'] === 'online', 'APP_ENV=auto should follow an explicit online DB mode.');

$profiles = load_database_config([
  'DB_LOCAL_HOST' => 'localhost',
  'DB_LOCAL_NAME' => 'local-database',
  'DB_LOCAL_USER' => 'local-user',
  'DB_LOCAL_PASS' => 'local-password',
  'DB_ONLINE_HOST' => 'localhost',
  'DB_ONLINE_NAME' => 'production-database',
  'DB_ONLINE_USER' => 'production-user',
  'DB_ONLINE_PASS' => 'production-password',
]);
expect_database_config($profiles['mode'] === 'online', 'Public hostname should select the online profile.');
expect_database_config($profiles['host'] === 'localhost', 'Online profile should use localhost as its MySQL host.');
expect_database_config($profiles['name'] === 'production-database', 'Online mode should use the online database name.');
expect_database_config($profiles['user'] === 'production-user', 'Online mode should use the online database user.');
expect_database_config($profiles['pass'] === 'production-password', 'Online mode should use the online database password.');
expect_database_config($profiles['localHost'] === 'localhost', 'Local profile should use localhost as its MySQL host.');
expect_database_config($profiles['localName'] === 'local-database', 'Local mode should retain its independent database name.');
expect_database_config($profiles['localUser'] === 'local-user', 'Local mode should retain its independent database user.');
expect_database_config($profiles['localPass'] === 'local-password', 'Local mode should retain its independent database password.');
expect_database_config($profiles['activeName'] === 'production-database', 'Active online mode should route to online database credentials.');
expect_database_config($profiles['activeUser'] === 'production-user', 'Active online mode should route to the online database user.');

echo "Database configuration tests passed.\n";
