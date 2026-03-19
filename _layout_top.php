<?php
// expects: $pageTitle, $activeNav, $userEmail

// Base URL for your current setup; bootstrap.php already populates this for every route
$BASE_URL = $BASE_URL ?? '';

// Ensure the session is initialized before any output is sent
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$resolvedUser = null;
if (function_exists('current_user')) {
  $resolvedUser = current_user();
} elseif (!empty($_SESSION['user'])) {
  // Backward compatibility with older session payloads
  $resolvedUser = $_SESSION['user'];
}

$displayEmail = $userEmail ?? ($resolvedUser['email'] ?? 'Guest');
$showLogout = !empty($resolvedUser);
?>
<!doctype html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Bot Dashboard') ?></title>

  <!-- Use BASE_URL so CSS loads correctly -->
  <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css">
</head>

<body>
  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand"><span class="brand-dot"></span> Futures Bot Dashboard</div>
      <div class="row">
        <span class="badge"><?= htmlspecialchars($displayEmail) ?></span>
        <?php if ($showLogout): ?>
          <a class="btn" href="<?= $BASE_URL ?>/logout.php">Logout</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="shell">
    <aside class="sidebar">
      <div class="nav">
        <a class="<?= ($activeNav === 'dashboard' ? 'active' : '') ?>" href="<?= $BASE_URL ?>/dashboard.php">Dashboard</a>
        <a class="<?= ($activeNav === 'bots' ? 'active' : '') ?>" href="<?= $BASE_URL ?>/bot_edit.php">Create Bot</a>
        <a class="<?= ($activeNav === 'apikeys' ? 'active' : '') ?>" href="<?= $BASE_URL ?>/api_keys.php">API Keys</a>
      </div>

      <hr class="sep">
      <p class="help">Create separate sessions per symbol (BTC/ETH/XRP) with unique settings.</p>
    </aside>

    <main>