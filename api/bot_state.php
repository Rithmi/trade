<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_login($BASE_URL);
header('Content-Type: application/json');

try {
  $user = current_user();
  if (!$user) {
    json_error('Unauthorized', 401);
  }

  ensure_bots_table();
  ensure_bot_state_table();

  $pdo = db();
  $stmt = $pdo->prepare(<<<SQL
    SELECT
      b.id AS bot_id,
      b.name,
      b.symbol,
      b.direction,
      b.is_enabled,
      b.base_order_usdt,
      b.max_safety_orders,
      b.dca_multiplier,
      b.next_dca_trigger_drop_pct,
      b.trailing_activation_profit_pct,
      b.trailing_drawdown_pct,
      COALESCE(bs.status, 'IDLE') AS status,
      COALESCE(bs.position_qty, 0) AS position_qty,
      COALESCE(bs.avg_entry_price, 0) AS avg_entry_price,
      COALESCE(bs.safety_order_count, 0) AS safety_order_count,
      COALESCE(bs.last_price, 0) AS last_price,
      bs.local_low,
      bs.local_high,
      bs.last_message,
      COALESCE(bs.updated_at, b.updated_at) AS updated_at
    FROM bots b
    LEFT JOIN bot_state bs ON bs.bot_id = b.id
    WHERE b.user_id = ?
    ORDER BY b.updated_at DESC, b.id DESC
  SQL);
  $stmt->execute([(int) $user['id']]);

  json_success([
    'bots' => $stmt->fetchAll(),
  ]);
} catch (Throwable $e) {
  error_log('bot_state api error: ' . $e->getMessage());
  json_error('Unable to load bot state', 500);
}
