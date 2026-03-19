<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  if (!extension_loaded('pdo_mysql')) {
    throw new RuntimeException('The pdo_mysql extension is not enabled. Enable it in php.ini to connect to MySQL.');
  }

  $config = app_config();
  $db = $config['db'];
  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['name'],
    $db['charset']
  );

  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];

  if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $options[constant('PDO::MYSQL_ATTR_INIT_COMMAND')] = sprintf('SET NAMES %s COLLATE %s', $db['charset'], $db['collation']);
  }

  try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
  } catch (PDOException $e) {
    $message = 'Database connection failed. Confirm DB_* values inside .env and that MySQL is running.';
    error_log($message . ' PDO said: ' . $e->getMessage());
    throw new RuntimeException($message, (int) $e->getCode(), $e);
  }

  return $pdo;
}