<?php
require_once __DIR__ . '/../includes/functions.php';
$started = microtime(true);
try {
  $status = db_status(true);
  $source = db_active_source();
  $ready = !empty($status[$source]['ok']);
  api_json([
    'ok' => $ready,
    'service' => 'botora-admin',
    'database' => ['source' => $source, 'ok' => $ready],
    'response_ms' => (int)round((microtime(true) - $started) * 1000),
    'time' => gmdate('c')
  ], $ready ? 200 : 503);
} catch (Throwable $e) {
  error_log('[Botora Health] ' . $e->getMessage());
  api_json(['ok'=>false,'service'=>'botora-admin','error'=>'Service indisponible.','response_ms'=>(int)round((microtime(true)-$started)*1000)],503);
}
