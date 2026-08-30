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

$findTransaction = static function ($value) use (&$findTransaction): array {
  if (!is_array($value)) return [];
  foreach (['entity', 'transaction', 'data', 'v1', 'object'] as $key) {
    if (isset($value[$key])) {
      $found = $findTransaction($value[$key]);
      if ($found) return $found;
    }
  }
  $isEnvelope = isset($value['name']) || isset($value['type']) || isset($value['event']);
  if (isset($value['id']) && (!$isEnvelope || isset($value['status']) || isset($value['custom_metadata']) || isset($value['metadata']))) return $value;
  return [];
};

$payload = $findTransaction($event) ?: $event;
$eventType = strtolower(trim((string)($event['name'] ?? $event['type'] ?? $event['event'] ?? '')));
$eventType = preg_replace('/^event\./', '', $eventType);
$externalId = (string)($payload['id'] ?? $payload['transaction_id'] ?? $event['object_id'] ?? '');
$statusByEvent = [
  'transaction.canceled' => 'canceled',
  'transaction.declined' => 'declined',
  'transaction.deleted' => 'deleted',
  'transaction.approved' => 'approved',
  'canceled' => 'canceled',
  'declined' => 'declined',
  'deleted' => 'deleted',
  'approved' => 'approved',
];
$eventStatus = $statusByEvent[$eventType] ?? strtolower((string)($payload['status'] ?? ''));
$eventVersion = (string)($payload['updated_at'] ?? $payload['created_at'] ?? '');
$eventId = $eventType . ':' . $externalId . ':' . ($eventStatus ?: 'unknown') . ':' . ($eventVersion ?: hash('sha256', $raw));
if ($externalId === '') api_json(['ok' => false, 'received' => true, 'processed' => false, 'error' => 'Identifiant de transaction absent du webhook.'], 400);
try {
  // Le payload webhook n’est jamais considéré comme preuve suffisante :
  // la transaction est relue directement depuis FedaPay.
  $transaction = fedapay_unwrap(fedapay_request('GET', '/transactions/' . rawurlencode($externalId)));
  if (isset($statusByEvent[$eventType])) $transaction['status'] = $statusByEvent[$eventType];
  $stmt = db()->prepare('SELECT id FROM payment_transactions WHERE external_id=? LIMIT 1');
  $stmt->execute([$externalId]);
  $paymentId = (int)$stmt->fetchColumn();
  if (!$paymentId && !empty($payload['custom_metadata']['botora_payment_id'])) {
    $paymentId = (int)$payload['custom_metadata']['botora_payment_id'];
  }
  if (!$paymentId) api_json(['ok' => false, 'received' => true, 'processed' => false, 'error' => 'Transaction non liée à un paiement Botora.'], 404);
  $result = payment_credit_approved($paymentId, $transaction, $eventId, $eventType, $raw);
  api_json(['ok' => true, 'received' => true, 'processed' => true, 'payment_id' => $paymentId, 'status' => $result['status'] ?? $eventStatus]);
} catch (Throwable $e) {
  error_log('[Botora Admin] FedaPay webhook: ' . $e->getMessage());
  api_json(['ok' => false, 'error' => 'Webhook temporairement indisponible.'], 500);
}
