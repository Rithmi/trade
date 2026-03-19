<?php
declare(strict_types=1);

if (!defined('PROJECT_ROOT')) {
  require_once __DIR__ . '/bootstrap.php';
}

const BOT_STATUS_TEMPLATE = [
  'running' => false,
  'pid' => null,
  'last_started_at' => null,
  'last_stopped_at' => null,
  'message' => 'Bot engine not started',
  'last_test_started_at' => null,
  'last_test_completed_at' => null,
  'last_test_result' => null,
  'last_test_log' => [],
];

function bot_python_root(): string {
  return PUBLIC_ROOT . '/python-bot';
}

function bot_status_path(): string {
  return bot_python_root() . '/bot_status.json';
}

function bot_pid_path(): string {
  return bot_python_root() . '/bot.pid';
}

function bot_start_script(): string {
  return bot_python_root() . '/start_bot.bat';
}

function bot_stop_script(): string {
  return bot_python_root() . '/stop_bot.bat';
}

function windows_quote(string $value): string {
  return '"' . str_replace('"', '""', $value) . '"';
}

function run_batch_script(string $scriptPath, array $arguments = []): string {
  ensure_shell_exec_available();

  if (!is_file($scriptPath)) {
    throw new RuntimeException('Unable to locate script: ' . $scriptPath);
  }

  $segments = array_merge([$scriptPath], $arguments);
  $quoted = array_map('windows_quote', $segments);
  $command = 'cmd /C ' . implode(' ', $quoted) . ' 2>&1';

  $output = shell_exec($command);
  if ($output === null) {
    throw new RuntimeException('Command failed to run: ' . $scriptPath);
  }

  return trim((string) $output);
}

function ensure_shell_exec_available(): void {
  if (!function_exists('shell_exec')) {
    throw new RuntimeException('shell_exec is disabled on this PHP installation. Enable it inside php.ini to manage the bot engine.');
  }
}

function load_bot_status(): array {
  $path = bot_status_path();

  if (!is_file($path)) {
    return save_bot_status(BOT_STATUS_TEMPLATE);
  }

  $contents = file_get_contents($path) ?: '';
  $decoded = json_decode($contents, true);

  if (!is_array($decoded)) {
    return save_bot_status(BOT_STATUS_TEMPLATE);
  }

  return array_merge(BOT_STATUS_TEMPLATE, array_intersect_key($decoded, BOT_STATUS_TEMPLATE));
}

