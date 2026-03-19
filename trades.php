<?php
require __DIR__ . '/bootstrap.php';

require_login($BASE_URL);

// Frontend-first trades page (mock). We'll connect DB later.
$pageTitle = "Trades";
$activeNav = "dashboard";

$currentUser = current_user();
$userEmail = $currentUser['email'] ?? 'you@example.com';

$botId = isset($_GET['id']) ? (int)$_GET['id'] : 1;

// Mock bot info (replace later with DB)
$bot = [
  'id' => $botId,
  'name' => 'BTC Long Nightingale',
  'symbol' => 'BTCUSDT',
  'direction' => 'LONG',
  'leverage' => 15,
  'margin_mode' => 'ISOLATED',
  'is_enabled' => 1,
  'status' => 'IN_TRADE'
];

// Mock trades list (replace later with DB)
$trades = [
  ['ts'=>'2026-02-24 12:10:02','action'=>'ENTRY','qty'=>0.0012,'price'=>50980.50,'pnl'=>null,'order_id'=>'1234567890'],
  ['ts'=>'2026-02-24 12:20:13','action'=>'DCA','qty'=>0.0024,'price'=>50820.00,'pnl'=>null,'order_id'=>'1234567891'],
  ['ts'=>'2026-02-24 12:33:40','action'=>'DCA','qty'=>0.0048,'price'=>50690.25,'pnl'=>null,'order_id'=>'1234567892'],
  // Example exit (comment in/out to preview closed trade)
  ['ts'=>'2026-02-24 13:05:12','action'=>'EXIT','qty'=>0.0084,'price'=>51240.10,'pnl'=>18.62,'order_id'=>'1234567899'],
];

function badge_direction(string $dir): string {
  $d = strtoupper($dir);
  $cls = ($d === 'LONG') ? 'long' : 'short';
  return "<span class=\"badge {$cls}\">{$d}</span>";
}
function badge_enabled(int $on): string {
  return $on ? "<span class=\"badge on\">ENABLED</span>" : "<span class=\"badge off\">DISABLED</span>";
}
function badge_action(string $a): string {
  $a = strtoupper($a);
  if ($a === 'EXIT')  return '<span class="badge" style="border-color:rgba(34,197,94,.35);color:#a7f3d0;">EXIT</span>';
  if ($a === 'DCA')   return '<span class="badge" style="border-color:rgba(59,130,246,.35);color:#c7d2fe;">DCA</span>';
  return '<span class="badge" style="border-color:rgba(156,163,175,.35);color:#e5e7eb;">ENTRY</span>';
}
function fmt_qty($q): string { return rtrim(rtrim(number_format((float)$q, 8, '.', ''), '0'), '.'); }
function fmt_price($p): string { return number_format((float)$p, 2); }
function fmt_pnl($pnl): string {
  if ($pnl === null) return '—';
  $pnl = (float)$pnl;
  $sign = $pnl >= 0 ? '+' : '';
  return $sign . number_format($pnl, 2) . ' USDT';
}

// Mock summary calcs (later from DB/state)
$totalClosedPnl = 0.0;
$closedCount = 0;
foreach ($trades as $t) {
  if ($t['action'] === 'EXIT' && $t['pnl'] !== null) {
    $totalClosedPnl += (float)$t['pnl'];
    $closedCount++;
  }
}
$todayPnl = $totalClosedPnl; // mock
$openPosition = ($bot['status'] === 'IN_TRADE');
?>
<?php require __DIR__ . '/_layout_top.php'; ?>

