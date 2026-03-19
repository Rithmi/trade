<?php
require __DIR__ . '/bootstrap.php';

$token = trim($_POST['token'] ?? $_GET['token'] ?? '');
$token = strtolower($token);
$tokenRow = null;
$errors = [];

$hasToken = $token !== '' && ctype_xdigit($token) && strlen($token) >= 32;

if ($hasToken) {
  ensure_password_resets_table();
  $pdo = db();
  $hash = hash('sha256', $token);
  $stmt = $pdo->prepare('SELECT id, user_id, expires_at FROM password_resets WHERE token_hash = ? LIMIT 1');
  $stmt->execute([$hash]);
  $row = $stmt->fetch();
  if ($row) {
    $expires = new DateTimeImmutable($row['expires_at']);
    if ($expires >= new DateTimeImmutable('now')) {
      $tokenRow = $row;
    }
  }
}

if (!$tokenRow) {
  $hasToken = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = (string)($_POST['password'] ?? '');
  $confirm  = (string)($_POST['password_confirmation'] ?? '');

  if (!$hasToken) {
    $errors[] = 'Reset link is invalid or has expired. Please request a new one.';
  }

  if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
  }

  if ($password !== $confirm) {
    $errors[] = 'Passwords do not match.';
  }

  if (!$errors && $tokenRow) {
    ensure_users_table();
    $hashPassword = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hashPassword, $tokenRow['user_id']]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$tokenRow['user_id']]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$tokenRow['user_id'];

    header("Location: {$BASE_URL}/dashboard.php");
    exit;
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Choose a new password</title>
  <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css">
</head>
<body>
  <div class="container" style="max-width:520px; padding-top:60px;">
    <div class="card">
      <h2 style="margin:0;">Set a new password</h2>
      <p class="help">Passwords must be at least 8 characters.</p>

      <?php if (!$hasToken): ?>
        <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
          Reset link invalid or expired. <a href="<?= $BASE_URL ?>/forgot_password.php" style="color:var(--accent);">Request a new one</a>.
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <hr class="sep">
        <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
          <ul style="margin:0; padding-left:18px;">
            <?php foreach ($errors as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($hasToken): ?>
        <hr class="sep">
        <form method="post">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

          <div class="field">
            <label>New password</label>
            <input type="password" name="password" required placeholder="Min 8 characters">
          </div>

          <div class="field">
            <label>Confirm password</label>
            <input type="password" name="password_confirmation" required>
          </div>

          <div class="row" style="margin-top:14px;">
            <button class="btn primary" type="submit" style="width:100%;">Update password</button>
          </div>
        </form>
      <?php endif; ?>

      <hr class="sep">
      <p class="help" style="margin:0;">
        <a href="<?= $BASE_URL ?>/login.php" style="color:var(--accent); font-weight:600;">Back to login</a>
      </p>
    </div>
  </div>
</body>
</html>
