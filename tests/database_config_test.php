<?php

function expect_database_config(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

function load_database_config(array $overrides = []): array {
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
  $bootstrap = '$_SERVER["SERVER_NAME"] = "botora.bluelifetech.site";'
    . ' require ' . var_export(__DIR__ . '/../config.php', true) . ';'
    . ' echo json_encode(["mode" => DB_MODE, "host" => DB_ONLINE_HOST, "port" => DB_ONLINE_PORT,'
    . ' "name" => DB_ONLINE_NAME, "user" => DB_ONLINE_USER, "pass" => DB_ONLINE_PASS]);';
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

echo "Database configuration tests passed.\n";
