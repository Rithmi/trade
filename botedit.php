<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_login($BASE_URL);

// Preserve query parameters so existing bookmarks keep working.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = $BASE_URL . '/bot_edit.php' . ($query ? ('?' . $query) : '');

header("Location: {$target}");
exit;
