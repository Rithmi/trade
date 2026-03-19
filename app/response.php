<?php
declare(strict_types=1);

// Using fully qualified name avoids redundant use statements when no namespace

const JSON_RESPONSE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

function json_prepare_headers(int $status): void {
  if (!headers_sent()) {
    header_remove('Content-Type');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
  }

  http_response_code($status);
}

function json_clear_output_buffers(): void {
  if (!function_exists('ob_get_level')) {
    return;
  }

  while (ob_get_level() > 0) {
    ob_end_clean();
  }
}

function json_begin_buffer(): void {
  if (function_exists('ob_get_level') && ob_get_level() === 0) {
    ob_start();
  }
}

function json_response(array $payload, int $status = 200): void {
  json_clear_output_buffers();
  json_prepare_headers($status);

  try {
    $body = json_encode($payload, JSON_RESPONSE_FLAGS | JSON_THROW_ON_ERROR);
  } catch (JsonException $e) {
    http_response_code(500);
    $fallback = [
      'success' => false,
      'message' => 'Failed to encode JSON response.',
      'meta' => ['error' => $e->getMessage()],
    ];
    $body = json_encode($fallback, JSON_RESPONSE_FLAGS);
  }

  echo $body ?? '';
  exit;
}

function json_success(array $data = [], int $status = 200, array $meta = []): void {
  json_response([
    'success' => true,
    'data' => $data,
    'meta' => $meta,
  ], $status);
}

function json_error(string $message, int $status = 400, array $meta = []): void {
  json_response([
    'success' => false,
    'message' => $message,
    'meta' => $meta,
  ], $status);
}