function save_bot_status(array $status): array {
  $normalized = array_merge(BOT_STATUS_TEMPLATE, array_intersect_key($status, BOT_STATUS_TEMPLATE));
  file_put_contents(bot_status_path(), json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  return $normalized;
}

function read_pid_file(): ?int {
  $path = bot_pid_path();
  if (!is_file($path)) {
    return null;
  }

  $pid = (int) trim((string) file_get_contents($path));
  return $pid > 0 ? $pid : null;
}

function clear_pid_file(): void {
  $path = bot_pid_path();
  if (is_file($path)) {
    @unlink($path);
  }
}

function extract_pid_from_output(?string $output): ?int {
  if ($output === null) {
    return null;
  }

  if (preg_match('/(\d+)/', $output, $matches)) {
    $pid = (int) $matches[1];
    return $pid > 0 ? $pid : null;
  }

  return null;
}

function is_process_running(?int $pid): bool {
  ensure_shell_exec_available();

  if (!$pid || $pid <= 0) {
    return false;
  }

  $command = sprintf('tasklist /FI "PID eq %d" /FO CSV /NH', $pid);
  $output = shell_exec($command);

  if ($output === null) {
    return false;
  }

  return stripos($output, (string) $pid) !== false;
}

function wait_for_process_state(int $pid, bool $shouldBeRunning, int $attempts = 5, int $delayMs = 300): bool {
  if ($pid <= 0) {
    return $shouldBeRunning ? false : true;
  }

  $delayMs = max($delayMs, 50);

  for ($i = 0; $i < $attempts; $i++) {
    $running = is_process_running($pid);
    if ($running === $shouldBeRunning) {
      return true;
    }
    usleep($delayMs * 1000);
  }

  return is_process_running($pid) === $shouldBeRunning;
}

function refresh_bot_status(): array {
  $status = load_bot_status();
  $pid = isset($status['pid']) ? (int) $status['pid'] : null;

  if ($pid && !is_process_running($pid)) {
    $status['running'] = false;
    $status['pid'] = null;
    $status['last_stopped_at'] = date(DATE_ATOM);
    $status['message'] = 'Bot engine is not running.';
    clear_pid_file();
    return save_bot_status($status);
  }

  if ($pid) {
    $status['running'] = true;
    $status['message'] = sprintf('Bot engine running (PID %d).', $pid);
    return save_bot_status($status);
  }

  $status['running'] = false;
  $status['message'] = $status['message'] ?: 'Bot engine not started';
  return save_bot_status($status);
}

function get_bot_status(): array {
  ensure_shell_exec_available();
  return refresh_bot_status();
}

function start_bot_engine(): array {
  ensure_shell_exec_available();

  $status = refresh_bot_status();
  if (!empty($status['running']) && !empty($status['pid'])) {
    $status['message'] = sprintf('Bot engine already running (PID %d).', (int) $status['pid']);
    return $status;
  }

  $startScript = bot_start_script();

  $output = run_batch_script($startScript);
  if (stripos($output, 'ERROR:') === 0) {
    throw new RuntimeException('Start script reported an error: ' . $output);
  }

  $pid = extract_pid_from_output($output) ?? read_pid_file();

  if (!$pid) {
    throw new RuntimeException('Start script did not return a PID. Output: ' . $output);
  }

  if (!wait_for_process_state($pid, true, 10)) {
    throw new RuntimeException(sprintf('Bot engine failed to stay running (PID %d). Check Python logs.', $pid));
  }

  $status['running'] = true;
  $status['pid'] = $pid;
  $status['last_started_at'] = date(DATE_ATOM);
  $status['message'] = sprintf('Bot engine started (PID %d).', $pid);
  save_bot_status($status);

  return refresh_bot_status();
}

function stop_bot_engine(): array {
  ensure_shell_exec_available();

  $status = refresh_bot_status();
  $pid = isset($status['pid']) ? (int) $status['pid'] : 0;

  if ($pid <= 0) {
    $status['running'] = false;
    $status['message'] = 'Bot engine already stopped.';
    $status['pid'] = null;
    $status['last_stopped_at'] = date(DATE_ATOM);
    clear_pid_file();
    return save_bot_status($status);
  }

  $stopScript = bot_stop_script();

  $output = run_batch_script($stopScript, [(string) $pid]);
  $normalized = strtoupper($output);

  if (str_contains($normalized, 'NOT_FOUND')) {
    $status['message'] = 'Process not found. Status synced to stopped.';
  } elseif (!str_contains($normalized, 'STOPPED')) {
    throw new RuntimeException('Stop script failed: ' . $output);
  } else {
    if (!wait_for_process_state($pid, false, 10)) {
      throw new RuntimeException('Bot process is still running; manual intervention required.');
    }
    $status['message'] = 'Bot engine stopped successfully.';
  }

  $status['running'] = false;
  $status['pid'] = null;
  $status['last_stopped_at'] = date(DATE_ATOM);
  clear_pid_file();
  save_bot_status($status);

  return refresh_bot_status();
}

function mark_test_started(): array {
  $status = load_bot_status();
  $status['last_test_started_at'] = date(DATE_ATOM);
  $status['last_test_completed_at'] = null;
  $status['last_test_result'] = 'running';
  $status['last_test_log'] = [];
  $status['message'] = 'Running verification test…';
  return save_bot_status($status);
}

function record_test_step(string $stage, bool $success, string $message): array {
  $status = load_bot_status();
  $log = $status['last_test_log'] ?? [];
  if (!is_array($log)) {
    $log = [];
  }
  $log[] = [
    'stage' => $stage,
    'success' => $success,
    'message' => $message,
    'timestamp' => date(DATE_ATOM),
  ];
  $status['last_test_log'] = array_slice($log, -20);
  $status['last_test_result'] = $success ? ($status['last_test_result'] === 'fail' ? 'fail' : 'running') : 'fail';
  if (!$success) {
    $status['message'] = $message;
  }
  return save_bot_status($status);
}

function mark_test_completed(bool $success, string $message = ''): array {
  $status = load_bot_status();
  $status['last_test_completed_at'] = date(DATE_ATOM);
  $status['last_test_result'] = $success ? 'pass' : 'fail';
  if ($message) {
    $status['message'] = $message;
  }
  return save_bot_status($status);
}

function get_test_status(): array {
  $status = load_bot_status();
  return [
    'running' => (bool) ($status['running'] ?? false),
    'pid' => $status['pid'] ?? null,
    'last_test_started_at' => $status['last_test_started_at'] ?? null,
    'last_test_completed_at' => $status['last_test_completed_at'] ?? null,
    'last_test_result' => $status['last_test_result'] ?? null,
    'last_test_log' => $status['last_test_log'] ?? [],
    'message' => $status['message'] ?? null,
  ];
}
