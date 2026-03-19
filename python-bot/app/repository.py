"""Data-access helpers for the Python bot engine."""

from __future__ import annotations

import json
import logging
from datetime import datetime
import threading
from typing import Any, Dict, List, Optional

from pymysql import MySQLError
from pymysql.cursors import DictCursor

from db import get_connection

logger = logging.getLogger(__name__)


_TABLE_LOCK = threading.Lock()
_TABLES_ENSURED = False

CREATE_BOT_STATE = """
    CREATE TABLE IF NOT EXISTS bot_state (
        bot_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'IDLE',
        avg_entry_price DECIMAL(18,8) DEFAULT NULL,
        position_qty DECIMAL(18,8) DEFAULT NULL,
        safety_order_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_price DECIMAL(18,8) DEFAULT NULL,
        local_low DECIMAL(18,8) DEFAULT NULL,
        local_high DECIMAL(18,8) DEFAULT NULL,
        last_message VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (bot_id),
        CONSTRAINT fk_bot_state_bot FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""

CREATE_BOT_LOGS = """
    CREATE TABLE IF NOT EXISTS bot_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bot_id BIGINT UNSIGNED NOT NULL,
        level VARCHAR(20) NOT NULL DEFAULT 'INFO',
        message TEXT NOT NULL,
        context_json LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_bot_logs_bot (bot_id),
        KEY idx_bot_logs_created_at (created_at),
        CONSTRAINT fk_bot_logs_bot FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""

