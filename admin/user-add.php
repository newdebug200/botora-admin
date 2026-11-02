<?php
$pageTitle = 'Nouveau client'; $activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$freePlanId = $db->query("SELECT id FROM plans WHERE slug='free' AND is_active=1 LIMIT 1")->fetchColumn() ?: null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name    = trim($_POST['name'] ?? '');
  $email   = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $credits = 0;
  $trial   = 14;
  $notes   = trim($_POST['notes'] ?? '');

  if (!$name) $errors[] = 'Le nom est requis.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
  if (strlen($password) < 8) $errors[] = 'Le mot de passe doit contenir au moins 8 caractères.';
  $existing = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1'); $existing->execute([$email]);
  if ($existing->fetchColumn()) {
    $errors[] = 'Cet email est déjà utilisé.';
  }

  if (empty($errors)) {
    $license = generate_license();
    $now = botora_server_now($db);
    $trial_start = $now->format('Y-m-d H:i:s');
    $trial_end = $now->modify('+14 days')->format('Y-m-d H:i:s');
    $db->prepare('INSERT INTO users (name, email, password_hash, plan_id, license_key, credits_balance, trial_started_at, trial_ends_at, trial_used, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $freePlanId, $license, 0, $trial_start, $trial_end, 1, 'trial', $notes]);
    $user_id = (int)$db->lastInsertId();
    if ($credits > 0) {
      add_credits($user_id, $credits, 'Attribution initiale', $_SESSION['admin_id']);
    }
    flash_set('success', "Client {$name} créé avec succès. Licence : {$license}");
    header('Location: ' . APP_URL . '/admin/user-detail.php?id=' . $user_id); exit;
  }
}
?>
<div class="page-header">
  <h1>Nouveau client</h1>
  <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-outline">← Retour</a>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:600px">
  <div class="card-header"><h2>Informations du client</h2></div>
  <form method="POST" class="form-body">
    <div class="form-group">
      <label>Nom complet *</label>
      <input type="text" name="name" class="form-control" required value="<?= h($_POST['name']??'') ?>">
    </div>
    <div class="form-group">
      <label>Email *</label>
      <input type="email" name="email" class="form-control" required value="<?= h($_POST['email']??'') ?>">
    </div>
    <div class="form-group">
      <label>Mot de passe d’accès *</label>
      <input type="password" name="password" class="form-control" minlength="8" required autocomplete="new-password">
      <small class="text-muted">Minimum 8 caractères. Communiquez-le au client de manière sécurisée.</small>
    </div>
    <div class="form-group">
      <label>Accès initial</label>
      <input type="text" class="form-control" value="Essai gratuit de 14 jours" readonly>
      <small class="text-muted">L’abonnement annuel se configure séparément dans Paramètres.</small>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Crédit initial</label>
        <input type="number" name="credits" class="form-control" min="0" value="0" readonly>
        <small class="text-muted">Tout nouvel utilisateur commence à 0 crédit.</small>
      </div>
      <div class="form-group">
        <label>Essai gratuit</label>
        <input type="text" class="form-control" value="14 jours (unique)" readonly>
        <small class="text-muted">L’essai est accordé une seule fois et calculé avec l’heure du serveur.</small>
      </div>
    </div>
    <div class="form-group">
      <label>Notes internes</label>
      <textarea name="notes" class="form-control" rows="3"><?= h($_POST['notes']??'') ?></textarea>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Créer le client</button>
      <a href="<?= APP_URL ?>/admin/users.php" class="btn btn-outline">Annuler</a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
