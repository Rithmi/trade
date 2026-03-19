<?php
declare(strict_types=1);

use JsonException;
use PDO;

require_once __DIR__ . '/../bootstrap.php';

json_begin_buffer();
require_login($BASE_URL);

try {
    $user = current_user();
    if (!$user) {
        json_error('Unauthorized', 401);
    }

    ensure_bots_table();
    ensure_bot_logs_table();

    $pdo = db();
    $stmt = $pdo->prepare(<<<SQL
        SELECT
            l.id,
            l.created_at,
            l.level,
            l.message,
            l.context_json,
            b.id AS bot_id,
            b.name AS bot_name
        FROM bot_logs l
        INNER JOIN bots b ON b.id = l.bot_id
        WHERE b.user_id = ?
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT 50
    SQL);
    $stmt->execute([(int) $user['id']]);

    $rows = array_map(static function (array $row): array {
        $context = $row['context_json'] ?? null;
        if ($context !== null && $context !== '') {
            try {
                $decoded = json_decode((string) $context, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $row['context'] = $decoded;
                }
            } catch (JsonException $decodeError) {
                $row['context_error'] = 'Invalid context payload.';
            }
        }
        unset($row['context_json']);
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    json_success(
        $rows,
        200,
        [
            'count' => count($rows),
            'user_id' => (int) $user['id'],
        ]
    );
} catch (Throwable $e) {
    error_log('logs api error: ' . $e->getMessage());
    json_error('Unable to load logs.', 500, ['code' => 'logs_fetch_failed']);
}
