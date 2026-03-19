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
  ensure_trades_table();

  $pdo = db();
  $stmt = $pdo->prepare(<<<SQL
    SELECT
      t.created_at,
      b.id AS bot_id,
      b.name AS bot_name,
      t.symbol,
      t.direction AS side,
      t.qty,
      t.price,
      t.exchange_order_id AS order_id
    FROM trades t
    INNER JOIN bots b ON b.id = t.bot_id
    WHERE b.user_id = ?
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 20
  SQL);
  $stmt->execute([(int) $user['id']]);

  json_success([
    'trades' => $stmt->fetchAll(),
  ]);
} catch (Throwable $e) {
  error_log('trades api error: ' . $e->getMessage());
  json_error('Unable to load trades', 500);
}
