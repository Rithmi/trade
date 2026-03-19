<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

function request_expects_json(): bool {
  $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
  if ($accept !== '' && str_contains($accept, 'application/json')) {
    return true;
  }

  $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
  if ($requestedWith === 'xmlhttprequest') {
    return true;
  }

  return false;
}

function require_login(string $baseUrl): void {
  if (empty($_SESSION['user_id'])) {
    if (function_exists('json_error') && request_expects_json()) {
      json_error('Unauthorized', 401);
    }

    header("Location: {$baseUrl}/login.php");
    exit;
  }
}

function current_user(): ?array {
  ensure_users_table();
  if (empty($_SESSION['user_id'])) {
    return null;
  }

  $stmt = db()->prepare('SELECT id, email, name FROM users WHERE id = ? AND is_active = 1');
  $stmt->execute([$_SESSION['user_id']]);
  $user = $stmt->fetch();

  return $user ?: null;
}

function attempt_login(string $email, string $password): bool {
  ensure_users_table();
  $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ? AND is_active = 1');
  $stmt->execute([$email]);
  $user = $stmt->fetch();

  if (!$user || !password_verify($password, $user['password_hash'])) {
    return false;
  }

  session_regenerate_id(true);
  $_SESSION['user_id'] = $user['id'];
  return true;
}

function logout_user(): void {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}