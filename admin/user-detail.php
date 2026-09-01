<?php
$pageTitle = 'Détail client'; $activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/admin/users.php'); exit; }

$user = $db->prepare('SELECT u.* FROM users u WHERE u.id=?');
$user->execute([$id]);
$user = $user->fetch();
if (!$user) { http_response_code(404); die('Utilisateur introuvable.'); }


// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'update_identity') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $company = trim((string)($_POST['company'] ?? '')) ?: null;
    $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      flash_set('error', 'Nom et email valide requis.');
    } else {
      $check = $db->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1'); $check->execute([$email, $id]);
      if ($check->fetchColumn()) flash_set('error', 'Cet email est déjà utilisé.');
      else if ($password !== '' && strlen($password) < 8) flash_set('error', 'Le nouveau mot de passe doit contenir au moins 8 caractères.');
      else {
        if ($password !== '') $db->prepare('UPDATE users SET name=?,email=?,company=?,phone=?,password_hash=?,updated_at=NOW() WHERE id=?')->execute([$name,$email,$company,$phone,password_hash($password,PASSWORD_DEFAULT),$id]);
        else $db->prepare('UPDATE users SET name=?,email=?,company=?,phone=?,updated_at=NOW() WHERE id=?')->execute([$name,$email,$company,$phone,$id]);
        flash_set('success', 'Informations utilisateur mises à jour.');
      }
    }
  } elseif ($action === 'add_credits') {
    $amount = (int)($_POST['amount'] ?? 0);
    $direction = ($_POST['direction'] ?? 'add') === 'remove' ? 'remove' : 'add';
    $signedAmount = $direction === 'remove' ? -abs($amount) : abs($amount);
    $reason = trim($_POST['reason'] ?? ($direction === 'remove' ? 'Retrait manuel admin' : 'Ajout manuel admin'));
    if ($amount > 0) {
      record_credit_adjustment($id, $signedAmount, $reason, (int)$_SESSION['admin_id']);
      flash_set('success', $direction === 'remove' ? "{$amount} crédits retirés." : "{$amount} crédits ajoutés.");
    }
  } elseif ($action === 'set_control_center_access') {
    $enabled = filter_var($_POST['control_center_access'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $db->prepare('UPDATE users SET control_center_access=?, updated_at=NOW() WHERE id=?')->execute([$enabled, $id]);
    flash_set('success', $enabled ? 'Accès au Centre de contrôle accordé.' : 'Accès au Centre de contrôle retiré.');
  } elseif ($action === 'set_status') {
    $s = $_POST['status'] ?? '';
    if (in_array($s, ['trial','active','suspended','expired','banned'])) {
      $db->prepare('UPDATE users SET status=?, updated_at=NOW() WHERE id=?')->execute([$s, $id]);
      flash_set('success', 'Statut mis à jour.');
    }
  } elseif ($action === 'extend_trial') {
    $days = (int)($_POST['days'] ?? 7);
    $db->prepare('UPDATE users SET trial_ends_at=DATE_ADD(IFNULL(trial_ends_at, CURDATE()), INTERVAL ? DAY), updated_at=NOW() WHERE id=?')->execute([$days, $id]);
    flash_set('success', "Essai prolongé de {$days} jours.");
  } elseif ($action === 'update_notes') {
    $notes = trim($_POST['notes'] ?? '');
    $db->prepare('UPDATE users SET notes=?, updated_at=NOW() WHERE id=?')->execute([$notes, $id]);
    flash_set('success', 'Notes mises à jour.');
  }
  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    api_json(['ok'=>true,'message'=>flash_get()['msg'] ?? 'Action exécutée.']);
  }
  header('Location: ' . APP_URL . '/admin/user-detail.php?id=' . $id); exit;
}

// Reload fresh
$user = $db->prepare('SELECT u.* FROM users u WHERE u.id=?');
$user->execute([$id]);
$user = $user->fetch();

$credit_logs = $db->prepare('SELECT cl.*, a.name as admin_name FROM credit_logs cl LEFT JOIN admins a ON a.id=cl.admin_id WHERE cl.user_id=? ORDER BY cl.created_at DESC LIMIT 20');
$credit_logs->execute([$id]);
$credit_logs = $credit_logs->fetchAll();

$usage = $db->prepare('SELECT event_type, COUNT(*) as cnt, SUM(credits_used) as total_credits FROM usage_logs WHERE user_id=? GROUP BY event_type ORDER BY cnt DESC');
$usage->execute([$id]);
$usage = $usage->fetchAll();

$activations = $db->prepare('SELECT * FROM activations WHERE user_id=? ORDER BY last_seen DESC');
$activations->execute([$id]);
$activations = $activations->fetchAll();