CREATE_TRADES = """
    CREATE TABLE IF NOT EXISTS trades (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bot_id BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NOT NULL,
        direction ENUM('LONG','SHORT') NOT NULL DEFAULT 'LONG',
        action VARCHAR(20) NOT NULL DEFAULT 'OPEN',
        qty DECIMAL(18,8) NOT NULL,
        price DECIMAL(18,8) NOT NULL,
        order_id VARCHAR(100) NOT NULL,
        exchange_order_id VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'FILLED',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_trades_bot (bot_id),
        KEY idx_trades_created_at (created_at),
        CONSTRAINT fk_trades_bot FOREIGN KEY (bot_id) REFERENCES bots(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""


def _ensure_support_tables() -> None:
    global _TABLES_ENSURED
    if _TABLES_ENSURED:
        return

    with _TABLE_LOCK:
        if _TABLES_ENSURED:
            return
        statements = [CREATE_BOT_STATE, CREATE_BOT_LOGS, CREATE_TRADES]
        try:
            with get_connection() as connection:
                with connection.cursor() as cursor:
                    for statement in statements:
                        cursor.execute(statement)
                connection.commit()
        except MySQLError:
            logger.exception("Failed to ensure support tables")
            raise
        _TABLES_ENSURED = True


class Repository:
    """Wrap every DB interaction needed by the bot engine."""

    def __init__(self) -> None:
        _ensure_support_tables()

    def get_enabled_bots(self) -> List[Dict[str, Any]]:
        sql = """
            SELECT
                b.id,
                b.user_id,
                b.name,
                b.symbol,
                b.direction,
                b.leverage,
                b.margin_mode,
                b.timeframe,
                b.signal_type,
                b.base_order_usdt,
                b.dca_multiplier,
                b.max_safety_orders,
                b.next_dca_trigger_drop_pct,
                b.bounce_from_local_low_pct,
                b.trailing_activation_profit_pct,
                b.trailing_drawdown_pct,
                b.allow_reentry,
                b.is_enabled,
                COALESCE(bs.status, 'IDLE') AS state_status,
                bs.avg_entry_price      AS state_avg_entry_price,
                bs.position_qty         AS state_position_qty,
                bs.safety_order_count   AS state_safety_order_count,
                bs.last_price           AS state_last_price,
                bs.local_low            AS state_local_low,
                bs.local_high           AS state_local_high,
                bs.last_message         AS state_last_message,
                bs.updated_at           AS state_updated_at
            FROM bots b
            LEFT JOIN bot_state bs ON bs.bot_id = b.id
            WHERE b.is_enabled = 1
            ORDER BY b.id ASC
        """

        return self._fetch_all(sql)

    def upsert_bot_state(self, bot_id: int, data: Dict[str, Any]) -> None:
        sql = """
            INSERT INTO bot_state (
                bot_id,
                status,
                avg_entry_price,
                position_qty,
                safety_order_count,
                last_price,
                local_low,
                local_high,
                last_message,
                updated_at
            )
            VALUES (
                %(bot_id)s,
                %(status)s,
                %(avg_entry_price)s,
                %(position_qty)s,
                %(safety_order_count)s,
                %(last_price)s,
                %(local_low)s,
                %(local_high)s,
                %(last_message)s,
                %(updated_at)s
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                avg_entry_price = VALUES(avg_entry_price),
                position_qty = VALUES(position_qty),
                safety_order_count = VALUES(safety_order_count),
                last_price = VALUES(last_price),
                local_low = VALUES(local_low),
                local_high = VALUES(local_high),
                last_message = VALUES(last_message),
                updated_at = VALUES(updated_at)
        """

        payload = {
            "bot_id": bot_id,
            "status": data.get("status") or "IDLE",
            "avg_entry_price": data.get("avg_entry_price"),
            "position_qty": data.get("position_qty"),
            "safety_order_count": data.get("safety_order_count", 0),
            "last_price": data.get("last_price"),
            "local_low": data.get("local_low"),
            "local_high": data.get("local_high"),
            "last_message": data.get("last_message"),
            "updated_at": datetime.utcnow(),
        }

        logger.debug(
            "bot_state upsert prepared bot_id=%s status=%s qty=%s price=%s",
            bot_id,
            payload["status"],
            payload["position_qty"],
            payload["last_price"],
        )
        self._execute(sql, payload)

    def insert_trade(
        self,
        bot_id: int,
        symbol: str,
        side: str,
        action: str,
        qty: float,
        price: float,
        order_id: str,
        exchange_order_id: Optional[str] = None,
        status: str = "FILLED",
    ) -> None:
        sql = """
            INSERT INTO trades (
                bot_id,
                symbol,
                direction,
                action,
                qty,
                price,
                order_id,
                exchange_order_id,
                status
            )
            VALUES (
                %(bot_id)s,
                %(symbol)s,
                %(direction)s,
                %(action)s,
                %(qty)s,
                %(price)s,
                %(order_id)s,
                %(exchange_order_id)s,
                %(status)s
            )
        """

        params = {
            "bot_id": bot_id,
            "symbol": symbol,
            "direction": side,
            "action": action,
            "qty": qty,
            "price": price,
            "order_id": order_id,
            "exchange_order_id": exchange_order_id,
            "status": status,
        }

        self._execute(sql, params)

    def insert_log(
        self,
        bot_id: int,
        level: str,
        message: str,
        context_json: Optional[Any] = None,
    ) -> None:
        sql = """
            INSERT INTO bot_logs (bot_id, level, message, context_json)
            VALUES (%(bot_id)s, %(level)s, %(message)s, %(context_json)s)
        """

        payload = context_json
        if payload is not None and not isinstance(payload, str):
            payload = json.dumps(payload, default=str)

        params = {
            "bot_id": bot_id,
            "level": level.upper(),
            "message": message,
            "context_json": payload,
        }

        logger.debug("bot_logs insert prepared bot_id=%s level=%s message=%s", bot_id, params["level"], message)
        self._execute(sql, params)

    def _fetch_all(
        self,
        sql: str,
        params: Optional[Dict[str, Any]] = None,
    ) -> List[Dict[str, Any]]:
        try:
            with get_connection() as connection:
                with connection.cursor(DictCursor) as cursor:
                    cursor.execute(sql, params or {})
                    rows = cursor.fetchall()
            return rows
        except MySQLError:
            logger.exception("Database fetch failed")
            raise

    def _execute(self, sql: str, params: Dict[str, Any]) -> None:
        try:
            with get_connection() as connection:
                with connection.cursor() as cursor:
                    cursor.execute(sql, params)
                connection.commit()
        except MySQLError:
            logger.exception("Database write failed")
            raise