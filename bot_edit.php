<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login($BASE_URL);

require_once PUBLIC_ROOT . '/app/schema.php';
ensure_bots_table();

$pageTitle = 'Bot Editor';
$activeNav = 'bots';

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$userEmail = $currentUser['email'] ?? 'Guest';

$pdo = db();
$botId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
$isEdit = $botId > 0;

$allowedDirections = ['LONG', 'SHORT'];
$allowedMarginModes = ['ISOLATED', 'CROSS'];
$allowedTimeframes = ['1m', '3m', '5m', '15m', '1h', '4h', '1d'];
$allowedSignals = ['PSAR', 'MANUAL', 'CUSTOM'];

$defaults = [
  'id' => 0,
  'name' => '',
  'symbol' => 'BTCUSDT',
  'direction' => 'LONG',
  'leverage' => 5,
  'margin_mode' => 'ISOLATED',
  'timeframe' => '5m',
  'signal_type' => 'PSAR',
  'base_order_usdt' => 10.0,
  'dca_multiplier' => 2.0,
  'max_safety_orders' => 6,
  'next_dca_trigger_drop_pct' => 1.0,
  'bounce_from_local_low_pct' => 0.5,
  'trailing_activation_profit_pct' => 0.3,
  'trailing_drawdown_pct' => 0.4,
  'allow_reentry' => 1,
  'is_enabled' => 0,
];

$form = $defaults;
$errors = [];

