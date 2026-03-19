<?php
declare(strict_types=1);

if (!function_exists('config_env_path')) {
  function config_env_path(): ?string {
    static $detected = false;
    static $cachedPath = null;

    if ($detected) {
      return $cachedPath;
    }

    $detected = true;

    $candidates = [];

    if (defined('PROJECT_ROOT')) {
      $candidates[] = PROJECT_ROOT . DIRECTORY_SEPARATOR . '.env';
    }

    if (defined('PUBLIC_ROOT')) {
      $candidates[] = PUBLIC_ROOT . DIRECTORY_SEPARATOR . '.env';
    }

    $appRoot = dirname(__DIR__);
    $candidates[] = $appRoot . DIRECTORY_SEPARATOR . '.env';
    $candidates[] = dirname($appRoot) . DIRECTORY_SEPARATOR . '.env';
    $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

    foreach (array_unique(array_filter($candidates)) as $path) {
      if (is_file($path)) {
        $cachedPath = realpath($path) ?: $path;
        return $cachedPath;
      }
    }

    $cachedPath = null;
    return null;
  }
}

if (!function_exists('parse_env_value')) {
  function parse_env_value(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $quote = $value[0];
    if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
      $value = substr($value, 1, -1);
      if ($quote === '"') {
        $value = stripcslashes($value);
      }
      return $value;
    }

    $hashPos = strpos($value, '#');
    if ($hashPos !== false) {
      $charBefore = $hashPos > 0 ? $value[$hashPos - 1] : '';
      if ($charBefore === ' ' || $charBefore === "\t") {
        $value = rtrim(substr($value, 0, $hashPos));
      }
    }

    return trim($value);
  }
}

if (!function_exists('load_env_file')) {
  function load_env_file(): array {
    static $cache = null;
    if ($cache !== null) {
      return $cache;
    }

    $cache = [];
    $path = config_env_path();
    if ($path === null || !is_readable($path)) {
      return $cache;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
      return $cache;
    }

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#') {
        continue;
      }

      [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
      $key = preg_replace('/^export\s+/i', '', trim($key));
      if ($key === '') {
        continue;
      }

      $value = parse_env_value($value);
      $cache[$key] = $value;

      if (!array_key_exists($key, $_ENV)) {
        $_ENV[$key] = $value;
      }

      if (!array_key_exists($key, $_SERVER)) {
        $_SERVER[$key] = $value;
      }
    }

    return $cache;
  }
}

if (!function_exists('env')) {
  function env(string $key, $default = null) {
    $key = trim($key);
    if ($key === '') {
      return $default;
    }

    $envFile = load_env_file();

    if (array_key_exists($key, $_ENV)) {
      return $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
      return $_SERVER[$key];
    }

    $value = getenv($key);
    if ($value !== false) {
      return $value;
    }

    if (array_key_exists($key, $envFile)) {
      return $envFile[$key];
    }

    return $default;
  }
}

if (!function_exists('env_bool')) {
  function env_bool(string $key, bool $default = false): bool {
    $value = env($key);
    if ($value === null) {
      return $default;
    }

    if (is_bool($value)) {
      return $value;
    }

    $normalized = strtolower((string) $value);
    if ($normalized === '') {
      return $default;
    }

    return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
  }
}

if (!function_exists('detect_default_base_url')) {
  function detect_default_base_url(): string {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $publicRoot = dirname(__DIR__);

    if ($publicRoot === '') {
      return '';
    }

    $documentRoot = $documentRoot !== '' ? (realpath($documentRoot) ?: $documentRoot) : '';
    $publicRoot = realpath($publicRoot) ?: $publicRoot;

    $documentRoot = $documentRoot !== '' ? rtrim(str_replace('\\', '/', $documentRoot), '/') : '';
    $publicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/');

    if ($documentRoot === '' || !str_starts_with($publicRoot, $documentRoot)) {
      return '';
    }

    $relative = trim(substr($publicRoot, strlen($documentRoot)), '/');

    return $relative === '' ? '' : '/' . $relative;
  }
}

if (!function_exists('app_config')) {
  function app_config(): array {
    static $config = null;
    if ($config !== null) {
      return $config;
    }

    $defaultBaseUrl = detect_default_base_url();
    $baseUrl = (string) env('BASE_URL', $defaultBaseUrl);
    $baseUrl = trim($baseUrl);

    if ($baseUrl === '/' || $baseUrl === '.') {
      $baseUrl = '';
    }

    $hasScheme = $baseUrl !== '' && preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $baseUrl) === 1;

    if ($baseUrl === '') {
      // Leave empty to represent the web root ("/"), so concatenations render "/path".
    } elseif ($hasScheme) {
      $baseUrl = rtrim($baseUrl, '/');
    } else {
      $baseUrl = '/' . ltrim($baseUrl, '/');
      $baseUrl = rtrim($baseUrl, '/');
    }

    $config = [
      'debug' => env_bool('APP_DEBUG', false),
      'timezone' => env('APP_TZ', 'UTC') ?: 'UTC',
      'base_url' => $baseUrl,
      'db' => [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => (int) env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'trades'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
      ],
    ];

    return $config;
  }
}

return app_config();