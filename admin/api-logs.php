<?php
$pageTitle = 'Logs API'; $activePage = 'api-logs';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$db->exec("DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 15 DAY)");

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$latest = $db->query('SELECT * FROM api_logs ORDER BY id DESC LIMIT 100')->fetchAll();
$total = count($latest);
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$logs = array_slice($latest, ($page - 1) * $perPage, $perPage);
?>
<div class="page-header">
  <div>
    <h1>Logs API <span class="text-muted">(<?= $total ?> derniers)</span></h1>
    <p class="text-muted mb-0">Les appels API des 15 derniers jours, avec payload, réponse et contexte technique.</p>
  </div>
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
          <td><details><summary>Voir</summary><pre class="api-log-data"><?= h((string)($log['payload'] ?? '')) ?></pre></details></td>
          <td><details><summary>Voir</summary><pre class="api-log-data"><?= h((string)($log['response'] ?? '')) ?></pre></details></td>
          <td><small>IP: <?= h((string)($log['ip_address'] ?? '—')) ?><br><?= h((string)($log['user_agent'] ?? '—')) ?></small></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="9" class="text-center text-muted py-4">Aucun appel API enregistré.</td></tr><?php endif; ?>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
