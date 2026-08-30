<?php
$pageTitle = 'Historique des clés API'; $activePage = 'api-keys';
require_once __DIR__ . '/../includes/header.php';
$rows = db()->query('SELECT account_email, platform_key_uid, key_name, key_prefix, status, created_at, last_used_at, revoked_at FROM api_key_history ORDER BY created_at DESC')->fetchAll();
?>
<div class="page-header d-flex justify-content-between align-items-center">
  <div><h1>Historique des clés API</h1><p class="text-muted mb-0">Suivi administratif des clés créées dans WhatsApp Cloud Platform. Les secrets ne sont jamais enregistrés ici.</p></div>
</div>
<div class="card mt-4">
  <div class="card-body p-0">
    <?php if (!$rows): ?>
      <div class="p-4 text-muted">Aucune clé API n’a encore été synchronisée.</div>
    <?php else: ?>
      <div class="table-responsive"><table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Compte</th><th>Nom</th><th>Préfixe</th><th>Statut</th><th>Créée le</th><th>Dernière utilisation</th><th>Révoquée le</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr><td><?= h($row['account_email']) ?></td><td><?= h($row['key_name']) ?></td><td><code><?= h($row['key_prefix']) ?>••••</code></td><td><span class="badge <?= $row['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>"><?= h($row['status']) ?></span></td><td><?= h($row['created_at']) ?></td><td><?= h($row['last_used_at'] ?: '—') ?></td><td><?= h($row['revoked_at'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
