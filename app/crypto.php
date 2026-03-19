<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function ensure_crypto_ready(): void {
  static $checked = false;
  if ($checked) {
    return;
  }

  if (!extension_loaded('openssl')) {
    throw new RuntimeException('The OpenSSL extension is not enabled. Enable it in php.ini to use encryption helpers.');
  }

  $methods = openssl_get_cipher_methods();
  $supportsAes256Gcm = false;
  if ($methods !== false) {
    foreach ($methods as $method) {
      if (strtolower($method) === 'aes-256-gcm') {
        $supportsAes256Gcm = true;
        break;
      }
    }
  }

  if (!$supportsAes256Gcm) {
    throw new RuntimeException('This PHP build lacks AES-256-GCM support. Update OpenSSL or use a compatible build.');
  }

  $checked = true;
}

function app_key_bytes(): string {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $rawKey = trim((string) env('APP_KEY', ''));
  if ($rawKey === '') {
    throw new RuntimeException('APP_KEY missing in .env. Generate a 32-byte key and set APP_KEY=.');
  }

  if (str_starts_with($rawKey, 'base64:')) {
    $decoded = base64_decode(substr($rawKey, 7), true);
    if ($decoded === false) {
      throw new RuntimeException('APP_KEY (base64) is invalid.');
    }
    if (strlen($decoded) < 32) {
      throw new RuntimeException('APP_KEY must decode to at least 32 bytes.');
    }
    $cached = substr($decoded, 0, 32);
    return $cached;
  }

  if (strlen($rawKey) < 32) {
    throw new RuntimeException('APP_KEY must be at least 32 characters long.');
  }

  $cached = substr($rawKey, 0, 32);
  return $cached;
}

function encrypt_str(string $plaintext): string {
  ensure_crypto_ready();
  $key = app_key_bytes();
  $iv = random_bytes(12);
  $tag = '';
  $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

  if ($cipher === false || strlen($tag) !== 16) {
    throw new RuntimeException('Unable to encrypt value. Check OpenSSL support.');
  }

  $payload = [
    'v' => 1,
    'iv' => base64_encode($iv),
    'tag' => base64_encode($tag),
    'value' => base64_encode($cipher),
  ];

  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    throw new RuntimeException('Failed to encode encrypted payload.');
  }

  return base64_encode($json);
}

function decrypt_str(string $ciphertext): string {
  ensure_crypto_ready();
  $ciphertext = trim($ciphertext);
  if ($ciphertext === '') {
    throw new RuntimeException('Encrypted value is empty.');
  }

  $key = app_key_bytes();

  $payload = decode_versioned_payload($ciphertext);
  if ($payload !== null) {
    return decrypt_from_payload($payload, $key);
  }

  $fallback = base64_decode($ciphertext, true);
  if ($fallback !== false) {
    $ciphertext = $fallback;
  }

  if (strlen($ciphertext) < 29) {
    throw new RuntimeException('Encrypted value is corrupted.');
  }

  $iv = substr($ciphertext, 0, 12);
  $tag = substr($ciphertext, 12, 16);
  $cipherRaw = substr($ciphertext, 28);

  if ($iv === '' || strlen($tag) !== 16 || $cipherRaw === '') {
    throw new RuntimeException('Encrypted value is malformed.');
  }

  $plain = openssl_decrypt($cipherRaw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) {
    throw new RuntimeException('Unable to decrypt value. Verify APP_KEY.');
  }

  return $plain;
}

function decode_versioned_payload(string $blob): ?array {
  $decoded = base64_decode($blob, true);
  if ($decoded === false) {
    return null;
  }

  $data = json_decode($decoded, true);
  if (!is_array($data)) {
    return null;
  }

  if (($data['v'] ?? null) !== 1) {
    return null;
  }

  if (!isset($data['iv'], $data['tag'], $data['value'])) {
    return null;
  }

  return $data;
}

function decrypt_from_payload(array $payload, string $key): string {
  $iv = base64_decode((string) $payload['iv'], true);
  $tag = base64_decode((string) $payload['tag'], true);
  $cipherRaw = base64_decode((string) $payload['value'], true);

  if ($iv === false || $tag === false || $cipherRaw === false) {
    throw new RuntimeException('Encrypted payload is invalid.');
  }

  if (strlen($iv) !== 12 || strlen($tag) !== 16 || $cipherRaw === '') {
    throw new RuntimeException('Encrypted payload is malformed.');
  }

  $plain = openssl_decrypt($cipherRaw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) {
    throw new RuntimeException('Unable to decrypt value. Verify APP_KEY.');
  }

  return $plain;
}

function mask_key(string $apiKey): string {
  $apiKey = trim($apiKey);
  if ($apiKey === '') {
    return '';
  }
  if (strlen($apiKey) <= 8) {
    return str_repeat('*', strlen($apiKey));
  }
  return substr($apiKey, 0, 4) . str_repeat('*', strlen($apiKey) - 8) . substr($apiKey, -4);
}