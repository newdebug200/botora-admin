<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
auth_check();
$db = db();
$reasons = [
  'no_longer_needed' => 'Je n’en ai plus besoin',
  'too_expensive' => 'Le prix est trop élevé',
  'difficult_to_use' => 'Le service est difficile à utiliser',
  'missing_features' => 'Il manque des fonctionnalités',
  'privacy_concern' => 'Préoccupation liée à la confidentialité',
  'other' => 'Autre'
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $requestId = (int)($_POST['request_id'] ?? 0);
  $action = $_POST['action'] ?? '';
  $note = trim((string)($_POST['review_note'] ?? ''));
  try {
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM account_deletion_requests WHERE id=? AND status='pending' FOR UPDATE");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) throw new RuntimeException('Demande introuvable ou déjà traitée.');
    if ($action === 'reject') {
      $db->prepare("UPDATE account_deletion_requests SET status='rejected',reviewed_at=NOW(),reviewed_by=?,review_note=? WHERE id=?")
        ->execute([(int)($_SESSION['admin_id'] ?? 0), $note !== '' ? $note : null, $requestId]);
      $db->commit();
      flash_set('success', 'La demande de suppression a été refusée.');
    } elseif ($action === 'approve') {
      if (!empty($request['user_id'])) {
        $history = $db->prepare('INSERT IGNORE INTO whatsapp_trial_history (phone_number,first_user_id,first_user_email,first_connected_at,last_seen_at) SELECT wa.phone_number,wa.user_id,u.email,wa.first_connected_at,wa.last_connected_at FROM whatsapp_accounts wa LEFT JOIN users u ON u.id=wa.user_id WHERE wa.user_id=?');
        $history->execute([(int)$request['user_id']]);
        $db->prepare("UPDATE account_deletion_requests SET status='approved',reviewed_at=NOW(),reviewed_by=?,review_note=? WHERE id=?")
          ->execute([(int)($_SESSION['admin_id'] ?? 0), $note !== '' ? $note : null, $requestId]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([(int)$request['user_id']]);
      } else {
        $db->prepare("UPDATE account_deletion_requests SET status='approved',reviewed_at=NOW(),reviewed_by=?,review_note=? WHERE id=?")
          ->execute([(int)($_SESSION['admin_id'] ?? 0), $note !== '' ? $note : null, $requestId]);
      }
      $db->commit();
      flash_set('success', 'La demande a été validée et le compte a été supprimé.');
    } else {
      $db->rollBack();
      flash_set('danger', 'Action invalide.');
    }
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('[Botora Admin] Account deletion review: ' . $e->getMessage());
    flash_set('danger', 'Impossible de traiter cette demande.');
  }
  header('Location: ' . APP_URL . '/admin/account-deletion-requests.php');
  exit;
}
$pageTitle = 'Demandes de suppression';
$activePage = 'account-deletion-requests';
require_once __DIR__ . '/../includes/header.php';
$requests = $db->query("SELECT r.*, a.name AS reviewer_name FROM account_deletion_requests r LEFT JOIN admins a ON a.id=r.reviewed_by ORDER BY (r.status = 'pending') DESC, r.requested_at DESC LIMIT 300")->fetchAll();
?>
<div class="page-header">
  <div><h1>Demandes de suppression <span class="text-muted">(<?= count($requests) ?>)</span></h1><p class="text-muted mb-0">Examinez les demandes avant la suppression définitive des comptes.</p></div>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Utilisateur</th><th>Motif</th><th>Demande</th><th>Statut</th><th>Traitement</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $request): ?>
        <tr>
          <td><strong><?= h($request['name']) ?></strong><br><small class="text-muted"><?= h($request['email']) ?></small></td>
          <td><?= h($reasons[$request['reason_code']] ?? $request['reason_code']) ?><?php if (!empty($request['reason_text'])): ?><br><small><?= nl2br(h($request['reason_text'])) ?></small><?php endif; ?></td>
          <td><?= h($request['requested_at']) ?></td>
          <td><span class="badge <?= $request['status'] === 'pending' ? 'badge-warning' : ($request['status'] === 'approved' ? 'badge-success' : 'badge-danger') ?>"><?= h($request['status']) ?></span></td>
          <td>
            <?php if ($request['status'] === 'pending'): ?>
              <form method="post" class="d-flex gap-2 flex-wrap">
                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                <input type="text" name="review_note" class="form-control form-control-sm" placeholder="Note facultative">
                <button name="action" value="approve" class="btn btn-sm btn-danger" onclick="return confirm('Confirmer la suppression définitive de ce compte ?')">Valider et supprimer</button>
                <button name="action" value="reject" class="btn btn-sm btn-outline-secondary">Refuser</button>
              </form>
            <?php else: ?>
              <small class="text-muted"><?= h($request['reviewer_name'] ?? '') ?> — <?= h($request['reviewed_at'] ?? '') ?><?php if (!empty($request['review_note'])): ?><br><?= h($request['review_note']) ?><?php endif; ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucune demande de suppression.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
