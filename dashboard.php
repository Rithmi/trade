<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login($BASE_URL);

require_once PUBLIC_ROOT . '/app/schema.php';
ensure_bots_table();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
$userEmail = $currentUser['email'] ?? 'Guest';

$pdo = db();

$botFlash = $_SESSION['bot_flash'] ?? null;
unset($_SESSION['bot_flash']);
$botCrudErrors = [];
$botList = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['bot_action'] ?? '';
  if (in_array($action, ['toggle', 'delete'], true)) {
    $targetBotId = (int) ($_POST['bot_id'] ?? 0);
    if ($targetBotId <= 0) {
      $botCrudErrors[] = 'Invalid bot selection.';
    } else {
      try {
        $stmt = $pdo->prepare('SELECT id, name, is_enabled FROM bots WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$targetBotId, $userId]);
        $botRow = $stmt->fetch();
        if (!$botRow) {
          $botCrudErrors[] = 'Bot not found or already removed.';
        } else {
          if ($action === 'delete') {
            $pdo->prepare('DELETE FROM bots WHERE id = ? AND user_id = ?')->execute([$targetBotId, $userId]);
            $_SESSION['bot_flash'] = sprintf('Deleted "%s".', $botRow['name']);
          } else {
            $targetState = ($_POST['target_state'] ?? '0') === '1' ? 1 : 0;
            $pdo->prepare('UPDATE bots SET is_enabled = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
              ->execute([$targetState, $targetBotId, $userId]);
            $_SESSION['bot_flash'] = $targetState
              ? sprintf('Enabled "%s".', $botRow['name'])
              : sprintf('Disabled "%s".', $botRow['name']);
          }

          if (empty($botCrudErrors)) {
            header("Location: {$BASE_URL}/dashboard.php#bot-list");
            exit;
          }
        }
      } catch (Throwable $e) {
        error_log('dashboard bot action error: ' . $e->getMessage());
        $botCrudErrors[] = 'Unable to update the bot. Please try again.';
      }
    }
  }
}

try {
  $stmt = $pdo->prepare('SELECT id, name, symbol, direction, timeframe, is_enabled, updated_at
    FROM bots
    WHERE user_id = ?
    ORDER BY is_enabled DESC, updated_at DESC, id DESC');
  $stmt->execute([$userId]);
  $botList = $stmt->fetchAll();
} catch (Throwable $e) {
  error_log('dashboard bot list error: ' . $e->getMessage());
  $botCrudErrors[] = 'Unable to load your bots right now.';
}
?>
<?php require __DIR__ . '/_layout_top.php'; ?>

<div id="dashboard-root">
  <section class="card" id="bot-list">
    <div class="row">
      <div>
        <h2>My Bot Sessions</h2>
        <p class="help">Create, enable/disable, or delete trading sessions.</p>
      </div>
      <div class="row">
        <a class="btn primary" href="<?= $BASE_URL ?>/bot_edit.php">+ New Bot</a>
      </div>
    </div>

    <?php if ($botFlash): ?>
      <div class="alert" style="margin-top:12px;"><?= htmlspecialchars($botFlash) ?></div>
    <?php endif; ?>

    <?php if (!empty($botCrudErrors)): ?>
      <div class="alert" style="background:rgba(239,68,68,.14);color:#fecaca;border-color:rgba(239,68,68,.35);margin-top:12px;">
        <?php foreach ($botCrudErrors as $err): ?>
          • <?= htmlspecialchars($err) ?><br>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($botList)): ?>
      <p class="help" style="margin-top:16px;">No bots yet. Click “New Bot” to create your first session.</p>
    <?php else: ?>
      <div class="table-scroll">
        <table class="table">
          <thead>
            <tr>
              <th>Bot</th>
              <th>Symbol</th>
              <th>Direction</th>
              <th>Timeframe</th>
              <th>Status</th>
              <th>Updated</th>
              <th style="width:240px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($botList as $bot): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($bot['name']) ?></strong>
                </td>
                <td><?= htmlspecialchars($bot['symbol']) ?></td>
                <td><?= htmlspecialchars($bot['direction']) ?></td>
                <td><?= htmlspecialchars($bot['timeframe']) ?></td>
                <td>
                  <?= $bot['is_enabled'] ? '<span class="badge on">ENABLED</span>' : '<span class="badge off">DISABLED</span>' ?>
                </td>
                <td><span class="help"><?= htmlspecialchars($bot['updated_at'] ?? '') ?></span></td>
                <td>
                  <div class="row" style="gap:8px; flex-wrap:wrap;">
                    <a class="btn" href="<?= $BASE_URL ?>/bot_edit.php?id=<?= (int) $bot['id'] ?>">Edit</a>
                    <form method="post">
                      <input type="hidden" name="bot_action" value="toggle">
                      <input type="hidden" name="bot_id" value="<?= (int) $bot['id'] ?>">
                      <input type="hidden" name="target_state" value="<?= $bot['is_enabled'] ? 0 : 1 ?>">
                      <button class="btn <?= $bot['is_enabled'] ? '' : 'good' ?>" type="submit">
                        <?= $bot['is_enabled'] ? 'Disable' : 'Enable' ?>
                      </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete <?= htmlspecialchars($bot['name']) ?>? This cannot be undone.');">
                      <input type="hidden" name="bot_action" value="delete">
                      <input type="hidden" name="bot_id" value="<?= (int) $bot['id'] ?>">
                      <button class="btn danger" type="submit">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="grid grid-4 stat-grid">
    <article class="card stat-card">
      <p class="stat-label">Total Bots</p>
      <div class="stat-value" id="stat-total-bots">--</div>
      <p class="stat-hint">Configured bots</p>
    </article>
    <article class="card stat-card">
      <p class="stat-label">Enabled</p>
      <div class="stat-value" id="stat-enabled-bots">--</div>
      <p class="stat-hint">Ready to run</p>
    </article>
    <article class="card stat-card">
      <p class="stat-label">Running</p>
      <div class="stat-value" id="stat-running-bots">--</div>
      <p class="stat-hint">Currently trading</p>
    </article>
    <article class="card stat-card">
      <p class="stat-label">Total Trades</p>
      <div class="stat-value" id="stat-total-trades">--</div>
      <p class="stat-hint" id="stat-last-trade">Last trade: --</p>
    </article>
  </section>

  <section class="card">
    <div class="row">
      <div>
        <h2>Bot Engine Control</h2>
        <p class="help">Start or stop the Python WebSocket engine that listens to Binance prices and places orders.</p>
      </div>
      <div class="engine-status">
        <span class="status-dot" id="engine-status-dot"></span>
        <div>
          <div class="engine-label">Status</div>
          <div class="engine-value" id="engine-status-text">Checking…</div>
          <div class="engine-meta" id="engine-last-message">Waiting for response…</div>
        </div>
      </div>
    </div>

    <div class="row engine-actions">
      <button id="btn-start-bot" class="btn good">Start Bot Engine</button>
      <button id="btn-stop-bot" class="btn danger" disabled>Stop Bot Engine</button>
      <button id="btn-refresh" class="btn">Refresh Data</button>
    </div>
    <p class="help">Flow: app.js → api/control.php → bot_control.php → PowerShell → ws_main.py → bot_status.json</p>
  </section>

  <section class="card" id="verification-card">
    <div class="row">
      <div>
        <h2>End-to-End Verification</h2>
        <p class="help">Run automated start → price → trade → stop checks for the bot engine.</p>
      </div>
      <div class="engine-status">
        <div>
          <div class="engine-label">Result</div>
          <div class="engine-value" id="test-status-text">Not run</div>
          <div class="engine-meta" id="test-status-time">Waiting for test…</div>
        </div>
      </div>
    </div>

    <div class="row engine-actions">
      <button id="btn-run-test" class="btn">Run End-to-End Test</button>
    </div>

    <div class="test-log" id="test-log">
      <p class="help">No verification history yet.</p>
    </div>
  </section>

  <div class="alert" id="dashboard-alert" role="alert" style="display:none;"></div>

  <section class="card">
    <div class="row">
      <div>
        <h2>Bot Status</h2>
        <p class="help">Live snapshot of each bot and its bot_state entry.</p>
      </div>
      <div class="help" id="bot-state-updated">Last update: --</div>
    </div>

    <div class="table-scroll">
      <table class="table">
        <thead>
          <tr>
            <th>Bot</th>
            <th>Symbol</th>
            <th>Direction</th>
            <th>Status</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Updated</th>
          </tr>
        </thead>
        <tbody id="bot-state-body">
          <tr>
            <td colspan="7" class="help center">Waiting for data…</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <div class="row">
      <div>
        <h2>Bot Position Status</h2>
        <p class="help">Track DCA progress, exposure, and unrealized PnL for every session.</p>
      </div>
      <div class="help" id="bot-position-updated">Last update: --</div>
    </div>

    <div class="position-grid" id="bot-position-container">
      <p class="help center full-width">Waiting for bot data…</p>
    </div>
  </section>

  <section class="grid grid-2">
    <article class="card">
      <div class="row">
        <h2>Recent Trades</h2>
        <button class="btn" id="btn-trades-refresh">Reload Trades</button>
      </div>
      <div class="table-scroll">
        <table class="table table-small">
          <thead>
            <tr>
              <th>Time</th>
              <th>Bot</th>
              <th>Symbol</th>
              <th>Side</th>
              <th>Qty</th>
              <th>Price</th>
              <th>Order ID</th>
            </tr>
          </thead>
          <tbody id="trades-body">
            <tr>
              <td colspan="7" class="help center">Waiting for data…</td>
            </tr>
          </tbody>
        </table>
      </div>
    </article>

    <article class="card">
      <div class="row">
        <h2>Latest Logs</h2>
        <button class="btn" id="btn-logs-refresh">Reload Logs</button>
      </div>
      <div class="log-list" id="logs-list">
        <p class="help">Waiting for data…</p>
      </div>
    </article>
  </section>
</div>

<script>
  window.BOT_BASE_URL = <?= json_encode($BASE_URL, JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= $BASE_URL ?>/assets/js/app.js"></script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>