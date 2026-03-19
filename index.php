<?php
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
  header("Location: {$BASE_URL}/dashboard.php");
} else {
  header("Location: {$BASE_URL}/login.php");
}
exit;