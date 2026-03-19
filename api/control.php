<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../bot_control.php';

json_begin_buffer();
require_login($BASE_URL);

try {
  $user = current_user();
  if (!$user) {
    json_error('Unauthorized', 401);
  }

  $action = strtolower((string) ($_GET['action'] ?? $_POST['action'] ?? 'status'));
  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

  switch ($action) {
    case 'status':
      json_success(get_bot_status(), 200, ['action' => 'status']);
      break;

    case 'start':
      if ($method !== 'POST') {
        json_error('Use POST for start', 405);
      }
      json_success(start_bot_engine(), 200, ['action' => 'start']);
      break;

    case 'stop':
      if ($method !== 'POST') {
        json_error('Use POST for stop', 405);
      }
      json_success(stop_bot_engine(), 200, ['action' => 'stop']);
      break;

    default:
      json_error('Unsupported action', 400);
  }
} catch (RuntimeException $e) {
  json_error($e->getMessage(), 500);
} catch (Throwable $e) {
  error_log('control api error: ' . $e->getMessage());
  json_error('Unable to process control action', 500);
}
