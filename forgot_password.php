<?php
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
  header("Location: {$BASE_URL}/dashboard.php");
  exit;
}

$success = null;
$error = null;
$resetLink = null;
$email = trim($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Enter a valid email address.';
  } else {
    ensure_users_table();
    ensure_password_resets_table();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
      $token = bin2hex(random_bytes(32));
      $tokenHash = hash('sha256', $token);
      $expiresAt = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

      $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$user['id']]);
      $insert = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
      $insert->execute([$user['id'], $tokenHash, $expiresAt]);

      // In a production build you would email the link. For this demo we surface it so testing is easy.
      $resetLink = sprintf('%s/reset_password.php?token=%s', $BASE_URL, $token);
    }

    $success = 'If that email exists on file, we just sent password reset instructions. Check your inbox.';
    $email = '';
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Forgot Password</title>
  <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css">
</head>
<body>
  <div class="container" style="max-width:520px; padding-top:60px;">
    <div class="card">
      <h2 style="margin:0;">Reset your password</h2>
      <p class="help">Enter the email you used when registering. We will send a secure reset link.</p>

      <?php if ($error): ?>
        <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
          <?= htmlspecialchars($error) ?>
        </div>
        <hr class="sep">
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert" style="border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.15);color:#d1fae5;">
          <?= htmlspecialchars($success) ?>
          <?php if ($resetLink): ?>
            <div class="help" style="margin-top:8px;">
              Demo shortcut: <a href="<?= $resetLink ?>" style="color:var(--accent);">Use this reset link now</a>
            </div>
          <?php endif; ?>
        </div>
        <hr class="sep">
      <?php endif; ?>

      <form method="post">
        <div class="field">
          <label>Email</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>">
        </div>

        <div class="row" style="margin-top:14px;">
          <button class="btn primary" type="submit" style="width:100%;">Send reset link</button>
        </div>
      </form>

      <hr class="sep">
      <p class="help" style="margin:0;">
        <a href="<?= $BASE_URL ?>/login.php" style="color:var(--accent); font-weight:600;">Back to login</a>
      </p>
    </div>
  </div>
</body>
</html>
