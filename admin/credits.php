<?php
$pageTitle = 'Crédits'; $activePage = 'credits';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_superadmin();
$db = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $userId=(int)($_POST['user_id']??0); $amount=(float)($_POST['amount']??0); $reason=trim((string)($_POST['reason']??'Ajustement admin')) ?: 'Ajustement admin';
  if ($userId && is_finite($amount) && $amount !== 0.0) {
    try {
      $db->beginTransaction(); $lock=$db->prepare('SELECT credits_balance FROM users WHERE id=? FOR UPDATE'); $lock->execute([$userId]); $balance=$lock->fetchColumn();
      if ($balance === false) throw new RuntimeException('Utilisateur introuvable.');
      $new=max(0,(float)$balance+$amount); $db->prepare('UPDATE users SET credits_balance=?,updated_at=NOW() WHERE id=?')->execute([$new,$userId]); $db->prepare('INSERT INTO credit_logs (user_id,amount,type,reason,admin_id,balance_after) VALUES (?,?,?,?,?,?)')->execute([$userId,$amount,$amount>0?'add':'consume',$reason,$_SESSION['admin_id'],$new]); $db->commit(); flash_set('success','Solde mis à jour : '.number_format($new,3,',',' ').' crédits.');
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); flash_set('danger','Impossible de modifier le solde.'); }
  } else flash_set('danger','Sélectionnez un utilisateur et saisissez un montant différent de zéro.');
  header('Location: '.APP_URL.'/admin/credits.php'); exit;
}
$users=$db->query('SELECT id,name,email,status,credits_balance FROM users ORDER BY name ASC')->fetchAll();
$logs=$db->query('SELECT l.*,u.name,u.email FROM credit_logs l JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 100')->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><div class="text-uppercase small fw-bold text-success">Administration centrale</div><h1 class="mb-1">Crédits</h1><p class="text-muted mb-0">Le solde officiel utilisé par la plateforme et les consommations IA.</p></div><span class="badge text-bg-success px-3 py-2">1 crédit = 100 000 tokens = 120 F CFA</span></div>
<div class="row g-4">
  <div class="col-12 col-xl-4"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Ajuster un solde</h2><p class="small text-muted">Utilisez une valeur positive pour créditer et négative pour débiter.</p><form method="POST" class="vstack gap-3"><div><label for="credit-user" class="form-label">Utilisateur</label><select id="credit-user" name="user_id" class="form-select js-user-select" data-placeholder="Choisir un utilisateur" required><option value=""></option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?> — <?= h($u['email']) ?> (<?= number_format($u['credits_balance'],3,',',' ') ?>)</option><?php endforeach; ?></select></div><div><label for="credit-amount" class="form-label">Montant de l’ajustement</label><input id="credit-amount" name="amount" type="number" step="0.0000000001" class="form-control" placeholder="Ex. 10.000 ou -2.500" required></div><div><label for="credit-reason" class="form-label">Motif</label><input id="credit-reason" name="reason" class="form-control" value="Ajustement administrateur"></div><button class="btn btn-success">Enregistrer l’ajustement</button></form></div></div></div>
  <div class="col-12 col-xl-8"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Soldes utilisateurs</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Utilisateur</th><th>Statut</th><th class="text-end">Solde</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?= h($u['name']) ?></strong><small class="d-block text-muted"><?= h($u['email']) ?></small></td><td><?= status_badge($u['status']) ?></td><td class="text-end fw-bold text-success"><?= number_format($u['credits_balance'],3,',',' ') ?></td></tr><?php endforeach; ?><?php if(!$users): ?><tr><td colspan="3" class="text-center text-muted">Aucun utilisateur synchronisé.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
</div>
<div class="card border-0 shadow-sm mt-4"><div class="card-body"><h2 class="h5">Journal récent</h2><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Utilisateur</th><th>Type</th><th>Motif</th><th class="text-end">Montant</th><th class="text-end">Après opération</th></tr></thead><tbody><?php foreach($logs as $l): ?><tr><td><?= format_datetime($l['created_at']) ?></td><td><?= h($l['email']) ?></td><td><?= h($l['type']) ?></td><td><?= h($l['reason']) ?></td><td class="text-end <?= $l['amount']>=0?'text-success':'text-danger' ?>"><?= number_format($l['amount'],3,',',' ') ?></td><td class="text-end"><?= number_format($l['balance_after'],3,',',' ') ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
