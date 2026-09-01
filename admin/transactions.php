<?php
$pageTitle = 'Transactions'; $activePage = 'transactions';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
auth_check();

$db = db();
$returnQuery = http_build_query(array_filter([
  'status' => $_GET['status'] ?? '',
  'q' => $_GET['q'] ?? '',
]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  $transactionId = (int)($_POST['transaction_id'] ?? 0);
  try {
    if (!$transactionId) throw new InvalidArgumentException('Transaction invalide.');
    if ($action === 'update_transaction') {
      $status = strtolower(trim((string)($_POST['status'] ?? '')));
      $amount = (float)($_POST['amount_xof'] ?? 0);
      $credits = (float)($_POST['credits'] ?? 0);
      $externalId = trim((string)($_POST['external_id'] ?? '')) ?: null;
      $description = trim((string)($_POST['description'] ?? '')) ?: null;
      $allowedStatuses = ['pending', 'approved', 'failed', 'creation_failed', 'canceled', 'declined', 'deleted', 'expired', 'refunded'];
      if (!in_array($status, $allowedStatuses, true)) throw new InvalidArgumentException('Statut invalide.');
      if (!is_finite($amount) || $amount < 0 || !is_finite($credits) || $credits < 0) throw new InvalidArgumentException('Montant ou crédits invalides.');
      $approvedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
      $stmt = $db->prepare('UPDATE payment_transactions SET external_id=?, amount_xof=?, credits=?, status=?, description=?, approved_at=?, updated_at=NOW() WHERE id=?');
      $stmt->execute([$externalId, $amount, $credits, $status, $description, $approvedAt, $transactionId]);
      flash_set('success', 'Transaction mise à jour.');
    } elseif ($action === 'delete_transaction') {
      $stmt = $db->prepare('DELETE FROM payment_transactions WHERE id=?');
      $stmt->execute([$transactionId]);
      if ($stmt->rowCount() === 0) throw new RuntimeException('Transaction introuvable.');
      flash_set('success', 'Transaction supprimée.');
    }
  } catch (Throwable $e) {
    flash_set('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Impossible de modifier cette transaction.');
  }
  header('Location: ' . APP_URL . '/admin/transactions.php' . ($returnQuery ? '?' . $returnQuery : ''));
  exit;
}

require_once __DIR__ . '/../includes/header.php';

$statusFilter = $_GET['status'] ?? 'all';
$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($statusFilter !== 'all' && $statusFilter !== '') {
  $where[] = 'pt.status = ?';
  $params[] = $statusFilter;
}
if ($search !== '') {
  $where[] = '(u.name LIKE ? OR u.email LIKE ? OR pt.external_id LIKE ? OR pt.description LIKE ?)';
  $like = '%' . $search . '%';
  $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "
  SELECT pt.*, u.name AS user_name, u.email AS user_email, u.license_key,
         u.credits_balance
  FROM payment_transactions pt
  LEFT JOIN users u ON u.id = pt.user_id
";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY pt.id DESC LIMIT 500';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$stats = [
  'total' => (int)$db->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn(),
  'approved' => (int)$db->query("SELECT COUNT(*) FROM payment_transactions WHERE status='approved'")->fetchColumn(),
  'pending' => (int)$db->query("SELECT COUNT(*) FROM payment_transactions WHERE status='pending'")->fetchColumn(),
  'failed' => (int)$db->query("SELECT COUNT(*) FROM payment_transactions WHERE status IN ('failed','creation_failed')")->fetchColumn(),
];
?>
<div class="page-header">
  <div>
    <h1>Transactions</h1>
    <p class="text-muted mb-0">Toutes les transactions de paiement, avec client, statut, montant et historique complet.</p>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon blue"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M3 7h18v10H3V7zm2 2v6h14V9H5zm2 2h4v2H7v-2zm6 0h4v2h-4v-2z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($stats['total']) ?></div><div class="stat-label">Total</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($stats['approved']) ?></div><div class="stat-label">Approuvées</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($stats['pending']) ?></div><div class="stat-label">En attente</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon red"><svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 15h-2v-2h2zm0-4h-2V7h2z"/></svg></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($stats['failed']) ?></div><div class="stat-label">Échec</div></div>
  </div>
</div>

<div class="card transactions-card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h2>Filtrer</h2>
    <form method="GET" class="search-form" style="margin:0;">
      <select name="status" class="form-control" style="width:auto; min-width:160px;">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Tous statuts</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>En attente</option>
        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approuvée</option>
        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Échouée</option>
        <option value="creation_failed" <?= $statusFilter === 'creation_failed' ? 'selected' : '' ?>>Création échouée</option>
        <option value="canceled" <?= $statusFilter === 'canceled' ? 'selected' : '' ?>>Annulée</option>
        <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expirée</option>
        <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Remboursée</option>
      </select>
      <input type="search" name="q" value="<?= h($search) ?>" class="form-control" placeholder="Recherche client / email / ID / description" style="width:260px;">
      <button type="submit" class="btn btn-primary">Filtrer</button>
    </form>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Client</th>
          <th>Montant</th>
          <th>Crédits</th>
          <th>Statut</th>
          <th>External ID</th>
          <th>Description</th>
          <th>Créée le</th>
          <th>Approuvée le</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $t): ?>
        <tr>
          <td><strong>#<?= (int)$t['id'] ?></strong></td>
          <td>
            <strong><?= h($t['user_name'] ?? '—') ?></strong><br>
            <small class="text-muted"><?php echo h($t['user_email'] ?? '—'); ?></small><br>
            <small class="text-muted">Licence: <?= h($t['license_key'] ?? '—') ?></small>
          </td>
          <td><?= number_format((float)$t['amount_xof'], 0, ',', ' ') ?> XOF</td>
          <td><?= number_format((float)$t['credits'], 0, ',', ' ') ?></td>
          <td><?= payment_status_badge((string)$t['status']) ?></td>
          <td><code><?= h((string)($t['external_id'] ?? '—')) ?></code></td>
          <td><?= h((string)($t['description'] ?? '—')) ?></td>
          <td><?= format_datetime($t['created_at']) ?></td>
          <td><?= $t['approved_at'] ? format_datetime($t['approved_at']) : '—' ?></td>
          <td class="actions">
            <button type="button" class="btn btn-sm btn-outline transaction-edit-trigger" data-bs-toggle="modal" data-bs-target="#transaction-edit-modal" data-id="<?= (int)$t['id'] ?>" data-status="<?= h($t['status']) ?>" data-amount="<?= h((string)$t['amount_xof']) ?>" data-credits="<?= h((string)$t['credits']) ?>" data-external-id="<?= h((string)($t['external_id'] ?? '')) ?>" data-description="<?= h((string)($t['description'] ?? '')) ?>">Modifier</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer définitivement la transaction #<?= (int)$t['id'] ?> ? Cette action est irréversible.');">
              <input type="hidden" name="action" value="delete_transaction">
              <input type="hidden" name="transaction_id" value="<?= (int)$t['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">Aucune transaction trouvée.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="transaction-edit-modal" tabindex="-1" aria-labelledby="transaction-edit-title" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transaction-edit-title">Modifier la transaction</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="action" value="update_transaction">
          <input type="hidden" name="transaction_id" id="transaction-edit-id">
          <div class="form-row">
            <div class="form-group"><label for="transaction-edit-status">Statut</label><select name="status" id="transaction-edit-status" class="form-control" required><?php foreach (['pending','approved','failed','creation_failed','canceled','declined','deleted','expired','refunded'] as $status): ?><option value="<?= $status ?>"><?= ucfirst(str_replace('_', ' ', $status)) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label for="transaction-edit-amount">Montant (XOF)</label><input type="number" name="amount_xof" id="transaction-edit-amount" class="form-control" min="0" step="0.01" required></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label for="transaction-edit-credits">Crédits</label><input type="number" name="credits" id="transaction-edit-credits" class="form-control" min="0" step="0.0000000001" required></div>
            <div class="form-group"><label for="transaction-edit-external-id">External ID FedaPay</label><input type="text" name="external_id" id="transaction-edit-external-id" class="form-control" maxlength="100"></div>
          </div>
          <div class="form-group"><label for="transaction-edit-description">Description</label><textarea name="description" id="transaction-edit-description" class="form-control" rows="4"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary">Enregistrer</button></div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.transaction-edit-trigger').forEach(function (button) {
  button.addEventListener('click', function () {
    document.getElementById('transaction-edit-id').value = button.dataset.id;
    document.getElementById('transaction-edit-status').value = button.dataset.status;
    document.getElementById('transaction-edit-amount').value = button.dataset.amount;
    document.getElementById('transaction-edit-credits').value = button.dataset.credits;
    document.getElementById('transaction-edit-external-id').value = button.dataset.externalId;
    document.getElementById('transaction-edit-description').value = button.dataset.description;
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
