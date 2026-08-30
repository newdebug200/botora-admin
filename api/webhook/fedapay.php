<?php
require_once __DIR__ . '/../../includes/payment.php';

// Le SDK officiel est optionnel en développement et obligatoire lorsque la signature est activée.
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
$raw = (string)($GLOBALS['_botora_api_log']['raw_body'] ?? '');
if ($raw === '') $raw = '{}';
if (defined('FEDAPAY_WEBHOOK_SECRET') && FEDAPAY_WEBHOOK_SECRET !== '') {
  $signature = $_SERVER['HTTP_X_FEDAPAY_SIGNATURE'] ?? '';
  try {
    if (!class_exists('FedaPay\\Webhook')) throw new RuntimeException('SDK FedaPay PHP absent pour vérifier la signature.');
    \FedaPay\Webhook::constructEvent($raw, $signature, FEDAPAY_WEBHOOK_SECRET);
  } catch (Throwable $e) {
    error_log('[Botora Admin] FedaPay signature: ' . $e->getMessage());
    api_json(['ok'=>false,'error'=>'Signature webhook FedaPay invalide.'], 400);
  }
}
$event = json_decode($raw, true);
if (!is_array($event)) api_json(['ok' => false, 'error' => 'Payload JSON invalide.'], 400);
$payload = $event['object'] ?? $event['data'] ?? $event['transaction'] ?? $event;
if (isset($payload['transaction']) && is_array($payload['transaction'])) $payload = $payload['transaction'];
$eventType = strtolower((string)($event['name'] ?? $event['type'] ?? $event['event'] ?? ''));
$externalId = (string)($payload['id'] ?? '');
$eventStatus = strtolower((string)($payload['status'] ?? ''));
$eventVersion = (string)($payload['updated_at'] ?? $payload['created_at'] ?? '');
$eventId = $eventType . ':' . $externalId . ':' . ($eventStatus ?: 'unknown') . ':' . ($eventVersion ?: hash('sha256', $raw));
if ($externalId === '') api_json(['ok' => true, 'received' => true]);
try {
  // Le payload webhook n’est jamais considéré comme preuve suffisante :
  // la transaction est relue directement depuis FedaPay.
  $transaction = fedapay_unwrap(fedapay_request('GET', '/transactions/' . rawurlencode($externalId)));
  $stmt = db()->prepare('SELECT id FROM payment_transactions WHERE external_id=? LIMIT 1');
  $stmt->execute([$externalId]);
  $paymentId = (int)$stmt->fetchColumn();
  if ($paymentId) payment_credit_approved($paymentId, $transaction, $eventId, $eventType, $raw);
  api_json(['ok' => true, 'received' => true]);
} catch (Throwable $e) {
  error_log('[Botora Admin] FedaPay webhook: ' . $e->getMessage());
  api_json(['ok' => false, 'error' => 'Webhook temporairement indisponible.'], 500);
}
