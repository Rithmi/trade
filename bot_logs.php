<?php
// Frontend-first bot logs page (mock). We'll connect DB later.
$pageTitle = "Bot Logs";
$activeNav = "dashboard";

// TEMP: pretend user is logged in (replace later with real auth_user())
$userEmail = "you@example.com";

$botId = isset($_GET['id']) ? (int)$_GET['id'] : 1;

// Mock bot info (replace with DB later)
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

// Mock logs (replace with DB later)
$logs = [
  ['ts' => '2026-02-24 12:18:12', 'level' => 'INFO',  'msg' => 'WebSocket connected: BTCUSDT mark price stream.'],
  ['ts' => '2026-02-24 12:18:30', 'level' => 'INFO',  'msg' => 'Signal armed (PSAR) on 5m. Waiting for bounce confirmation.'],
  ['ts' => '2026-02-24 12:19:02', 'level' => 'INFO',  'msg' => 'Local low updated: 50780.25. Bounce target: +0.50%.'],
  ['ts' => '2026-02-24 12:20:11', 'level' => 'INFO',  'msg' => 'Bounce detected (+0.52%). Placing DCA order #2 (x2).'],
  ['ts' => '2026-02-24 12:20:13', 'level' => 'INFO',  'msg' => 'Order filled. Avg entry recalculated. Safety orders used: 2/8.'],
  ['ts' => '2026-02-24 12:21:05', 'level' => 'WARN',  'msg' => 'Price volatility spike detected. Slippage may increase.'],
  ['ts' => '2026-02-24 12:22:44', 'level' => 'INFO',  'msg' => 'Trailing activated at +0.30%. Tracking peak for drawdown exit.'],
];

function badge_direction(string $dir): string {
  $d = strtoupper($dir);
  $cls = ($d === 'LONG') ? 'long' : 'short';
  return "<span class=\"badge {$cls}\">{$d}</span>";
}
function badge_enabled(int $on): string {
  return $on ? "<span class=\"badge on\">ENABLED</span>" : "<span class=\"badge off\">DISABLED</span>";
}
function badge_level(string $lvl): string {
  $lvl = strtoupper($lvl);
  if ($lvl === 'ERROR') return '<span class="badge" style="border-color:rgba(239,68,68,.35);color:#fecaca;">ERROR</span>';
  if ($lvl === 'WARN')  return '<span class="badge" style="border-color:rgba(245,158,11,.35);color:#fde68a;">WARN</span>';
  return '<span class="badge" style="border-color:rgba(34,197,94,.35);color:#a7f3d0;">INFO</span>';
}
?>
<?php require __DIR__ . '/_layout_top.php'; ?>

<div class="grid">
  <!-- Header -->
  <div class="card">
    <div class="row">
      <div>
        <h2 style="margin:0;">Logs: <?= htmlspecialchars($bot['name']) ?></h2>
        <p class="help">
          <?= htmlspecialchars($bot['symbol']) ?> • <?= badge_direction($bot['direction']) ?>
          • <?= (int)$bot['leverage'] ?>x <?= htmlspecialchars($bot['margin_mode']) ?>
        </p>
      </div>
      <div class="row">
        <a class="btn" href="/dashboard.php">← Back</a>
        <a class="btn primary" href="/bot_edit.php?id=<?= (int)$bot['id'] ?>">Edit</a>
        <a class="btn" href="/trades.php?id=<?= (int)$bot['id'] ?>">Trades</a>
      </div>
    </div>

    <hr class="sep">

    <div class="row">
      <div class="row">
        <span class="badge"><?= htmlspecialchars($bot['status']) ?></span>
        <?= badge_enabled((int)$bot['is_enabled']) ?>
      </div>

      <!-- Frontend-only controls -->
      <div class="row">
        <select>
          <option>All levels</option>
          <option>INFO</option>
          <option>WARN</option>
          <option>ERROR</option>
        </select>
        <input type="search" placeholder="Search logs…" />
        <button class="btn" type="button" onclick="alert('Frontend demo: We will implement refresh/polling later.')">Refresh</button>
      </div>
    </div>
  </div>

  <!-- Log list -->
  <div class="card">
    <div class="row">
      <div>
        <h2 style="margin:0;">Event Stream</h2>
        <p class="help">Latest messages first (mock). We’ll load from MySQL and add pagination.</p>
      </div>
      <div class="row">
        <span class="badge">Auto-scroll</span>
        <button class="btn" type="button" onclick="alert('Frontend demo: We will implement export later.')">Export</button>
      </div>
    </div>

    <hr class="sep">

    <table class="table">
      <thead>
        <tr>
          <th style="width:190px;">Time</th>
          <th style="width:110px;">Level</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($logs) as $l): ?>
          <tr>
            <td><span class="help"><?= htmlspecialchars($l['ts']) ?></span></td>
            <td><?= badge_level($l['level']) ?></td>
            <td><?= htmlspecialchars($l['msg']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <hr class="sep">

    <div class="help">
      Next step: wire this to the <b>bot_logs</b> table and show real-time updates (polling or SSE).
    </div>
  </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>