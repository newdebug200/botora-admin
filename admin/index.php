<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
session_init();

if (empty($_SESSION['admin_id'])) {
  header('Location: ' . APP_URL . '/?redirect=/admin/');
  exit;
}

header('Location: ' . APP_URL . '/admin/dashboard.php');
exit;
