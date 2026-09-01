<?php
require_once __DIR__ . '/../includes/payment.php';
verify_service_key();
$data=payment_request_json(); $user=payment_user($data); $tokens=max(0,(int)($data['tokens_used']??0));
if (!$user || $tokens<=0) api_json(['ok'=>false,'error'=>'Utilisateur ou tokens invalides.'],400);
$db=db(); $conversion = credit_conversion($db); $credits=round(($tokens / $conversion['tokens_per_unit']) * $conversion['credits_per_unit'], 10); $db->beginTransaction();
try {
  $lock=$db->prepare('SELECT credits_balance,status FROM users WHERE id=? FOR UPDATE'); $lock->execute([$user['id']]); $row=$lock->fetch();
  if (!$row || !in_array($row['status'],['trial','active'],true)) throw new RuntimeException('Compte non actif.');
  if ((float)$row['credits_balance'] < $credits) { $db->rollBack(); api_json(['ok'=>false,'error'=>'Crédits insuffisants.','credits_balance'=>(float)$row['credits_balance'],'credits_required'=>$credits],402); }
  $new=(float)$row['credits_balance']-$credits;
  $db->prepare('UPDATE users SET credits_balance=?,updated_at=NOW() WHERE id=?')->execute([$new,$user['id']]);
  $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,balance_after) VALUES (?,?,?,?,?)')->execute([$user['id'],-$credits,'consume',$data['event_type']??'ai.usage',$new]);
  $usageStmt = $db->prepare('INSERT INTO usage_logs (user_id,event_type,credits_used,meta) VALUES (?,?,?,?)');
  $usageStmt->execute([$user['id'],$data['event_type']??'ai.usage',$credits,json_encode(['tokens_used'=>$tokens,'conversion'=>$conversion,'payload'=>$data['payload']??[]],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $usageId = (int)$db->lastInsertId();
  $db->commit(); api_json(['ok'=>true,'credits_balance'=>$new,'consumed'=>$credits,'tokens_used'=>$tokens,'usage_id'=>$usageId,'conversion'=>$conversion]);
} catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); error_log('[Botora Admin] consume: '.$e->getMessage()); api_log_set_error($e->getMessage()); api_json(['ok'=>false,'error'=>'Erreur interne de consommation.'],500); }
