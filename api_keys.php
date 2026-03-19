<?php
require __DIR__ . '/bootstrap.php';
require_login($BASE_URL);

const API_EXCHANGE = 'BINANCE_FUTURES';

ensure_users_table();
ensure_api_keys_table();

$pageTitle = 'API Keys';
$activeNav = 'apikeys';

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$userEmail = $currentUser['email'] ?? 'Guest';

$pdo = db();

$requestedMode = $_POST['mode'] ?? $_GET['mode'] ?? null;
if ($requestedMode !== null) {
  $mode = sanitize_mode($requestedMode);
} else {
  $mode = fetch_last_mode($pdo, $userId, API_EXCHANGE) ?? 'TESTNET';
}

$hasKeys = false;
$storedKeyMasked = '';
$lastUpdated = null;
$flash = null;
$errors = [];

$credentials = fetch_credentials($pdo, $userId, API_EXCHANGE, $mode);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'save';
  $mode = sanitize_mode($_POST['mode'] ?? $mode);

  if ($action === 'disconnect') {
    try {
      $deleted = delete_credentials($pdo, $userId, API_EXCHANGE, $mode);
      $flash = $deleted > 0
        ? sprintf('Removed %s Binance keys.', human_mode_label($mode))
        : sprintf('No %s keys were stored.', human_mode_label($mode));
      $credentials = null;
    } catch (Throwable $e) {
      error_log('Failed to delete API keys: ' . $e->getMessage());
      $errors[] = 'Unable to delete stored keys right now. Please retry in a moment.';
    }
  } else {
    $apiKey = trim((string) ($_POST['api_key'] ?? ''));
    $apiSecret = trim((string) ($_POST['api_secret'] ?? ''));

    $errors = array_merge($errors, validate_credentials_input($apiKey, $apiSecret));

    if (empty($errors)) {
      try {
        $apiKeyEnc = encrypt_str($apiKey);
        $apiSecretEnc = encrypt_str($apiSecret);
      } catch (Throwable $e) {
        error_log('Encryption failed for API keys: ' . $e->getMessage());
        $errors[] = 'Unable to encrypt credentials. Verify APP_KEY in .env.';
      }
    }

    if (empty($errors)) {
      try {
        save_credentials($pdo, $userId, API_EXCHANGE, $mode, $apiKeyEnc, $apiSecretEnc);
        $flash = sprintf('Saved %s Binance credentials securely.', human_mode_label($mode));
        $credentials = fetch_credentials($pdo, $userId, API_EXCHANGE, $mode);
      } catch (Throwable $e) {
        error_log('Failed to persist API keys: ' . $e->getMessage());
        $errors[] = 'Unable to store credentials. Check database access and try again.';
      }
    }
  }
}

if ($credentials && empty($errors)) {
  try {
    $storedKeyMasked = mask_key(decrypt_str($credentials['api_key_enc']));
    $lastUpdated = $credentials['updated_at'] ?? null;
    $hasKeys = true;
  } catch (Throwable $e) {
    error_log('Failed to decrypt stored API key: ' . $e->getMessage());
    $errors[] = 'Encrypted keys exist but cannot be decrypted. Ensure APP_KEY matches the original value.';
    $storedKeyMasked = '';
    $hasKeys = false;
  }
}

function selected($a, $b): string {
  return ((string) $a === (string) $b) ? 'selected' : '';
}

function sanitize_mode(?string $value): string {
  return strtoupper((string) $value) === 'LIVE' ? 'LIVE' : 'TESTNET';
}

function human_mode_label(string $mode): string {
  return $mode === 'LIVE' ? 'Live (real funds)' : 'Testnet';
}

function validate_credentials_input(string $apiKey, string $apiSecret): array {
  $issues = [];

  if ($apiKey === '' || $apiSecret === '') {
    $issues[] = 'API Key and API Secret are required.';
    return $issues;
  }

  if (strlen($apiKey) < 10) {
    $issues[] = 'API Key looks too short. Double-check the value from Binance.';
  }

  if (strlen($apiSecret) < 20) {
    $issues[] = 'API Secret looks too short. Copy the full secret from Binance (it is shown only once).';
  }

  return $issues;
}

