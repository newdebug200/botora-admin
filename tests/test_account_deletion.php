<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/account-deletion.php';

function expect_true(bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
}

[$ok] = validate_account_deletion_reason('no_longer_needed');
expect_true($ok, 'A standard reason must be accepted');

[$ok] = validate_account_deletion_reason('other', 'Cette raison contient largement vingt caractères.');
expect_true($ok, 'Other with enough detail must be accepted');

[$ok] = validate_account_deletion_reason('other', 'Trop court');
expect_true(!$ok, 'Other with short detail must be rejected');

[$ok] = validate_account_deletion_reason('other', 'éééééééééé');
expect_true(!$ok, 'Ten Unicode characters must be rejected as fewer than twenty characters');

[$ok] = validate_account_deletion_reason('unknown_reason');
expect_true(!$ok, 'Unknown reason must be rejected');

expect_true(count(account_deletion_reasons()) === 6, 'Exactly six deletion reasons must be available');
echo "Account deletion unit tests: OK\n";
