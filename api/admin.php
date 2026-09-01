<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$db = db();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$resource = strtolower(trim((string)($_GET['resource'] ?? 'overview')));
$data = payment_request_json();

function admin_user_payload(array $u): array {
  return ['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'company'=>$u['company'],'phone'=>$u['phone'],'license_key'=>$u['license_key'],'status'=>$u['status'],'credits_balance'=>(float)$u['credits_balance'],'created_at'=>$u['created_at'],'plan_id'=>$u['plan_id'] ? (int)$u['plan_id'] : null,'trial_started_at'=>$u['trial_started_at'] ?? null,'trial_ends_at'=>$u['trial_ends_at'] ?? null,'trial_used'=>(bool)($u['trial_used'] ?? true),'subscription_started_at'=>$u['subscription_started_at'] ?? null,'subscription_ends_at'=>$u['subscription_ends_at'] ?? null];
}
function admin_read_body(): array { return $GLOBALS['data']; }

try {
  if ($resource === 'overview' && $method === 'GET') {
    $users = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $credits = (float)$db->query('SELECT COALESCE(SUM(credits_balance),0) FROM users')->fetchColumn();
    $payments = (int)$db->query("SELECT COUNT(*) FROM payment_transactions WHERE status='approved'")->fetchColumn();
    $revenue = (float)$db->query("SELECT COALESCE(SUM(amount_xof),0) FROM payment_transactions WHERE status='approved'")->fetchColumn();
    $plans = (int)$db->query('SELECT COUNT(*) FROM plans WHERE is_active=1')->fetchColumn();
    $activities = (int)$db->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
    api_json(['ok'=>true,'users'=>$users,'credits_balance'=>$credits,'approved_payments'=>$payments,'revenue_xof'=>$revenue,'active_plans'=>$plans,'activities'=>$activities]);
  }
  if ($resource === 'activities' && $method === 'GET') {
    $limit=min(200,max(1,(int)($_GET['limit']??50)));
    $stmt=$db->query('SELECT a.*,u.email,u.name FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT '.$limit);
    api_json(['ok'=>true,'activities'=>$stmt->fetchAll()]);
  }
  if ($resource === 'users' && $method === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT u.*, p.name plan_name FROM users u LEFT JOIN plans p ON p.id=u.plan_id';
    $params = [];
    if ($q !== '') { $sql .= ' WHERE u.name LIKE ? OR u.email LIKE ? OR u.license_key LIKE ?'; $params = ["%$q%","%$q%","%$q%"]; }
    $sql .= ' ORDER BY u.created_at DESC LIMIT 200';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $rows = array_map('admin_user_payload', $stmt->fetchAll());
    api_json(['ok'=>true,'users'=>$rows]);
  }
  if ($resource === 'users' && $method === 'POST') {
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) api_json(['ok'=>false,'error'=>'Nom, email valide et mot de passe de 8 caractères minimum requis.'],400);
    $exists = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1'); $exists->execute([$email]);
    if ($exists->fetchColumn()) api_json(['ok'=>false,'error'=>'Cet email est déjà utilisé.'],409);
    $planId = $db->query("SELECT id FROM plans WHERE slug='free' AND is_active=1 LIMIT 1")->fetchColumn() ?: null;
    $status = 'trial';
    $now = botora_server_now($db);
    $trialStart = $now->format('Y-m-d H:i:s');
    $trialEnd = $now->modify('+14 days')->format('Y-m-d H:i:s');
    $license = generate_license();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (name,email,password_hash,company,phone,plan_id,license_key,status,credits_balance,trial_started_at,trial_ends_at,trial_used,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name,$email,$hash,trim((string)($data['company'] ?? '')),trim((string)($data['phone'] ?? '')) ?: null,$planId,$license,$status,0,$trialStart,$trialEnd,1,trim((string)($data['notes'] ?? '')) ?: null]);
    $stmt = $db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([(int)$db->lastInsertId()]);
    api_json(['ok'=>true,'user'=>admin_user_payload($stmt->fetch())],201);
  }
  if ($resource === 'user' && $method === 'PATCH') {
    $user = payment_user($data); if (!$user) api_json(['ok'=>false,'error'=>'Utilisateur introuvable.'],404);
    $fields=[]; $params=[];
    foreach (['name','company','phone','status'] as $field) if (array_key_exists($field,$data)) { $fields[]="$field=?"; $params[] = trim((string)$data[$field]); }
    if (!$fields) api_json(['ok'=>false,'error'=>'Aucune modification.'],400);
    $params[]=(int)$user['id']; $db->prepare('UPDATE users SET '.implode(',', $fields).', updated_at=NOW() WHERE id=?')->execute($params);
    $stmt=$db->prepare('SELECT * FROM users WHERE id=?'); $stmt->execute([$user['id']]); api_json(['ok'=>true,'user'=>admin_user_payload($stmt->fetch())]);
  }
  if ($resource === 'credits' && $method === 'GET') {
    $user = payment_user($_GET); if (!$user) api_json(['ok'=>false,'error'=>'Utilisateur introuvable.'],404);
    $stmt=$db->prepare('SELECT id,amount,type,reason,balance_after,created_at FROM credit_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 100'); $stmt->execute([$user['id']]);
    api_json(['ok'=>true,'user'=>admin_user_payload($user),'transactions'=>$stmt->fetchAll()]);
  }
  if ($resource === 'credits' && $method === 'POST') {
    $user = payment_user($data); $amount=(float)($data['amount'] ?? 0); if (!$user || !is_finite($amount) || $amount===0.0) api_json(['ok'=>false,'error'=>'Utilisateur ou montant invalide.'],400);
    record_credit_adjustment((int)$user['id'], $amount, (string)($data['reason'] ?? 'Ajustement admin'), null);
    $balanceStmt = $db->prepare('SELECT credits_balance FROM users WHERE id=?'); $balanceStmt->execute([(int)$user['id']]);
    api_json(['ok'=>true,'balance'=>(float)$balanceStmt->fetchColumn()]);
  }
  if ($resource === 'credit-config' && $method === 'GET') {
    $config = credit_conversion($db);
    api_json(['ok'=>true,'credit_config'=>$config]);
  }
  if ($resource === 'credit-config' && in_array($method, ['POST','PUT','PATCH'], true)) {
    $creditsPerUnit = (float)($data['credits_per_unit'] ?? 0);
    $xofPerUnit = (float)($data['xof_per_unit'] ?? -1);
    if (!is_finite($creditsPerUnit) || $creditsPerUnit <= 0 || !is_finite($xofPerUnit) || $xofPerUnit <= 0) api_json(['ok'=>false,'error'=>'Conversion crédits/XOF invalide.'],400);
    $db->prepare('INSERT INTO credit_config (id,tokens_per_unit,credits_per_unit,xof_per_unit) VALUES (1,100000,?,?,?) ON DUPLICATE KEY UPDATE tokens_per_unit=100000,credits_per_unit=VALUES(credits_per_unit),xof_per_unit=VALUES(xof_per_unit)')->execute([$creditsPerUnit,$xofPerUnit]);
    api_json(['ok'=>true,'credit_config'=>credit_conversion($db)]);
  }
  if ($resource === 'credit-usage' && $method === 'GET') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(1000, max(50, (int)($_GET['per_page'] ?? 50)));
    $where = []; $params = [];
    if (!empty($_GET['user_id'])) { $where[]='ul.user_id=?'; $params[]=(int)$_GET['user_id']; }
    if (!empty($_GET['email'])) { $where[]='u.email=?'; $params[]=strtolower(trim((string)$_GET['email'])); }
    $whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';
    $countStmt=$db->prepare("SELECT COUNT(*) FROM usage_logs ul LEFT JOIN users u ON u.id=ul.user_id{$whereSql}"); $countStmt->execute($params); $total=(int)$countStmt->fetchColumn();
    $offset=($page-1)*$perPage;
    $stmt=$db->prepare("SELECT ul.id,ul.user_id,ul.event_type,ul.credits_used,ul.meta,ul.logged_at,u.name,u.email FROM usage_logs ul LEFT JOIN users u ON u.id=ul.user_id{$whereSql} ORDER BY ul.logged_at DESC,ul.id DESC LIMIT {$perPage} OFFSET {$offset}"); $stmt->execute($params);
    api_json(['ok'=>true,'usage'=>$stmt->fetchAll(),'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>max(1,(int)ceil($total/$perPage))]]);
  }
  if ($resource === 'subscription' && $method === 'GET') {
    $config = $db->query('SELECT id,price_xof,duration_days,is_active,updated_at FROM subscription_config WHERE id=1 LIMIT 1')->fetch() ?: ['id'=>1,'price_xof'=>0,'duration_days'=>365,'is_active'=>0,'updated_at'=>null];
    api_json(['ok'=>true,'subscription'=>['price_xof'=>(float)$config['price_xof'],'duration_days'=>(int)$config['duration_days'],'is_active'=>(bool)$config['is_active'],'updated_at'=>$config['updated_at']]]);
  }
  if ($resource === 'subscription' && in_array($method, ['POST','PUT','PATCH'], true)) {
    $price = (float)($data['price_xof'] ?? -1);
    $duration = 365;
    $active = array_key_exists('is_active', $data) ? (int)filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : 1;
    if (!is_finite($price) || $price < 0) api_json(['ok'=>false,'error'=>'Prix d’abonnement invalide.'],400);
    $db->prepare('INSERT INTO subscription_config (id,price_xof,duration_days,is_active) VALUES (1,?,?,?) ON DUPLICATE KEY UPDATE price_xof=VALUES(price_xof),duration_days=VALUES(duration_days),is_active=VALUES(is_active)')->execute([$price,$duration,$active]);
    api_json(['ok'=>true,'subscription'=>['price_xof'=>$price,'duration_days'=>$duration,'is_active'=>(bool)$active]]);
  }
  if ($resource === 'features' && $method === 'GET') api_json(['ok'=>true,'features'=>$db->query('SELECT * FROM platform_features ORDER BY feature_key')->fetchAll()]);
  if ($resource === 'features' && $method === 'PUT') {
    foreach (($data['features'] ?? $data) as $key=>$value) {
      if (!preg_match('/^[a-z0-9_]+$/', (string)$key) || $key==='ok') continue;
      $stmt=$db->prepare('UPDATE platform_features SET enabled=?,updated_at=NOW() WHERE feature_key=?'); $stmt->execute([(int)(filter_var($value,FILTER_VALIDATE_BOOLEAN)), $key]);
    }
    api_json(['ok'=>true]);
  }
  api_json(['ok'=>false,'error'=>'Ressource ou méthode non supportée.'],405);
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  error_log('[Botora Admin] admin API: '.$e->getMessage());
  api_log_set_error($e->getMessage());
  api_json(['ok'=>false,'error'=>'Erreur interne de l’API admin.'],500);
}
