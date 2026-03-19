<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
  header("Location: {$BASE_URL}/dashboard.php");
  exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if (attempt_login($email, $password)) {
    header("Location: {$BASE_URL}/dashboard.php");
    exit;
  }
  $error = "Invalid email or password.";
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login</title>
  <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css">
</head>
<body>
  <div class="container" style="max-width:520px; padding-top:60px;">
    <div class="card">
      <h2 style="margin:0;">Sign in</h2>
      <p class="help">Login to manage your bot sessions.</p>

      <?php if ($error): ?>
        <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
          <?= htmlspecialchars($error) ?>
        </div>
        <hr class="sep">
      <?php endif; ?>

      <form method="post">
        <div class="field">
          <label>Email</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="field">
          <label>Password</label>
          <input type="password" name="password" required>
        </div>

        <div class="row" style="margin-top:14px;">
          <button class="btn primary" type="submit" style="width:100%;">Login</button>
        </div>

        <div class="row" style="justify-content:space-between; margin-top:10px;">
          <a href="<?= $BASE_URL ?>/forgot_password.php" style="color:var(--accent); font-weight:600;">Forgot password?</a>
          <a href="<?= $BASE_URL ?>/register.php" style="color:var(--accent); font-weight:600;">Create account</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>