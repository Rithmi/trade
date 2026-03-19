<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (!defined('PUBLIC_ROOT')) {
  define('PUBLIC_ROOT', __DIR__);
}

if (!defined('PROJECT_ROOT')) {
  define('PROJECT_ROOT', PUBLIC_ROOT);
}

if (!function_exists('bootstrap_step')) {
  function bootstrap_step(string $message, bool $enabled): void {
    if (!$enabled) {
      return;
    }

    $suffix = PHP_SAPI === 'cli' ? PHP_EOL : '<br>';
    echo $message . $suffix;
    flush();
  }
}

require_once PROJECT_ROOT . '/app/config.php';

$config = app_config();
$debug = !empty($config['debug']);
$BASE_URL = $config['base_url'] ?? '';

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
$timezone = $config['timezone'] ?? 'UTC';
if (!@date_default_timezone_set($timezone)) {
  date_default_timezone_set('UTC');
}

bootstrap_step('bootstrap start', $debug);

require_once PROJECT_ROOT . '/app/response.php';
bootstrap_step('response loaded', $debug);

require_once PROJECT_ROOT . '/app/db.php';
bootstrap_step('db loaded', $debug);

require_once PROJECT_ROOT . '/app/auth.php';
bootstrap_step('auth loaded', $debug);

require_once PROJECT_ROOT . '/app/validators.php';
bootstrap_step('validators loaded', $debug);

require_once PROJECT_ROOT . '/app/crypto.php';
bootstrap_step('crypto loaded', $debug);

require_once PROJECT_ROOT . '/app/schema.php';
bootstrap_step('schema loaded', $debug);