<div class="grid">
  <!-- Header -->
  <div class="card">
    <div class="row">
      <div>
        <h2 style="margin:0;">Trades: <?= htmlspecialchars($bot['name']) ?></h2>
        <p class="help">
          <?= htmlspecialchars($bot['symbol']) ?> • <?= badge_direction($bot['direction']) ?>
          • <?= (int)$bot['leverage'] ?>x <?= htmlspecialchars($bot['margin_mode']) ?>
        </p>
      </div>
      <div class="row">
        <a class="btn" href="<?= $BASE_URL ?>/dashboard.php">← Back</a>
        <a class="btn" href="<?= $BASE_URL ?>/bot_logs.php?id=<?= (int)$bot['id'] ?>">Logs</a>
        <a class="btn primary" href="<?= $BASE_URL ?>/bot_edit.php?id=<?= (int)$bot['id'] ?>">Edit</a>
      </div>
    </div>

    <hr class="sep">

    <div class="row">
      <div class="row">
        <span class="badge"><?= htmlspecialchars($bot['status']) ?></span>
        <?= $openPosition ? '<span class="badge on">OPEN</span>' : '<span class="badge off">FLAT</span>' ?>
        <?= badge_enabled((int)$bot['is_enabled']) ?>
      </div>

      <!-- Frontend-only filters -->
      <div class="row">
        <select>
          <option>All actions</option>
          <option>ENTRY</option>
          <option>DCA</option>
          <option>EXIT</option>
        </select>
        <select>
          <option>Last 24h</option>
          <option>Today</option>
          <option>7 days</option>
          <option>30 days</option>
          <option>Custom…</option>
        </select>
        <button class="btn" type="button" onclick="alert('Frontend demo: export will be added after DB wiring.')">Export CSV</button>
      </div>
    </div>
  </div>

  <!-- Summary cards -->
  <div class="grid grid-3">
    <div class="card">
      <h2>Today PnL</h2>
      <p class="help">Closed PnL today (mock)</p>
      <div class="row">
        <div style="font-size:30px;font-weight:800;"><?= fmt_pnl($todayPnl) ?></div>
        <span class="badge">Closed</span>
      </div>
    </div>

    <div class="card">
      <h2>Total Closed PnL</h2>
      <p class="help">All-time (mock)</p>
      <div class="row">
        <div style="font-size:30px;font-weight:800;"><?= fmt_pnl($totalClosedPnl) ?></div>
        <span class="badge"><?= (int)$closedCount ?> exits</span>
      </div>
    </div>

    <div class="card">
      <h2>Position</h2>
      <p class="help">Current state (mock)</p>
      <div class="row">
        <div style="font-size:30px;font-weight:800;"><?= $openPosition ? 'OPEN' : 'FLAT' ?></div>
        <span class="badge"><?= htmlspecialchars($bot['status']) ?></span>
      </div>
    </div>
  </div>

  <!-- Trades table -->
  <div class="card">
    <div class="row">
      <div>
        <h2 style="margin:0;">Trade History</h2>
        <p class="help">ENTRY / DCA / EXIT rows. Later we’ll group these into “cycles” (one full trade).</p>
      </div>
      <div class="row">
        <input type="search" placeholder="Search order id…" />
        <button class="btn" type="button" onclick="alert('Frontend demo: refresh/paging later.')">Refresh</button>
      </div>
    </div>

    <hr class="sep">

    <table class="table">
      <thead>
        <tr>
          <th style="width:190px;">Time</th>
          <th style="width:110px;">Action</th>
          <th>Qty</th>
          <th>Price</th>
          <th>PnL (USDT)</th>
          <th>Order ID</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($trades as $t): ?>
          <tr>
            <td><span class="help"><?= htmlspecialchars($t['ts']) ?></span></td>
            <td><?= badge_action($t['action']) ?></td>
            <td><?= htmlspecialchars(fmt_qty($t['qty'])) ?></td>
            <td><?= htmlspecialchars(fmt_price($t['price'])) ?></td>
            <td><?= htmlspecialchars(fmt_pnl($t['pnl'])) ?></td>
            <td><span class="help"><?= htmlspecialchars($t['order_id']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <hr class="sep">

    <div class="help">
      Next step: wire to the <b>trades</b> table + compute real PnL and position summary from <b>bot_state</b>.
    </div>
  </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>