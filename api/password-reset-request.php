<?php
require_once __DIR__ . '/../includes/payment.php';
verify_password_reset_service_key();
$data = payment_request_json();
$email = strtolower(trim((string)($data['email'] ?? '')));
$generic = ['ok' => true, 'message' => 'Si cette adresse existe, un code de récupération a été envoyé.'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_json($generic);

$db = db();
$userStmt = $db->prepare('SELECT id,name,email FROM users WHERE email=? LIMIT 1');
$userStmt->execute([$email]);
$user = $userStmt->fetch();
if (!$user) api_json($generic);

$recentStmt = $db->prepare('SELECT COUNT(*) FROM password_reset_requests WHERE email=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
$recentStmt->execute([$email]);
if ((int)$recentStmt->fetchColumn() >= 3) api_json($generic);

$code = (string)random_int(100000, 999999);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expiresAt = (new DateTimeImmutable('now'))->modify('+15 minutes')->format('Y-m-d H:i:s');
$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null;
$db->prepare('UPDATE password_reset_requests SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
$db->prepare('INSERT INTO password_reset_requests (user_id,email,code_hash,expires_at,request_ip) VALUES (?,?,?,?,?)')->execute([(int)$user['id'], $email, $codeHash, $expiresAt, $ip]);

$host = parse_url((string)(defined('APP_URL') ? APP_URL : ''), PHP_URL_HOST) ?: 'botora';
$from = (defined('MAIL_FROM') && MAIL_FROM !== '') ? MAIL_FROM : (getenv('MAIL_FROM_EMAIL') ?: ('no-reply@' . preg_replace('/[^a-z0-9.-]/i', '', $host)));
$fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Botora';
$subject = 'Votre code de récupération Botora';
$body = "Bonjour " . ((string)($user['name'] ?: '')) . ",\n\n";
$body .= "Votre code de récupération Botora est : {$code}\n\n";
$body .= "Ce code expire dans 15 minutes et ne peut être utilisé qu'une seule fois.\n";
$body .= "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.\n\nL'équipe Botora";
$headers = "From: {$fromName} <{$from}>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
if (!@mail($email, $subject, $body, $headers)) error_log('[Botora Password Reset] Envoi mail échoué pour ' . $email);
api_json($generic);
