<?php
$pageTitle = 'Nouveau client'; $activePage = 'users';
require_once __DIR__ . '/../includes/header.php';

$db = db();
$plans = $db->query('SELECT * FROM plans WHERE is_active=1 ORDER BY price_eur ASC')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name    = trim($_POST['name'] ?? '');
  $email   = trim($_POST['email'] ?? '');
  $plan_id = (int)($_POST['plan_id'] ?? 0);
  $credits = (int)($_POST['credits'] ?? 0);
  $trial   = (int)($_POST['trial_days'] ?? 14);
  $notes   = trim($_POST['notes'] ?? '');

  if (!$name) $errors[] = 'Le nom est requis.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email invalide.';
  if ($db->prepare('SELECT id FROM users WHERE email=?')->execute([$email]) && $db->query("SELECT id FROM users WHERE email='".addslashes($email)."'")->fetchColumn()) {
    $errors[] = 'Cet email est déjà utilisé.';
  }

  if (empty($errors)) {
    $license = generate_license();
    $trial_end = date('Y-m-d', strtotime("+{$trial} days"));
    $db->prepare('INSERT INTO users (name, email, plan_id, license_key, credits_balance, trial_ends_at, status, notes) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([$name, $email, $plan_id ?: null, $license, $credits, $trial_end, 'trial', $notes]);
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
      <label>Plan</label>
      <select name="plan_id" class="form-control">
        <option value="">Sans plan</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>" <?= ($_POST['plan_id']??'')==$p['id']?'selected':'' ?>>
            <?= h($p['name']) ?> — <?= $p['credits_per_month'] ?> crédits/mois
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Crédits initiaux</label>
        <input type="number" name="credits" class="form-control" min="0" value="<?= (int)($_POST['credits']??0) ?>">
      </div>
      <div class="form-group">
        <label>Jours d'essai</label>
        <input type="number" name="trial_days" class="form-control" min="1" max="365" value="<?= (int)($_POST['trial_days']??14) ?>">
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
