<?php
require __DIR__ . '/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
  header("Location: {$BASE_URL}/dashboard.php");
  exit;
}

$errors = [];
$values = [
  'name' => trim($_POST['name'] ?? ''),
  'email' => trim($_POST['email'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $password = (string)($_POST['password'] ?? '');
  $confirm  = (string)($_POST['password_confirmation'] ?? '');

  if ($values['name'] === '') {
    $errors[] = 'Name is required.';
  }

  if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
  }

  if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters.';
  }

  if ($password !== $confirm) {
    $errors[] = 'Passwords do not match.';
  }

  if (!$errors) {
    ensure_users_table();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$values['email']]);
    if ($stmt->fetch()) {
      $errors[] = 'That email is already registered.';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, is_active) VALUES (?, ?, ?, 1)');
      $insert->execute([$values['name'], $values['email'], $hash]);

      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$pdo->lastInsertId();

      header("Location: {$BASE_URL}/dashboard.php");
      exit;
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Create Account</title>
  <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/app.css">
</head>
<body>
  <div class="container" style="max-width:560px; padding-top:60px;">
    <div class="card">
      <h2 style="margin:0;">Create your account</h2>
      <p class="help">Sign up to configure bots, API keys, and monitor performance.</p>

      <?php if ($errors): ?>
        <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
          <ul style="margin:0; padding-left:18px;">
            <?php foreach ($errors as $err): ?>
              <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <hr class="sep">
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <div class="field">
          <label>Name</label>
          <input type="text" name="name" required value="<?= htmlspecialchars($values['name']) ?>">
        </div>

        <div class="field">
          <label>Email</label>
          <input type="email" name="email" required value="<?= htmlspecialchars($values['email']) ?>">
        </div>

        <div class="field">
          <label>Password</label>
          <input type="password" name="password" required placeholder="Min 8 characters">
        </div>

        <div class="field">
          <label>Confirm Password</label>
          <input type="password" name="password_confirmation" required>
        </div>

        <div class="row" style="margin-top:16px;">
          <button class="btn primary" type="submit" style="width:100%;">Create Account</button>
        </div>
      </form>

      <hr class="sep">
      <p class="help" style="margin:0;">
        Already have an account?
        <a href="<?= $BASE_URL ?>/login.php" style="color:var(--accent); font-weight:600;">Sign in</a>
      </p>
    </div>
  </div>
</body>
</html>
