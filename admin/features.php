<?php
$pageTitle = 'Fonctionnalités'; $activePage = 'features';
require_once __DIR__ . '/../includes/header.php';
require_superadmin();
$db = db();
$features = $db->query('SELECT feature_key,label,description,enabled,updated_at FROM platform_features ORDER BY feature_key ASC')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $enabled = $_POST['enabled'] ?? [];
  $stmt = $db->prepare('UPDATE platform_features SET enabled=? WHERE feature_key=?');
  foreach ($features as $feature) $stmt->execute([isset($enabled[$feature['feature_key']]) ? 1 : 0, $feature['feature_key']]);
  flash_set('success', 'Les fonctionnalités ont été mises à jour.');
  header('Location: ' . APP_URL . '/admin/features.php'); exit;
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div><div class="text-uppercase small fw-bold text-success">Administration centrale</div><h1 class="mb-1">Fonctionnalités</h1><p class="text-muted mb-0">Décidez depuis Botora Admin ce qui est visible et disponible dans WhatsApp Grok Platform.</p></div>
  <span class="badge text-bg-success px-3 py-2"><?= count(array_filter($features, fn($f) => (int)$f['enabled'] === 1)) ?> actives / <?= count($features) ?></span>
</div>
<div class="alert alert-info border-0 shadow-sm"><strong>Source de vérité centrale.</strong> Les changements enregistrés ici sont lus par l’API de Botora et appliqués progressivement dans la plateforme utilisateur.</div>
<form method="POST">
  <div class="row g-3">
  <?php foreach ($features as $feature): ?>
    <div class="col-12 col-md-6 col-xl-4">
      <div class="card h-100 border-0 shadow-sm feature-card">
        <div class="card-body d-flex flex-column gap-3">
          <div class="d-flex justify-content-between align-items-start gap-3"><div><h2 class="h5 mb-1"><?= h($feature['label']) ?></h2><p class="small text-muted mb-0"><?= h($feature['description'] ?? '') ?></p></div><span class="feature-icon">◆</span></div>
          <label class="feature-toggle mt-auto"><span><strong class="feature-state"><?= (int)$feature['enabled'] ? 'Activée' : 'Désactivée' ?></strong><small class="d-block text-muted">Clé : <?= h($feature['feature_key']) ?></small></span><input type="checkbox" name="enabled[<?= h($feature['feature_key']) ?>]" <?= (int)$feature['enabled'] ? 'checked' : '' ?>><i></i></label>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="d-flex justify-content-end mt-4"><button type="submit" class="btn btn-success btn-lg px-4">Enregistrer les décisions</button></div>
</form>
<style>
.feature-card{border-radius:18px;background:linear-gradient(145deg,#fff,#f4fbf8);transition:.2s}.feature-card:hover{transform:translateY(-2px);box-shadow:0 1rem 2rem rgba(10,80,70,.12)!important}.feature-icon{width:34px;height:34px;border-radius:12px;display:grid;place-items:center;background:#dff6eb;color:#087c73}.feature-toggle{display:flex;justify-content:space-between;align-items:center;gap:16px;cursor:pointer}.feature-toggle input{position:absolute;opacity:0}.feature-toggle i{width:48px;height:27px;border-radius:50px;background:#bccaca;position:relative;flex:none;transition:.2s}.feature-toggle i:after{content:'';position:absolute;width:21px;height:21px;top:3px;left:3px;background:#fff;border-radius:50%;transition:.2s;box-shadow:0 2px 4px #0002}.feature-toggle input:checked+i{background:#11856f}.feature-toggle input:checked+i:after{left:24px}.feature-toggle input:checked~span .feature-state{color:#087c73}.feature-toggle input:not(:checked)~span .feature-state{color:#8a5960}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
