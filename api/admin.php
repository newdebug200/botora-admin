<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$db = db();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$resource = strtolower(trim((string)($_GET['resource'] ?? 'overview')));
$data = payment_request_json();

function admin_user_payload(array $u): array {
  return ['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'company'=>$u['company'],'phone'=>$u['phone'],'license_key'=>$u['license_key'],'status'=>$u['status'],'credits_balance'=>(float)$u['credits_balance'],'created_at'=>$u['created_at'],'plan_id'=>$u['plan_id'] ? (int)$u['plan_id'] : null];
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
    $planId = !empty($data['plan_id']) ? (int)$data['plan_id'] : null;
    $status = in_array(($data['status'] ?? 'active'), ['trial','active','suspended','expired','banned'], true) ? $data['status'] : 'active';
    $trialDays = max(0, (int)($data['trial_days'] ?? 14));
    $trialEnd = $trialDays > 0 ? date('Y-m-d', strtotime("+$trialDays days")) : null;
    $license = generate_license();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (name,email,password_hash,company,phone,plan_id,license_key,status,credits_balance,trial_ends_at,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name,$email,$hash,trim((string)($data['company'] ?? '')),trim((string)($data['phone'] ?? '')) ?: null,$planId,$license,$status,0,$trialEnd,trim((string)($data['notes'] ?? '')) ?: null]);
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
    $db->beginTransaction();
    $lock=$db->prepare('SELECT credits_balance FROM users WHERE id=? FOR UPDATE'); $lock->execute([$user['id']]); $balance=(float)$lock->fetchColumn(); $new=max(0,$balance+$amount);
    $db->prepare('UPDATE users SET credits_balance=?,updated_at=NOW() WHERE id=?')->execute([$new,$user['id']]);
    $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')->execute([$user['id'],$amount,$amount>0?'add':'consume',$data['reason']??'Ajustement admin',$new]); $db->commit();
    api_json(['ok'=>true,'balance'=>$new]);
  }
  if ($resource === 'plans' && $method === 'GET') {
    $plans = $db->query('SELECT * FROM plans ORDER BY price_xof ASC, price_eur ASC')->fetchAll();
    foreach ($plans as &$plan) {
      if ((float)($plan['price_xof'] ?? 0) <= 0 && (float)($plan['price_eur'] ?? 0) > 0) $plan['price_xof'] = round((float)$plan['price_eur'] * 655.957, 2);
    }
    unset($plan);
    api_json(['ok'=>true,'plans'=>$plans]);
  }
  if ($resource === 'plans' && $method === 'POST') {
    $name=trim((string)($data['name']??'')); if ($name==='') api_json(['ok'=>false,'error'=>'Nom du plan requis.'],400);
    $slug=preg_replace('/[^a-z0-9]+/','-',strtolower($name));
    $stmt=$db->prepare('INSERT INTO plans (name,slug,credits_per_month,max_profiles,price_xof,is_active) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$name,$slug,(float)($data['credits_per_month']??$data['credits']??0),(int)($data['max_profiles']??1),max(0,(float)($data['price_xof']??$data['price']??0)),!empty($data['is_active'])?1:0]);
    api_json(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
  }
  if ($resource === 'plans' && $method === 'PATCH') {
    $id=(int)($data['id']??0); if (!$id) api_json(['ok'=>false,'error'=>'Plan requis.'],400); $fields=[];$params=[];
    foreach (['name','slug','credits_per_month','max_profiles','price_xof','is_active'] as $field) if (array_key_exists($field,$data)) {$fields[]="$field=?";$params[]=$data[$field];}
    if (!$fields) api_json(['ok'=>false,'error'=>'Aucune modification.'],400); $params[]=$id; $db->prepare('UPDATE plans SET '.implode(',',$fields).' WHERE id=?')->execute($params); api_json(['ok'=>true]);
  }
  if ($resource === 'plans' && $method === 'DELETE') {
    $id=(int)($_GET['id']??$data['id']??0); if (!$id) api_json(['ok'=>false,'error'=>'Plan requis.'],400); $db->prepare('UPDATE plans SET is_active=0 WHERE id=?')->execute([$id]); api_json(['ok'=>true]);
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
  api_json(['ok'=>false,'error'=>'Erreur interne de l’API admin.'],500);
}
