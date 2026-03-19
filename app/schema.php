<?php
require_once __DIR__ . '/db.php';

function ensure_users_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `name` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  db()->exec($sql);
  $ensured = true;
}

function ensure_password_resets_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  ensure_users_table();

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_token_hash` (`token_hash`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  db()->exec($sql);
  $ensured = true;
}

function ensure_api_keys_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  ensure_users_table();

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `exchange` VARCHAR(40) NOT NULL DEFAULT 'BINANCE_FUTURES',
  `mode` ENUM('TESTNET','LIVE') NOT NULL DEFAULT 'TESTNET',
  `api_key_enc` TEXT NOT NULL,
  `api_secret_enc` TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_keys_user_exchange_mode` (`user_id`,`exchange`,`mode`),
  CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  db()->exec($sql);
  $ensured = true;
}

function ensure_bots_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  ensure_users_table();

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `bots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `symbol` VARCHAR(20) NOT NULL,
  `direction` ENUM('LONG','SHORT') NOT NULL DEFAULT 'LONG',
  `leverage` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `margin_mode` ENUM('ISOLATED','CROSS') NOT NULL DEFAULT 'ISOLATED',
  `timeframe` VARCHAR(10) NOT NULL DEFAULT '5m',
  `signal_type` VARCHAR(50) NOT NULL DEFAULT 'PSAR',
  `base_order_usdt` DECIMAL(18,8) NOT NULL DEFAULT 0.01,
  `dca_multiplier` DECIMAL(10,4) NOT NULL DEFAULT 1.00,
  `max_safety_orders` INT UNSIGNED NOT NULL DEFAULT 0,
  `next_dca_trigger_drop_pct` DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  `bounce_from_local_low_pct` DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  `trailing_activation_profit_pct` DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  `trailing_drawdown_pct` DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  `allow_reentry` TINYINT(1) NOT NULL DEFAULT 1,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bots_user` (`user_id`),
  KEY `idx_bots_enabled` (`is_enabled`),
  CONSTRAINT `fk_bots_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  db()->exec($sql);
  $ensured = true;
}

function ensure_bot_state_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  ensure_bots_table();

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `bot_state` (
  `bot_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'IDLE',
  `avg_entry_price` DECIMAL(18,8) DEFAULT NULL,
  `position_qty` DECIMAL(18,8) DEFAULT NULL,
  `safety_order_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_price` DECIMAL(18,8) DEFAULT NULL,
  `local_low` DECIMAL(18,8) DEFAULT NULL,
  `local_high` DECIMAL(18,8) DEFAULT NULL,
  `last_message` VARCHAR(255) DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bot_id`),
  CONSTRAINT `fk_bot_state_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  db()->exec($sql);
  $ensured = true;
}

function ensure_bot_logs_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  ensure_bots_table();

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `bot_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` BIGINT UNSIGNED NOT NULL,
  `level` VARCHAR(20) NOT NULL DEFAULT 'INFO',
  `message` TEXT NOT NULL,
  `context_json` LONGTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot_logs_bot` (`bot_id`),
  KEY `idx_bot_logs_created_at` (`created_at`),
  CONSTRAINT `fk_bot_logs_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  $pdo = db();
  $pdo->exec($sql);

  try {
    $pdo->exec("ALTER TABLE `bot_logs` ADD COLUMN `context_json` LONGTEXT DEFAULT NULL AFTER `message`");
  } catch (Throwable $e) {
    $msg = $e->getMessage();
    if ($msg === null || stripos($msg, 'Duplicate column name') === false) {
      error_log('bot_logs column ensure failed: ' . $msg);
    }
  }
  $ensured = true;
}

function ensure_trades_table(): void {
  static $ensured = false;
  if ($ensured) {
    return;
  }

  ensure_bots_table();

  $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `trades` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` BIGINT UNSIGNED NOT NULL,
  `symbol` VARCHAR(20) NOT NULL,
  `direction` ENUM('LONG','SHORT') NOT NULL DEFAULT 'LONG',
  `action` VARCHAR(20) NOT NULL DEFAULT 'OPEN',
  `qty` DECIMAL(18,8) NOT NULL,
  `price` DECIMAL(18,8) NOT NULL,
  `order_id` VARCHAR(100) NOT NULL,
  `exchange_order_id` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'FILLED',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_trades_bot` (`bot_id`),
  KEY `idx_trades_created_at` (`created_at`),
  CONSTRAINT `fk_trades_bot` FOREIGN KEY (`bot_id`) REFERENCES `bots`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

  db()->exec($sql);
  $ensured = true;
}

