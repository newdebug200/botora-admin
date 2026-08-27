<?php
$pageTitle = 'Détail client'; $activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/admin/users.php'); exit; }

$user = $db->prepare('SELECT u.*, p.name as plan_name, p.slug as plan_slug FROM users u LEFT JOIN plans p ON p.id=u.plan_id WHERE u.id=?');
$user->execute([$id]);
$user = $user->fetch();
if (!$user) { http_response_code(404); die('Utilisateur introuvable.'); }

$plans = $db->query('SELECT * FROM plans WHERE is_active=1 ORDER BY price_eur')->fetchAll();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'add_credits') {
    $amount = (int)($_POST['amount'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'Ajout manuel admin');
    if ($amount > 0) {
      add_credits($id, $amount, $reason, $_SESSION['admin_id']);
      flash_set('success', "{$amount} crédits ajoutés.");
    }
  } elseif ($action === 'set_status') {
    $s = $_POST['status'] ?? '';
    if (in_array($s, ['trial','active','suspended','expired','banned'])) {
      $db->prepare('UPDATE users SET status=?, updated_at=NOW() WHERE id=?')->execute([$s, $id]);
      flash_set('success', 'Statut mis à jour.');
    }
  } elseif ($action === 'set_plan') {
    $pid = (int)($_POST['plan_id'] ?? 0);
    $db->prepare('UPDATE users SET plan_id=?, updated_at=NOW() WHERE id=?')->execute([$pid ?: null, $id]);
    flash_set('success', 'Plan mis à jour.');
  } elseif ($action === 'extend_trial') {
    $days = (int)($_POST['days'] ?? 7);
    $db->prepare('UPDATE users SET trial_ends_at=DATE_ADD(IFNULL(trial_ends_at, CURDATE()), INTERVAL ? DAY), updated_at=NOW() WHERE id=?')->execute([$days, $id]);
    flash_set('success', "Essai prolongé de {$days} jours.");
  } elseif ($action === 'update_notes') {
    $notes = trim($_POST['notes'] ?? '');
    $db->prepare('UPDATE users SET notes=?, updated_at=NOW() WHERE id=?')->execute([$notes, $id]);
    flash_set('success', 'Notes mises à jour.');
  }
  header('Location: ' . APP_URL . '/admin/user-detail.php?id=' . $id); exit;
}

// Reload fresh
$user = $db->prepare('SELECT u.*, p.name as plan_name FROM users u LEFT JOIN plans p ON p.id=u.plan_id WHERE u.id=?');
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
      <div class="info-row"><span>Plan</span><strong><?= h($user['plan_name'] ?? 'Aucun') ?></strong></div>
      <div class="info-row"><span>Crédits</span><strong class="credits-display <?= $user['credits_balance']<=0?'zero':($user['credits_balance']<20?'low':'') ?>"><?= number_format($user['credits_balance']) ?></strong></div>
      <div class="info-row"><span>Essai expire</span><strong><?= $user['trial_ends_at'] ? format_date($user['trial_ends_at']).' ('.($trial_days_left >= 0 ? $trial_days_left.'j' : 'Expiré').')' : '—' ?></strong></div>
      <div class="info-row"><span>Inscrit le</span><strong><?= format_datetime($user['created_at']) ?></strong></div>
      <div class="info-row"><span>Mis à jour</span><strong><?= format_datetime($user['updated_at']) ?></strong></div>
    </div>
  </div>

  <!-- Add credits -->
  <div class="card">
    <div class="card-header"><h2>Ajouter des crédits</h2></div>
    <form method="POST" class="form-body">
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

  <!-- Status & Plan -->
  <div class="card">
    <div class="card-header"><h2>Statut & Plan</h2></div>
    <form method="POST" class="form-body" style="margin-bottom:12px">
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
    <form method="POST" class="form-body" style="margin-bottom:12px">
      <input type="hidden" name="action" value="set_plan">
      <div class="form-group">
        <label>Changer de plan</label>
        <select name="plan_id" class="form-control">
          <option value="">Aucun plan</option>
          <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $user['plan_id']==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-outline btn-block">Changer</button>
    </form>
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
    <form method="POST" class="form-body">
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
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