if ($isEdit) {
  $stmt = $pdo->prepare('SELECT * FROM bots WHERE id = ? AND user_id = ? LIMIT 1');
  $stmt->execute([$botId, $userId]);
  $existing = $stmt->fetch();

  if (!$existing) {
    header("Location: {$BASE_URL}/dashboard.php");
    exit;
  }

  $form = array_merge($form, $existing ?: []);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['form_action'] ?? 'save';

  if ($action === 'delete' && $isEdit) {
    try {
      $del = $pdo->prepare('DELETE FROM bots WHERE id = ? AND user_id = ?');
      $del->execute([$botId, $userId]);
      if ($del->rowCount()) {
        $_SESSION['bot_flash'] = sprintf('Deleted bot "%s".', $form['name'] ?? 'Bot');
        header("Location: {$BASE_URL}/dashboard.php#bot-list");
        exit;
      }
      $errors[] = 'Unable to delete this bot. Please try again.';
    } catch (Throwable $e) {
      error_log('bot delete error: ' . $e->getMessage());
      $errors[] = 'Unexpected error while deleting the bot.';
    }
  } else {
    try {
      $form['name'] = require_string($_POST['name'] ?? $form['name'], 'Session name', 3, 120);
    } catch (InvalidArgumentException $e) {
      $errors[] = $e->getMessage();
    }

    $form['symbol'] = sanitize_symbol($_POST['symbol'] ?? $form['symbol']);
    if ($form['symbol'] === '') {
      $errors[] = 'Trading pair (symbol) is required (e.g. BTCUSDT).';
    }

    try {
      $form['direction'] = require_enum($_POST['direction'] ?? $form['direction'], $allowedDirections, 'Direction');
      $form['margin_mode'] = require_enum($_POST['margin_mode'] ?? $form['margin_mode'], $allowedMarginModes, 'Margin mode');
      $timeframe = sanitize_string($_POST['timeframe'] ?? $form['timeframe']);
      if (!in_array($timeframe, $allowedTimeframes, true)) {
        throw new InvalidArgumentException('Select a valid timeframe.');
      }
      $form['timeframe'] = $timeframe;
      $form['signal_type'] = require_enum($_POST['signal_type'] ?? $form['signal_type'], $allowedSignals, 'Signal type');
    } catch (InvalidArgumentException $e) {
      $errors[] = $e->getMessage();
    }

    try {
      $form['leverage'] = require_int_range($_POST['leverage'] ?? $form['leverage'], 'Leverage', 1, 125);
      $form['base_order_usdt'] = require_float_range($_POST['base_order_usdt'] ?? $form['base_order_usdt'], 'Base order (USDT)', 0.01, 1000000);
      $form['dca_multiplier'] = require_float_range($_POST['dca_multiplier'] ?? $form['dca_multiplier'], 'DCA multiplier', 1.0, 100.0);
      $form['max_safety_orders'] = require_int_range($_POST['max_safety_orders'] ?? $form['max_safety_orders'], 'Max safety orders', 0, 100);
      $form['next_dca_trigger_drop_pct'] = require_float_range($_POST['next_dca_trigger_drop_pct'] ?? $form['next_dca_trigger_drop_pct'], 'Next DCA trigger drop %', 0.0, 100.0);
      $form['bounce_from_local_low_pct'] = require_float_range($_POST['bounce_from_local_low_pct'] ?? $form['bounce_from_local_low_pct'], 'Bounce from local low %', 0.0, 100.0);
      $form['trailing_activation_profit_pct'] = require_float_range($_POST['trailing_activation_profit_pct'] ?? $form['trailing_activation_profit_pct'], 'Trailing activation profit %', 0.0, 100.0);
      $form['trailing_drawdown_pct'] = require_float_range($_POST['trailing_drawdown_pct'] ?? $form['trailing_drawdown_pct'], 'Trailing drawdown %', 0.0, 100.0);
    } catch (InvalidArgumentException $e) {
      $errors[] = $e->getMessage();
    }

    $form['allow_reentry'] = isset($_POST['allow_reentry']) ? (int) $_POST['allow_reentry'] : $form['allow_reentry'];
    $form['allow_reentry'] = $form['allow_reentry'] ? 1 : 0;
    $form['is_enabled'] = isset($_POST['is_enabled']) ? (int) $_POST['is_enabled'] : $form['is_enabled'];
    $form['is_enabled'] = $form['is_enabled'] ? 1 : 0;

    if (empty($errors)) {
      $payload = [
        'name' => $form['name'],
        'symbol' => $form['symbol'],
        'direction' => $form['direction'],
        'leverage' => $form['leverage'],
        'margin_mode' => $form['margin_mode'],
        'timeframe' => $form['timeframe'],
        'signal_type' => $form['signal_type'],
        'base_order_usdt' => $form['base_order_usdt'],
        'dca_multiplier' => $form['dca_multiplier'],
        'max_safety_orders' => $form['max_safety_orders'],
        'next_dca_trigger_drop_pct' => $form['next_dca_trigger_drop_pct'],
        'bounce_from_local_low_pct' => $form['bounce_from_local_low_pct'],
        'trailing_activation_profit_pct' => $form['trailing_activation_profit_pct'],
        'trailing_drawdown_pct' => $form['trailing_drawdown_pct'],
        'allow_reentry' => $form['allow_reentry'],
        'is_enabled' => $form['is_enabled'],
      ];

      try {
        if ($isEdit) {
          $stmt = $pdo->prepare('UPDATE bots SET
            name = :name,
            symbol = :symbol,
            direction = :direction,
            leverage = :leverage,
            margin_mode = :margin_mode,
            timeframe = :timeframe,
            signal_type = :signal_type,
            base_order_usdt = :base_order_usdt,
            dca_multiplier = :dca_multiplier,
            max_safety_orders = :max_safety_orders,
            next_dca_trigger_drop_pct = :next_dca_trigger_drop_pct,
            bounce_from_local_low_pct = :bounce_from_local_low_pct,
            trailing_activation_profit_pct = :trailing_activation_profit_pct,
            trailing_drawdown_pct = :trailing_drawdown_pct,
            allow_reentry = :allow_reentry,
            is_enabled = :is_enabled,
            updated_at = NOW()
            WHERE id = :id AND user_id = :user_id');
          $stmt->execute($payload + ['id' => $botId, 'user_id' => $userId]);
          $_SESSION['bot_flash'] = sprintf('Updated "%s".', $form['name']);
        } else {
          $stmt = $pdo->prepare('INSERT INTO bots (
            user_id, name, symbol, direction, leverage, margin_mode, timeframe, signal_type,
            base_order_usdt, dca_multiplier, max_safety_orders,
            next_dca_trigger_drop_pct, bounce_from_local_low_pct,
            trailing_activation_profit_pct, trailing_drawdown_pct,
            allow_reentry, is_enabled
          ) VALUES (
            :user_id, :name, :symbol, :direction, :leverage, :margin_mode, :timeframe, :signal_type,
            :base_order_usdt, :dca_multiplier, :max_safety_orders,
            :next_dca_trigger_drop_pct, :bounce_from_local_low_pct,
            :trailing_activation_profit_pct, :trailing_drawdown_pct,
            :allow_reentry, :is_enabled
          )');
          $stmt->execute($payload + ['user_id' => $userId]);
          $_SESSION['bot_flash'] = sprintf('Created "%s".', $form['name']);
        }

        header("Location: {$BASE_URL}/dashboard.php#bot-list");
        exit;
      } catch (Throwable $e) {
        error_log('bot save error: ' . $e->getMessage());
        $errors[] = 'Unable to save this bot. Please try again.';
      }
    }
  }
}

function selected($a, $b): string {
  return ((string) $a === (string) $b) ? 'selected' : '';
}
?>
<?php require __DIR__ . '/_layout_top.php'; ?>

<?php if ($isEdit): ?>
  <form id="bot-delete-form" method="post" style="display:none;">
    <input type="hidden" name="form_action" value="delete">
  </form>
<?php endif; ?>

<div class="grid">
  <div class="card">
    <div class="row">
      <div>
        <h2 style="margin:0;"><?= $isEdit ? 'Edit Bot Session' : 'Create Bot Session' ?></h2>
        <p class="help">One session controls a single symbol + direction with its own sizing rules.</p>
      </div>
      <div class="row">
        <a class="btn" href="<?= $BASE_URL ?>/dashboard.php">← Back</a>
        <?php if ($isEdit): ?>
          <button form="bot-delete-form" class="btn danger" type="submit" onclick="return confirm('Delete this bot session? This cannot be undone.');">Delete</button>
        <?php endif; ?>
        <button form="botForm" class="btn primary" type="submit">Save</button>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <hr class="sep">
      <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);">
        <b>Please fix:</b><br>
        <?php foreach ($errors as $message): ?>
          • <?= htmlspecialchars($message) ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <form id="botForm" method="post" class="grid">
    <input type="hidden" name="form_action" value="save">

    <div class="card">
      <h2>Session Identity</h2>
      <p class="help">Name and symbol help you recognize each bot quickly.</p>

      <div class="grid grid-2">
        <div class="field">
          <label>Session Name</label>
          <input name="name" value="<?= htmlspecialchars((string) $form['name']) ?>" placeholder="e.g. BTC Long Nightingale" required>
          <div class="help">3–120 characters.</div>
        </div>

        <div class="field">
          <label>Trading Pair (Symbol)</label>
          <input name="symbol" value="<?= htmlspecialchars((string) $form['symbol']) ?>" placeholder="BTCUSDT" required>
          <div class="help">Binance Futures symbol (no slash).</div>
        </div>
      </div>

      <div class="grid grid-3">
        <div class="field">
          <label>Direction</label>
          <select name="direction">
            <?php foreach ($allowedDirections as $dir): ?>
              <option value="<?= $dir ?>" <?= selected($form['direction'], $dir) ?>><?= $dir ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">LONG buys first, SHORT sells first.</div>
        </div>

        <div class="field">
          <label>Timeframe</label>
          <select name="timeframe">
            <?php foreach ($allowedTimeframes as $tf): ?>
              <option value="<?= $tf ?>" <?= selected($form['timeframe'], $tf) ?>><?= $tf ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">Used when computing signals.</div>
        </div>

        <div class="field">
          <label>Entry Signal</label>
          <select name="signal_type">
            <?php foreach ($allowedSignals as $signal): ?>
              <option value="<?= $signal ?>" <?= selected($form['signal_type'], $signal) ?>><?= $signal ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">PSAR is automated. Manual keeps session always armed.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Futures Settings</h2>
      <p class="help">Risk controls for leverage, margin mode, and enablement.</p>

      <div class="grid grid-3">
        <div class="field">
          <label>Leverage</label>
          <input type="number" min="1" max="125" step="1" name="leverage" value="<?= (int) $form['leverage'] ?>" required>
          <div class="help">1–125x.</div>
        </div>

        <div class="field">
          <label>Margin Mode</label>
          <select name="margin_mode">
            <?php foreach ($allowedMarginModes as $mode): ?>
              <option value="<?= $mode ?>" <?= selected($form['margin_mode'], $mode) ?>><?= $mode ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help">Isolated keeps risk per position.</div>
        </div>

        <div class="field">
          <label>Enable Session</label>
          <select name="is_enabled">
            <option value="0" <?= selected($form['is_enabled'], 0) ?>>Disabled</option>
            <option value="1" <?= selected($form['is_enabled'], 1) ?>>Enabled</option>
          </select>
          <div class="help">Python bot only runs enabled sessions.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Order Sizing</h2>
      <p class="help">Base order and DCA settings for Nightingale-style scaling.</p>

      <div class="grid grid-3">
        <div class="field">
          <label>Base Order (USDT)</label>
          <input type="number" min="0.01" step="0.01" name="base_order_usdt" value="<?= htmlspecialchars((string) $form['base_order_usdt']) ?>" required>
          <div class="help">Initial order size.</div>
        </div>

        <div class="field">
          <label>DCA Multiplier</label>
          <input type="number" min="1" step="0.01" name="dca_multiplier" value="<?= htmlspecialchars((string) $form['dca_multiplier']) ?>" required>
          <div class="help">2.0 doubles each additional order.</div>
        </div>

        <div class="field">
          <label>Max Safety Orders</label>
          <input type="number" min="0" step="1" name="max_safety_orders" value="<?= (int) $form['max_safety_orders'] ?>" required>
          <div class="help">How many times to average in.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Drop + Bounce Logic</h2>
      <p class="help">Control when the bot adds DCAs and how it confirms a bounce.</p>

      <div class="grid grid-2">
        <div class="field">
          <label>Next DCA Trigger Drop (%)</label>
          <input type="number" min="0" step="0.01" name="next_dca_trigger_drop_pct" value="<?= htmlspecialchars((string) $form['next_dca_trigger_drop_pct']) ?>" required>
        </div>

        <div class="field">
          <label>Bounce From Local Low (%)</label>
          <input type="number" min="0" step="0.01" name="bounce_from_local_low_pct" value="<?= htmlspecialchars((string) $form['bounce_from_local_low_pct']) ?>" required>
        </div>
      </div>

      <div class="grid grid-2">
        <div class="field">
          <label>Allow Re-entry After Exit</label>
          <select name="allow_reentry">
            <option value="1" <?= selected($form['allow_reentry'], 1) ?>>Yes</option>
            <option value="0" <?= selected($form['allow_reentry'], 0) ?>>No</option>
          </select>
          <div class="help">Immediately re-arm when a cycle finishes.</div>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Trailing Take Profit</h2>
      <p class="help">Lock in profit by letting winners trail until they pull back.</p>

      <div class="grid grid-2">
        <div class="field">
          <label>Trailing Activation Profit (%)</label>
          <input type="number" min="0" step="0.01" name="trailing_activation_profit_pct" value="<?= htmlspecialchars((string) $form['trailing_activation_profit_pct']) ?>" required>
        </div>

        <div class="field">
          <label>Trailing Drawdown (%)</label>
          <input type="number" min="0" step="0.01" name="trailing_drawdown_pct" value="<?= htmlspecialchars((string) $form['trailing_drawdown_pct']) ?>" required>
        </div>
      </div>

      <hr class="sep">
      <div class="row">
        <a class="btn" href="<?= $BASE_URL ?>/dashboard.php">Cancel</a>
        <button class="btn primary" type="submit">Save Session</button>
      </div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>