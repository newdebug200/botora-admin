<?php
$pageTitle = 'Logs API'; $activePage = 'api-logs';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
auth_check();

$db = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_api_logs') {
  $db->exec('DELETE FROM api_logs');
  flash_set('success', 'Tous les logs API ont été supprimés.');
  header('Location: ' . APP_URL . '/admin/api-logs.php');
  exit;
}

$db->exec("DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 15 DAY)");

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$latest = $db->query('SELECT * FROM api_logs ORDER BY id DESC LIMIT 100')->fetchAll();
$total = count($latest);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$logs = array_slice($latest, ($page - 1) * $perPage, $perPage);
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <div>
    <h1>Logs API <span class="text-muted">(<?= $total ?> derniers)</span></h1>
    <p class="text-muted mb-0">Les appels API des 15 derniers jours, avec payload, réponse et contexte technique.</p>
  </div>
  <form method="POST" onsubmit="return confirm('Vider définitivement tous les logs API ? Cette action est irréversible.');">
    <input type="hidden" name="action" value="clear_api_logs">
    <button type="submit" class="btn btn-danger">Vider tous les logs</button>
  </form>
</div>

<div class="card">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h2>Historique des appels</h2>
    <span class="small text-muted">Page <?= $page ?> / <?= $totalPages ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Date</th>
          <th>Utilisateur</th>
          <th>Requête</th>
          <th>Statut</th>
          <th>Durée</th>
          <th>Payload</th>
          <th>Réponse</th>
          <th>Erreur</th>
          <th>Contexte</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logs as $log): ?>
        <tr>
          <td><strong>#<?= (int)$log['id'] ?></strong></td>
          <td><?= format_datetime($log['created_at']) ?></td>
          <td>
            <?php if ($log['user_id']): ?>
              <a href="<?= APP_URL ?>/admin/user-detail.php?id=<?= (int)$log['user_id'] ?>">Utilisateur #<?= (int)$log['user_id'] ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><strong><?= h($log['method']) ?></strong><br><code><?= h($log['route']) ?></code></td>
          <td><span class="badge <?= (int)$log['status_code'] < 400 ? 'badge-success' : 'badge-danger' ?>"><?= (int)$log['status_code'] ?></span></td>
          <td><?= (int)$log['response_ms'] ?> ms</td>
          <td><button type="button" class="btn btn-sm btn-outline api-log-modal-trigger" data-modal-title="Payload" data-modal-content-id="api-log-payload-<?= (int)$log['id'] ?>">Voir</button><template id="api-log-payload-<?= (int)$log['id'] ?>"><pre><?= h((string)($log['payload'] ?? '')) ?></pre></template></td>
          <td><button type="button" class="btn btn-sm btn-outline api-log-modal-trigger" data-modal-title="Réponse" data-modal-content-id="api-log-response-<?= (int)$log['id'] ?>">Voir</button><template id="api-log-response-<?= (int)$log['id'] ?>"><pre><?= h((string)($log['response'] ?? '')) ?></pre></template></td>
          <td><?php if (!empty($log['error_message'])): ?><button type="button" class="btn btn-sm btn-danger api-log-modal-trigger" data-modal-title="Erreur" data-modal-content-id="api-log-error-<?= (int)$log['id'] ?>">Voir</button><template id="api-log-error-<?= (int)$log['id'] ?>"><pre><?= h((string)$log['error_message']) ?></pre></template><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <td><small>IP: <?= h((string)($log['ip_address'] ?? '—')) ?><br><?= h((string)($log['user_agent'] ?? '—')) ?></small></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="10" class="text-center text-muted py-4">Aucun appel API enregistré.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav aria-label="Pagination des logs API" class="pagination-bar">
  <?php if ($page > 1): ?><a class="btn btn-sm btn-outline" href="?page=<?= $page - 1 ?>">Précédent</a><?php endif; ?>
  <?php for ($number = 1; $number <= $totalPages; $number++): ?>
    <a class="pagination-link <?= $number === $page ? 'active' : '' ?>" href="?page=<?= $number ?>"><?= $number ?></a>
  <?php endfor; ?>
  <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline" href="?page=<?= $page + 1 ?>">Suivant</a><?php endif; ?>
</nav>
<?php endif; ?>

<div class="modal fade" id="api-log-modal" tabindex="-1" aria-labelledby="api-log-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="api-log-modal-title">Détail</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body"><pre id="api-log-modal-content" class="api-log-modal-content"></pre></div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.api-log-modal-trigger').forEach(function (button) {
  button.addEventListener('click', function () {
    document.getElementById('api-log-modal-title').textContent = button.dataset.modalTitle || 'Détail';
    const template = document.getElementById(button.dataset.modalContentId);
    document.getElementById('api-log-modal-content').textContent = template ? template.content.textContent : 'Aucun contenu.';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('api-log-modal')).show();
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