$activities = $db->prepare('SELECT event_type,tokens_used,credits_used,payload,created_at FROM activity_logs WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
$activities->execute([$id]);
$activities = $activities->fetchAll();

$user_transactions = $db->prepare('SELECT pt.*, a.name AS admin_name FROM payment_transactions pt LEFT JOIN admins a ON a.id=pt.admin_id WHERE pt.user_id=? ORDER BY pt.created_at DESC LIMIT 100');
$user_transactions->execute([$id]);
$user_transactions = $user_transactions->fetchAll();

$trial_days_left = $user['trial_ends_at'] ? (int)ceil((strtotime($user['trial_ends_at']) - time()) / 86400) : null;
?>
<div class="page-header">
  <div>
    <a href="<?= APP_URL ?>/admin/users.php" class="breadcrumb-link">← Utilisateurs</a>
    <h1><?= h($user['name']) ?> <?= status_badge($user['status']) ?></h1>
    <div class="text-muted"><?= h($user['email']) ?> · Clé: <code><?= h($user['license_key']) ?></code></div>
  </div>
</div>

<div class="detail-grid">

  <!-- Info card -->
  <div class="card">
    <div class="card-header"><h2>Informations</h2></div>
    <div class="info-list">
      <div class="info-row"><span>Accès</span><strong><?= h(ucfirst((string)$user['status'])) ?></strong></div>
      <div class="info-row"><span>Centre de contrôle</span><strong><?= $user['control_center_access'] === null ? 'Héritage du rôle local' : ($user['control_center_access'] ? 'Autorisé' : 'Refusé') ?></strong></div>
      <div class="info-row"><span>Crédits</span><strong class="credits-display <?= $user['credits_balance']<=0?'zero':($user['credits_balance']<20?'low':'') ?>"><?= number_format($user['credits_balance']) ?></strong></div>
      <div class="info-row"><span>Essai expire</span><strong><?= $user['trial_ends_at'] ? format_date($user['trial_ends_at']).' ('.($trial_days_left >= 0 ? $trial_days_left.'j' : 'Expiré').')' : '—' ?></strong></div>
      <div class="info-row"><span>Abonnement annuel</span><strong><?= !empty($user['subscription_ends_at']) ? format_date($user['subscription_ends_at']) : 'Aucun' ?></strong></div>
      <div class="info-row"><span>Inscrit le</span><strong><?= format_datetime($user['created_at']) ?></strong></div>
      <div class="info-row"><span>Mis à jour</span><strong><?= format_datetime($user['updated_at']) ?></strong></div>
    </div>
  </div>

  <!-- Identity -->
  <div class="card">
    <div class="card-header"><h2>Informations et accès</h2></div>
    <form method="POST" class="form-body js-admin-action" data-action-label="Informations mises à jour">
      <input type="hidden" name="action" value="update_identity">
      <div class="form-group"><label>Nom complet</label><input type="text" name="name" class="form-control" value="<?= h($user['name']) ?>" required></div>
      <div class="form-group"><label>Email de connexion</label><input type="email" name="email" class="form-control" value="<?= h($user['email']) ?>" required></div>
      <div class="form-row"><div class="form-group"><label>Entreprise</label><input type="text" name="company" class="form-control" value="<?= h($user['company'] ?? '') ?>"></div><div class="form-group"><label>Téléphone</label><input type="text" name="phone" class="form-control" value="<?= h($user['phone'] ?? '') ?>"></div></div>
      <div class="form-group"><label>Nouveau mot de passe</label><input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password"><small class="text-muted">Laisser vide pour conserver le mot de passe actuel.</small></div>
      <button type="submit" class="btn btn-primary">Enregistrer les informations</button>
    </form>
  </div>

  <div id="admin-action-alert" class="alert d-none" role="alert"></div>

  <!-- Add credits -->
  <div class="card">
    <div class="card-header"><h2>Ajouter des crédits</h2></div>
    <form method="POST" class="form-body js-admin-action" data-action-label="Crédits ajoutés">
      <input type="hidden" name="action" value="add_credits">
      <div class="form-group">
        <label>Montant</label>
        <input type="number" name="amount" class="form-control" min="1" placeholder="Ex: 500" required>
      </div>
      <div class="form-group">
        <label>Motif</label>
        <input type="text" name="reason" class="form-control" value="Ajout manuel admin">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Ajouter les crédits</button>
    </form>
  </div>

  <!-- Remove credits -->
  <div class="card">
    <div class="card-header"><h2>Retirer des crédits</h2></div>
    <form method="POST" class="form-body js-admin-action" data-action-label="Crédits retirés">
      <input type="hidden" name="action" value="add_credits">
      <input type="hidden" name="direction" value="remove">
      <div class="form-group"><label>Montant</label><input type="number" name="amount" class="form-control" min="1" placeholder="Ex: 50" required></div>
      <div class="form-group"><label>Motif</label><input type="text" name="reason" class="form-control" value="Retrait manuel admin"></div>
      <button type="submit" class="btn btn-outline btn-block">Retirer les crédits</button>
    </form>
  </div>

  <!-- Control center permission -->
  <div class="card">
    <div class="card-header"><h2>Centre de contrôle</h2></div>
    <form method="POST" class="form-body js-admin-action">
      <input type="hidden" name="action" value="set_control_center_access">
      <div class="form-group"><label style="display:flex;align-items:center;gap:10px"><input type="checkbox" name="control_center_access" value="1" <?= $user['control_center_access'] ? 'checked' : '' ?>> Autoriser l’accès à l’administration de la plateforme</label><small class="text-muted">Ce droit est vérifié par WhatsApp Cloud Platform auprès de Botora Admin. Décoché, l’utilisateur ne pourra pas ouvrir le Centre de contrôle.</small></div>
      <button type="submit" class="btn btn-primary btn-block">Enregistrer le droit</button>
    </form>
  </div>

  <!-- Access status -->
  <div class="card">
    <div class="card-header"><h2>Statut et accès</h2></div>
    <form method="POST" class="form-body js-admin-action" data-action-label="Modification enregistrée" style="margin-bottom:12px">
      <input type="hidden" name="action" value="set_status">
      <div class="form-group">
        <label>Changer le statut</label>
        <select name="status" class="form-control">
          <?php foreach (['trial','active','suspended','expired','banned'] as $s): ?>
          <option value="<?= $s ?>" <?= $user['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-outline btn-block">Appliquer</button>
    </form>
    <div class="d-flex gap-2 mb-3">
      <?php if ($user['status'] !== 'suspended'): ?>
      <form method="POST" class="flex-fill" onsubmit="return confirm('Suspendre ce compte ?')"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="suspended"><button type="submit" class="btn btn-danger w-100">Suspendre le compte</button></form>
      <?php else: ?>
      <form method="POST" class="flex-fill"><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="active"><button type="submit" class="btn btn-success w-100">Réactiver le compte</button></form>
      <?php endif; ?>
    </div>
    <form method="POST" class="form-body">
      <input type="hidden" name="action" value="extend_trial">
      <div class="form-group">
        <label>Prolonger l'essai (jours)</label>
        <input type="number" name="days" class="form-control" value="7" min="1" max="365">
      </div>
      <button type="submit" class="btn btn-outline btn-block">Prolonger</button>
    </form>
  </div>

  <!-- Notes -->
  <div class="card">
    <div class="card-header"><h2>Notes internes</h2></div>
    <form method="POST" class="form-body js-admin-action" data-action-label="Notes mises à jour">
      <input type="hidden" name="action" value="update_notes">
      <textarea name="notes" class="form-control" rows="5"><?= h($user['notes'] ?? '') ?></textarea>
      <button type="submit" class="btn btn-outline" style="margin-top:8px">Sauvegarder</button>
    </form>
  </div>

  <!-- Activations -->
  <?php if ($activations): ?>
  <div class="card">
    <div class="card-header"><h2>Installations (<?= count($activations) ?>)</h2></div>
    <table class="table table-sm">
      <thead><tr><th>IP</th><th>Version</th><th>Vu la dernière fois</th></tr></thead>
      <tbody>
        <?php foreach ($activations as $a): ?>
        <tr>
          <td><?= h($a['ip_address'] ?? '—') ?></td>
          <td><?= h($a['botora_version'] ?? '—') ?></td>
          <td><?= format_datetime($a['last_seen']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Usage summary -->
  <?php if ($usage): ?>
  <div class="card">
    <div class="card-header"><h2>Utilisation</h2></div>
    <table class="table table-sm">
      <thead><tr><th>Événement</th><th>Occurrences</th><th>Crédits</th></tr></thead>
      <tbody>
        <?php foreach ($usage as $u): ?>
        <tr><td><?= h($u['event_type']) ?></td><td><?= $u['cnt'] ?></td><td><?= number_format($u['total_credits']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- User transactions -->
  <div class="card" style="grid-column: 1/-1">
    <div class="card-header"><h2>Transactions du compte</h2><span class="text-muted small"><?= count($user_transactions) ?> opérations récentes</span></div>
    <div class="table-responsive"><table class="table table-sm align-middle">
      <thead><tr><th>Date</th><th>Type</th><th>Montant indicatif</th><th>Crédits</th><th>Statut</th><th>Motif / opérateur</th></tr></thead>
      <tbody>
      <?php foreach ($user_transactions as $transaction): $transactionType = (string)($transaction['transaction_type'] ?? 'payment'); ?>
        <tr>
          <td><?= format_datetime($transaction['created_at']) ?></td>
          <td><?= credit_transaction_type_badge($transactionType) ?></td>
          <td><?= number_format((float)$transaction['amount_xof'], 0, ',', ' ') ?> XOF</td>
          <td class="<?= $transactionType === 'admin_debit' ? 'text-danger' : 'text-success' ?>"><?= $transactionType === 'admin_debit' ? '−' : '+' ?><?= number_format((float)$transaction['credits'], 3, ',', ' ') ?></td>
          <td><?= $transactionType === 'payment' ? payment_status_badge((string)$transaction['status']) : '<span class="badge badge-success">Approuvée automatiquement</span>' ?></td>
          <td><?= h((string)($transaction['description'] ?? '—')) ?><?php if (!empty($transaction['admin_name'])): ?><br><small class="text-muted">Par <?= h($transaction['admin_name']) ?></small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$user_transactions): ?><tr><td colspan="6" class="text-center text-muted">Aucune transaction enregistrée.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Central activity history -->
  <div class="card" style="grid-column: 1/-1">
    <div class="card-header"><h2>Activité de la plateforme</h2><span class="text-muted small"><?= count($activities) ?> événements récents</span></div>
    <div class="table-responsive"><table class="table table-sm align-middle">
      <thead><tr><th>Date</th><th>Événement</th><th>Tokens</th><th>Crédits</th><th>Détails</th></tr></thead>
      <tbody>
      <?php foreach ($activities as $activity): ?>
        <tr><td><?= format_datetime($activity['created_at']) ?></td><td><span class="badge badge-primary"><?= h($activity['event_type']) ?></span></td><td><?= $activity['tokens_used'] !== null ? number_format((int)$activity['tokens_used']) : '—' ?></td><td><?= $activity['credits_used'] !== null ? number_format((float)$activity['credits_used'], 10, ',', ' ') : '—' ?></td><td><code><?= h(mb_strimwidth((string)($activity['payload'] ?? ''), 0, 120, '…')) ?></code></td></tr>
      <?php endforeach; ?>
      <?php if (empty($activities)): ?><tr><td colspan="5" class="text-center text-muted">Aucune activité centrale remontée pour le moment.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- Credit history -->
  <div class="card" style="grid-column: 1/-1">
    <div class="card-header"><h2>Historique crédits</h2></div>
    <table class="table">
      <thead><tr><th>Date</th><th>Type</th><th>Montant</th><th>Motif</th><th>Admin</th><th>Solde après</th></tr></thead>
      <tbody>
        <?php foreach ($credit_logs as $l): ?>
        <tr>
          <td><?= format_datetime($l['created_at']) ?></td>
          <td><span class="badge <?= $l['type']==='add'?'badge-success':'badge-secondary' ?>"><?= h($l['type']) ?></span></td>
          <td><?= $l['amount'] > 0 ? '+' . $l['amount'] : $l['amount'] ?></td>
          <td><?= h($l['reason'] ?? '—') ?></td>
          <td><?= h($l['admin_name'] ?? 'API') ?></td>
          <td><?= number_format($l['balance_after']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($credit_logs)): ?>
          <tr><td colspan="6" class="text-center text-muted">Aucun mouvement.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
<script>
$(function () {
  const $alert = $('#admin-action-alert');
  function notify(type, message) {
    $alert.removeClass('d-none alert-success alert-danger').addClass('alert-' + type).text(message).hide().fadeIn(180);
    setTimeout(() => $alert.fadeOut(300, () => $alert.addClass('d-none')), 4500);
  }
  $('.js-admin-action').on('submit', function (event) {
    event.preventDefault();
    const $form = $(this), $button = $form.find('button[type=submit]'), original = $button.text();
    $button.prop('disabled', true).text('Traitement…');
    $.ajax({ url: window.location.href, method: 'POST', data: $form.serialize(), dataType: 'json' })
      .done(function (response) { notify('success', response.message || ($form.data('action-label') + ' avec succès.')); setTimeout(() => window.location.reload(), 550); })
      .fail(function (xhr) { notify('danger', xhr.responseJSON?.error || 'L’action n’a pas pu être exécutée.'); })
      .always(function () { $button.prop('disabled', false).text(original); });
  });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
