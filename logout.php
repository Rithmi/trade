<?php
require __DIR__ . '/bootstrap.php';
logout_user();
header("Location: {$BASE_URL}/login.php");
exit;