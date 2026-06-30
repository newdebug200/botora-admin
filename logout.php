<?php
require_once __DIR__ . '/includes/auth.php';
session_init();
session_destroy();
header('Location: ' . APP_URL . '/index.php');
exit;