function fetch_credentials(PDO $pdo, int $userId, string $exchange, string $mode): ?array {
  $stmt = $pdo->prepare('SELECT api_key_enc, api_secret_enc, mode, updated_at
                         FROM api_keys
                         WHERE user_id=? AND exchange=? AND mode=?
                         LIMIT 1');
  $stmt->execute([$userId, $exchange, $mode]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function fetch_last_mode(PDO $pdo, int $userId, string $exchange): ?string {
  $stmt = $pdo->prepare('SELECT mode
                         FROM api_keys
                         WHERE user_id=? AND exchange=?
                         ORDER BY updated_at DESC
                         LIMIT 1');
  $stmt->execute([$userId, $exchange]);
  $mode = $stmt->fetchColumn();
  return $mode ? sanitize_mode($mode) : null;
}

function save_credentials(PDO $pdo, int $userId, string $exchange, string $mode, string $apiKeyEnc, string $apiSecretEnc): void {
  $sql = 'INSERT INTO api_keys (user_id, exchange, mode, api_key_enc, api_secret_enc, updated_at)
          VALUES (?, ?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE
            api_key_enc = VALUES(api_key_enc),
            api_secret_enc = VALUES(api_secret_enc),
            updated_at = NOW()';
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId, $exchange, $mode, $apiKeyEnc, $apiSecretEnc]);
}

function delete_credentials(PDO $pdo, int $userId, string $exchange, string $mode): int {
  $stmt = $pdo->prepare('DELETE FROM api_keys WHERE user_id=? AND exchange=? AND mode=?');
  $stmt->execute([$userId, $exchange, $mode]);
  return (int) $stmt->rowCount();
}

function format_timestamp(?string $ts): ?string {
  if (!$ts) {
    return null;
  }

  try {
    $dt = new DateTime($ts);
    return $dt->format('M j, Y g:i A');
  } catch (Throwable $e) {
    return $ts;
  }
}
?>
<?php require __DIR__ . '/_layout_top.php'; ?>

<div class="grid">
  <div class="card">
    <div class="row">
      <div>
        <h2 style="margin:0;">Connect Binance Futures</h2>
        <p class="help">
          Add your Binance API Key + Secret so the Python bot can place Futures orders.
          For safety, start with <b>Testnet</b> first.
        </p>
      </div>
      <div class="row">
        <a class="btn" href="<?= $BASE_URL ?>/dashboard.php">← Back</a>
        <button form="keysForm" class="btn primary" type="submit">Save</button>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <hr class="sep">
      <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
        <b>Please fix:</b><br>
        <?php foreach ($errors as $e): ?>
          • <?= htmlspecialchars($e) ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <hr class="sep">
      <div class="alert"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
  </div>

  <div class="grid grid-2">
    <div class="card">
      <h2>API Credentials</h2>
      <p class="help">Stored encrypted in MySQL using AES-256-GCM.</p>

      <form id="keysForm" method="post">
        <input type="hidden" name="action" value="save">

        <div class="field">
          <label>Environment</label>
          <select name="mode">
            <option value="TESTNET" <?= selected($mode, 'TESTNET') ?>>Testnet (recommended)</option>
            <option value="LIVE" <?= selected($mode, 'LIVE') ?>>Live (real funds)</option>
          </select>
        </div>

        <div class="field">
          <label>Exchange</label>
          <select name="exchange" disabled>
            <option>Binance Futures (USDT-M)</option>
          </select>
        </div>

        <div class="field">
          <label>API Key</label>
          <input name="api_key" placeholder="Paste Binance API key" autocomplete="off">
        </div>

        <div class="field">
          <label>API Secret</label>
          <input name="api_secret" type="password" placeholder="Paste Binance API secret" autocomplete="off">
        </div>

        <hr class="sep">

        <div class="row">
          <button class="btn primary" type="submit">Save Keys</button>
          <button class="btn danger" type="submit" name="action" value="disconnect"
                  onclick="return confirm('Disconnect and delete stored keys?')">
            Disconnect
          </button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Status</h2>

      <div class="row">
        <span class="badge"><?= htmlspecialchars($mode) ?></span>
        <?php if ($hasKeys): ?>
          <span class="badge on">CONNECTED</span>
        <?php else: ?>
          <span class="badge off">NOT CONNECTED</span>
        <?php endif; ?>
      </div>

      <hr class="sep">

      <h2>Stored Key</h2>
      <?php if ($hasKeys): ?>
        <p style="margin:8px 0; font-weight:700; letter-spacing:0.5px;">
          <?= htmlspecialchars($storedKeyMasked) ?>
        </p>
        <?php if ($lastUpdated): ?>
          <p class="help">Last updated <?= htmlspecialchars(format_timestamp($lastUpdated) ?? $lastUpdated) ?>.</p>
        <?php endif; ?>
        <p class="help">
          Key is masked here. Raw key/secret are encrypted in the database using APP_KEY.
        </p>
      <?php else: ?>
        <p class="help">No keys stored yet.</p>
      <?php endif; ?>

      <hr class="sep">

      <h2>Next step</h2>
      <p class="help">
        We’ll add a “Test Connection” button that calls Binance to validate permissions,
        and later Python will read these encrypted values.
      </p>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>