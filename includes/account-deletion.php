<?php
function account_deletion_reasons(): array {
  return [
    'no_longer_needed' => 'Je n’en ai plus besoin',
    'too_expensive' => 'Le prix est trop élevé',
    'difficult_to_use' => 'Le service est difficile à utiliser',
    'missing_features' => 'Il manque des fonctionnalités',
    'privacy_concern' => 'Préoccupation liée à la confidentialité',
    'other' => 'Autre'
  ];
}

function validate_account_deletion_reason(string $reasonCode, string $reasonText = ''): array {
  if (!array_key_exists($reasonCode, account_deletion_reasons())) {
    return [false, 'Motif de suppression invalide.'];
  }
  $trimmedText = trim($reasonText);
  if (function_exists('mb_strlen')) {
    $length = mb_strlen($trimmedText);
  } elseif (preg_match_all('/./us', $trimmedText, $matches) !== false) {
    $length = count($matches[0]);
  } else {
    $length = strlen($trimmedText);
  }
  if ($reasonCode === 'other' && $length < 20) {
    return [false, 'Pour le motif Autre, la précision doit contenir au moins 20 caractères.'];
  }
  return [true, null];
}
