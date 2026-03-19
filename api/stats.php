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
    ensure_trades_table();

    $pdo = db();
    $userId = (int) $user['id'];

    $botSummaryStmt = $pdo->prepare(<<<SQL
        SELECT
            COUNT(*) AS total_bots,
            COALESCE(SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END), 0) AS enabled_bots
        FROM bots
        WHERE user_id = ?
    SQL);
    $botSummaryStmt->execute([$userId]);
    $botSummary = $botSummaryStmt->fetch() ?: ['total_bots' => 0, 'enabled_bots' => 0];

    $runningStmt = $pdo->prepare(<<<SQL
        SELECT COUNT(*)
        FROM bot_state bs
        INNER JOIN bots b ON b.id = bs.bot_id
        WHERE b.user_id = ? AND bs.status = 'RUNNING'
    SQL);
    $runningStmt->execute([$userId]);
    $runningBots = (int) $runningStmt->fetchColumn();

    $tradeSummaryStmt = $pdo->prepare(<<<SQL
        SELECT COUNT(*) AS total_trades, MAX(t.created_at) AS last_trade_at
        FROM trades t
        INNER JOIN bots b ON b.id = t.bot_id
        WHERE b.user_id = ?
    SQL);
    $tradeSummaryStmt->execute([$userId]);
    $tradeSummary = $tradeSummaryStmt->fetch() ?: ['total_trades' => 0, 'last_trade_at' => null];

    json_success([
        'total_bots' => (int) ($botSummary['total_bots'] ?? 0),
        'enabled_bots' => (int) ($botSummary['enabled_bots'] ?? 0),
        'running_bots' => $runningBots,
        'total_trades' => (int) ($tradeSummary['total_trades'] ?? 0),
        'last_trade_at' => $tradeSummary['last_trade_at'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('stats api error: ' . $e->getMessage());
    json_error('Unable to load stats', 500);
}
