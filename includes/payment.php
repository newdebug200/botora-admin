<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';

// Les erreurs serveur doivent rester visibles sous forme JSON pour le client
// Node.js, tout en conservant le détail uniquement dans le journal PHP.
set_exception_handler(function (Throwable $e): void {
  error_log('[Botora API] ' . $e->getMessage());
  api_log_set_error($e->getMessage());
  $message = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Erreur interne de configuration du serveur.';
  api_json(['ok' => false, 'error' => $message], 500);
});

function payment_request_json(): array {
  $payload = $GLOBALS['_botora_api_log']['request_data'] ?? [];
  return is_array($payload) ? $payload : [];
}

function verify_service_key(): void {
  // Contrôle interservices désactivé temporairement en développement.
  // Les échanges seront protégés par clé après stabilisation des contrats HTTP.
}

function payment_user(array $data): array {
  $db = db();
  if (!empty($data['license_key'])) {
    $stmt = $db->prepare('SELECT * FROM users WHERE license_key = ? LIMIT 1');
    $stmt->execute([trim((string)$data['license_key'])]);
  } elseif (!empty($data['email'])) {
    $email = strtolower(trim((string)$data['email']));
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    if ($existing) {
      api_log_set_user((int)$existing['id']);
      return $existing;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [];
    $name = trim((string)($data['name'] ?? 'Client Botora')) ?: 'Client Botora';
    $insert = $db->prepare('INSERT INTO users (name,email,license_key,status) VALUES (?,?,?,?)');
    $insert->execute([$name, $email, generate_license(), 'active']);
    $newId = (int)$db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$newId]);
  } else {
    return [];
  }
  $user = $stmt->fetch() ?: [];
  if ($user) api_log_set_user((int)$user['id']);
  return $user;
}

function fedapay_sdk_payload_to_array($value): array {
  if ($value === null) return [];
  if (is_array($value)) return $value;
  if (is_object($value)) {
    if (method_exists($value, '__toArray')) return $value->__toArray(true);
    if (method_exists($value, 'jsonSerialize')) return (array)$value->jsonSerialize();
    return (array)$value;
  }
  return [];
}

function fedapay_bootstrap(): void {
  if (FEDAPAY_SECRET_KEY === '') throw new RuntimeException('FEDAPAY_SECRET_KEY non configurée.');
  $base = preg_replace('#/v1/?$#i', '', trim((string)FEDAPAY_API_URL));
  \FedaPay\FedaPay::setApiKey(FEDAPAY_SECRET_KEY);
  \FedaPay\FedaPay::setApiBase($base !== '' ? $base : 'https://api.fedapay.com');
}

function fedapay_request(string $method, string $path, ?array $payload = null): array {
  fedapay_bootstrap();
  $segments = array_values(array_filter(explode('/', trim((string)$path, '/')), static fn($segment) => $segment !== ''));
  if ($segments === []) throw new RuntimeException('Endpoint FedaPay vide.');
  if ($segments[0] !== 'transactions') throw new RuntimeException('Endpoint FedaPay non supporté par le SDK: ' . $path);

  $transactionId = $segments[1] ?? null;
  $action = $segments[2] ?? null;

  try {
    switch (strtoupper($method)) {
      case 'POST':
        if ($transactionId === null && $action === null) {
          $resource = \FedaPay\Transaction::create($payload ?? []);
          return fedapay_sdk_payload_to_array($resource);
        }
        if ($transactionId !== null && $action === 'token') {
          $resource = \FedaPay\Transaction::generateTokenFromId((string)$transactionId, $payload ?? [], []);
          return fedapay_sdk_payload_to_array($resource);
        }
        throw new RuntimeException('Endpoint FedaPay POST non pris en charge: ' . $path);

      case 'GET':
        if ($transactionId === null) throw new RuntimeException('Transaction FedaPay manquante dans l’URL: ' . $path);
        $resource = \FedaPay\Transaction::retrieve((string)$transactionId, [], []);
        return fedapay_sdk_payload_to_array($resource);

      default:
        throw new RuntimeException('Méthode HTTP FedaPay non supportée: ' . $method);
    }
  } catch (\Throwable $e) {
    if ($e instanceof RuntimeException) throw $e;
    throw new RuntimeException('FedaPay SDK inaccessible: ' . $e->getMessage(), 0, $e);
  }
}

function fedapay_unwrap($data): array {
  $payload = fedapay_sdk_payload_to_array($data);
  if (isset($payload['v1']) && is_array($payload['v1'])) return $payload['v1'];
  if (isset($payload['transaction']) && is_array($payload['transaction'])) return $payload['transaction'];
  return $payload;
}

function payment_credit_approved(int $paymentId, array $transaction, string $eventId, string $eventType, string $rawPayload): array {
  $db = db();
  $db->beginTransaction();
  try {
    $stmt = $db->prepare('SELECT * FROM payment_transactions WHERE id = ? FOR UPDATE');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) throw new RuntimeException('Paiement introuvable.');
    try {
      $db->prepare('INSERT INTO payment_webhook_events (payment_id,event_id,event_type,payload) VALUES (?,?,?,?)')
        ->execute([$paymentId, $eventId, $eventType, $rawPayload]);
    } catch (PDOException $e) {
      if ((string)$e->getCode() !== '23000') throw $e;
      $db->commit();
      return ['status' => $payment['status'], 'already_processed' => true, 'credits' => (float)$payment['credits']];
    }
    $status = strtolower((string)($transaction['status'] ?? 'pending'));
    if ($payment['status'] === 'approved') {
      $db->commit();
      return ['status' => 'approved', 'already_processed' => true, 'credits' => (float)$payment['credits']];
    }
    $db->prepare('UPDATE payment_transactions SET status=?, approved_at=CASE WHEN ?="approved" THEN NOW() ELSE approved_at END, updated_at=NOW() WHERE id=?')
      ->execute([$status, $status, $paymentId]);
    if ($status === 'approved') {
      $db->prepare('UPDATE users SET credits_balance = credits_balance + ?, updated_at = NOW() WHERE id = ?')->execute([$payment['credits'], $payment['user_id']]);
      $balanceStmt = $db->prepare('SELECT credits_balance FROM users WHERE id=?');
      $balanceStmt->execute([$payment['user_id']]);
      $balance = $balanceStmt->fetchColumn();
      $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')
        ->execute([$payment['user_id'], $payment['credits'], 'add', 'Recharge FedaPay ' . $payment['external_id'], $balance]);
    }
    $db->commit();
    return ['status' => $status, 'already_processed' => false, 'credits' => $status === 'approved' ? (float)$payment['credits'] : 0];
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}
