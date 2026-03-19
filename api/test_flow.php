<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../bot_control.php';

use DateTimeImmutable;
use DateTimeZone;
use PDO;

json_begin_buffer();
require_login($BASE_URL);

try {
    $user = current_user();
    if (!$user) {
        json_error('Unauthorized', 401);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        json_success(get_test_status(), 200, ['source' => 'bot_status']);
        return;
    }

    if ($method !== 'POST') {
        json_error('Unsupported method', 405);
    }

    set_time_limit(180);

    $status = refresh_bot_status();
    if (!empty($status['running'])) {
        json_error(
            'Stop the bot engine before running the verification test.',
            409,
            ['code' => 'engine_running']
        );
    }

    $pdo = db();
    $userId = (int) $user['id'];

    $testStartedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $testStartMicro = microtime(true);
    $startedAtIso = $testStartedAt->format(DATE_ATOM);
    $startedAtDb = $testStartedAt->format('Y-m-d H:i:s');
    $baselineTradeId = fetch_max_trade_id($pdo, $userId);

    mark_test_started();

    $steps = [];
    $overallSuccess = true;
    $engineStarted = false;

    $startResult = $startMessage = '';

    try {
        $startResult = start_bot_engine();
        $engineStarted = true;
        $startMessage = $startResult['message'] ?? 'Engine start requested.';
        add_step($steps, 'start', true, $startMessage, ['pid' => $startResult['pid'] ?? null]);
        record_test_step('start', true, $startMessage);
    } catch (Throwable $e) {
        record_test_step('start', false, $e->getMessage());
        mark_test_completed(false, 'Engine failed to start.');
        json_error('Engine failed to start: ' . $e->getMessage(), 500, ['code' => 'engine_start_failed']);
    }

    [$runningOk, $runningMsg, $runningCtx] = wait_for_engine_running();
    add_step($steps, 'engine-online', $runningOk, $runningMsg, $runningCtx);
    record_test_step('engine-online', $runningOk, $runningMsg);
    if (!$runningOk) {
        $overallSuccess = false;
    }

    if ($overallSuccess) {
        [$priceOk, $priceMsg, $priceCtx] = wait_for_price_update($pdo, $userId, $startedAtDb);
        add_step($steps, 'price-stream', $priceOk, $priceMsg, $priceCtx);
        record_test_step('price-stream', $priceOk, $priceMsg);
        if (!$priceOk) {
            $overallSuccess = false;
        }
    }

    if ($overallSuccess) {
        [$tradeOk, $tradeMsg, $tradeCtx] = wait_for_trade_save($pdo, $userId, $baselineTradeId, $startedAtDb);
        add_step($steps, 'trade-save', $tradeOk, $tradeMsg, $tradeCtx);
        record_test_step('trade-save', $tradeOk, $tradeMsg);
        if (!$tradeOk) {
            $overallSuccess = false;
        }
    }

    $stopResult = null;
    if ($engineStarted) {
        try {
            $stopResult = stop_bot_engine();
            $stopMessage = $stopResult['message'] ?? 'Stop command sent.';
            add_step($steps, 'stop', true, $stopMessage, ['pid' => $stopResult['pid'] ?? null]);
            record_test_step('stop', true, $stopMessage);
        } catch (Throwable $e) {
            $overallSuccess = false;
            $stopMessage = 'Stop failed: ' . $e->getMessage();
            add_step($steps, 'stop', false, $stopMessage);
            record_test_step('stop', false, $stopMessage);
        }
    }

    $completedMessage = $overallSuccess ? 'Verification succeeded.' : 'Verification failed. Check logs and configuration.';
    mark_test_completed($overallSuccess, $completedMessage);

    $durationSeconds = round(microtime(true) - $testStartMicro, 2);

    json_success([
        'result' => $overallSuccess ? 'pass' : 'fail',
        'started_at' => $startedAtIso,
        'completed_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        'steps' => $steps,
        'duration_seconds' => $durationSeconds,
    ], 200, ['duration_seconds' => $durationSeconds]);
} catch (RuntimeException $e) {
    record_test_step('error', false, $e->getMessage());
    mark_test_completed(false, $e->getMessage());
    json_error($e->getMessage(), 500, ['code' => 'test_flow_runtime']);
} catch (Throwable $e) {
    record_test_step('error', false, $e->getMessage());
    mark_test_completed(false, 'Unexpected error during verification.');
    error_log('test_flow error: ' . $e->getMessage());
    json_error('Unable to run verification test.', 500, ['code' => 'test_flow_unknown']);
}

function add_step(array &$steps, string $stage, bool $success, string $message, array $context = []): void {
    $steps[] = [
        'stage' => $stage,
        'success' => $success,
        'message' => $message,
        'context' => $context,
        'timestamp' => date(DATE_ATOM),
    ];
}

function wait_for_engine_running(int $timeoutSeconds = 30, int $intervalSeconds = 2): array {
    $deadline = time() + $timeoutSeconds;
    while (time() <= $deadline) {
        $status = refresh_bot_status();
        if (!empty($status['running']) && !empty($status['pid'])) {
            $pid = (int) $status['pid'];
            return [true, sprintf('Bot engine running (PID %d).', $pid), ['pid' => $pid]];
        }
        sleep($intervalSeconds);
    }
    return [false, 'Bot engine did not report as running within the timeout.', []];
}

function wait_for_price_update(PDO $pdo, int $userId, string $since, int $timeoutSeconds = 45, int $intervalSeconds = 3): array {
    $deadline = time() + $timeoutSeconds;
    while (time() <= $deadline) {
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                b.name AS bot_name,
                b.symbol,
                bs.last_price,
                bs.updated_at
            FROM bot_state bs
            INNER JOIN bots b ON b.id = bs.bot_id
            WHERE b.user_id = :uid
              AND bs.last_price IS NOT NULL
              AND bs.last_price > 0
              AND bs.updated_at >= :since
            ORDER BY bs.updated_at DESC
            LIMIT 1
        SQL);
        $stmt->execute([
            'uid' => $userId,
            'since' => $since,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                true,
                sprintf('Live price %.4f captured for %s.', (float) $row['last_price'], $row['symbol']),
                $row,
            ];
        }
        sleep($intervalSeconds);
    }
    return [false, 'No live price updates detected within the allotted time.', []];
}

function wait_for_trade_save(PDO $pdo, int $userId, int $baselineTradeId, string $since, int $timeoutSeconds = 60, int $intervalSeconds = 3): array {
    $deadline = time() + $timeoutSeconds;
    while (time() <= $deadline) {
        $stmt = $pdo->prepare(<<<SQL
            SELECT
                t.id,
                t.created_at,
                t.symbol,
                t.qty,
                t.price,
                b.name AS bot_name
            FROM trades t
            INNER JOIN bots b ON b.id = t.bot_id
            WHERE b.user_id = :uid
              AND t.id > :baseline
              AND t.created_at >= :since
            ORDER BY t.id DESC
            LIMIT 1
        SQL);
        $stmt->execute([
            'uid' => $userId,
            'baseline' => $baselineTradeId,
            'since' => $since,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                true,
                sprintf('Trade #%d saved for %s @ %.4f.', (int) $row['id'], $row['symbol'], (float) $row['price']),
                $row,
            ];
        }
        sleep($intervalSeconds);
    }
    return [false, 'No new trades detected during the verification window.', []];
}

function fetch_max_trade_id(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare(<<<SQL
        SELECT COALESCE(MAX(t.id), 0) AS max_id
        FROM trades t
        INNER JOIN bots b ON b.id = t.bot_id
        WHERE b.user_id = ?
    SQL);
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}
