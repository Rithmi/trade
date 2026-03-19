"""Strategy module for bot entry, DCA, bounce, and trailing logic."""

from __future__ import annotations

import logging
import os
import time
from dataclasses import dataclass
from decimal import InvalidOperation
from typing import Any, Dict, Optional

logger = logging.getLogger("bot.strategy")


@dataclass
class StrategyConfig:
    direction: str
    base_order_usdt: float
    dca_multiplier: float
    max_safety_orders: int
    next_dca_trigger_drop_pct: float
    bounce_from_local_low_pct: float
    trailing_activation_profit_pct: float
    trailing_drawdown_pct: float
    allow_reentry: bool
    signal_cooldown: float
    min_notional: float


@dataclass
class StrategyDecision:
    action: str  # ENTRY, DCA, EXIT
    side: str  # BUY or SELL
    quantity: float
    reason: str
    reduce_only: bool = False
    timestamp: float = 0.0


class BotStrategy:
    """Stateful helper that turns mark prices into concrete trade decisions."""

    def __init__(self, bot: Dict[str, Any], state_ref: Dict[str, Any]) -> None:
        self.bot = bot
        self.bot_id = int(bot.get("id") or 0)
        self.symbol = (bot.get("symbol") or "").upper()
        self.state = state_ref
        if self.state.get("position_qty") is None:
            self.state["position_qty"] = 0.0

        env_cooldown = float(os.getenv("STRATEGY_SIGNAL_COOLDOWN", "3"))
        env_min_notional = float(os.getenv("STRATEGY_MIN_NOTIONAL", "1"))

        self.config = StrategyConfig(
            direction=(bot.get("direction") or "LONG").upper(),
            base_order_usdt=max(float(bot.get("base_order_usdt") or 10.0), 1.0),
            dca_multiplier=max(float(bot.get("dca_multiplier") or 1.0), 1.0),
            max_safety_orders=int(bot.get("max_safety_orders") or 0),
            next_dca_trigger_drop_pct=max(float(bot.get("next_dca_trigger_drop_pct") or 1.0), 0.1),
            bounce_from_local_low_pct=max(float(bot.get("bounce_from_local_low_pct") or 1.0), 0.1),
            trailing_activation_profit_pct=max(float(bot.get("trailing_activation_profit_pct") or 1.0), 0.1),
            trailing_drawdown_pct=max(float(bot.get("trailing_drawdown_pct") or 0.5), 0.1),
            allow_reentry=self._to_bool(bot.get("allow_reentry")),
            signal_cooldown=max(env_cooldown, 0.0),
            min_notional=max(env_min_notional, 0.0),
        )

        self.meta: Dict[str, Any] = {
            "dca_levels": set(),
            "trailing_active": False,
            "best_price": None,
            "last_entry_price": None,
            "bounce_notified": False,
            "last_signal_at": 0.0,
        }

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------
    def evaluate(self, price: float, event_time_ms: int) -> Optional[StrategyDecision]:
        if price <= 0 or not self.symbol:
            return None

        now_ts = self._timestamp_from_event(event_time_ms)
        self._update_extrema(price)

        for checker in (self._maybe_trailing_exit, self._maybe_dca, self._maybe_entry):
            decision = checker(price)
            if not decision:
                continue
            if not self._cooldown_elapsed(now_ts):
                logger.debug("Bot %s cooldown active; skipping %s signal", self.bot_id, decision.action)
                return None
            decision.timestamp = now_ts
            self.meta["last_signal_at"] = now_ts
            return decision

        self._maybe_bounce(price)
        return None

    def on_execution(self, action: StrategyDecision, executed_qty: float, execution_price: float) -> None:
        direction = self.config.direction
        position_qty = float(self.state.get("position_qty") or 0.0)
        avg_price = float(self.state.get("avg_entry_price") or 0.0)

        if action.action in ("ENTRY", "DCA"):
            new_qty = position_qty + executed_qty
            new_avg = ((avg_price * position_qty) + (execution_price * executed_qty)) / new_qty if new_qty else execution_price
            self.state["position_qty"] = new_qty
            self.state["avg_entry_price"] = new_avg
            self.state["status"] = "ACTIVE"
            self.meta["last_entry_price"] = execution_price

            if action.action == "ENTRY":
                self.state["safety_order_count"] = 0
                self.meta["dca_levels"].clear()
                self.meta["bounce_notified"] = False
                self.state["local_low"] = execution_price
                self.state["local_high"] = execution_price
            else:
                self.state["safety_order_count"] = int(self.state.get("safety_order_count") or 0) + 1
                self.state["local_low"] = min(self.state.get("local_low") or execution_price, execution_price)
                self.state["local_high"] = max(self.state.get("local_high") or execution_price, execution_price)

        elif action.action == "EXIT":
            remaining = max(position_qty - executed_qty, 0.0)
            self.state["position_qty"] = remaining
            if remaining <= 0:
                self.state["avg_entry_price"] = None
                self.state["safety_order_count"] = 0
                self.state["status"] = "IDLE" if self.config.allow_reentry else "EXITED"
                self.meta["trailing_active"] = False
                self.meta["best_price"] = None
                self.meta["bounce_notified"] = False
                self.meta["dca_levels"].clear()

        self._update_extrema(execution_price)
        logger.debug("Bot %s state after %s: qty=%.6f", self.bot_id, action.action, self.state.get("position_qty"))

    # ------------------------------------------------------------------
    # Decision helpers
    # ------------------------------------------------------------------
    def _maybe_entry(self, price: float) -> Optional[StrategyDecision]:
        if float(self.state.get("position_qty") or 0) > 0:
            return None
        status = (self.state.get("status") or "IDLE").upper()
        if status not in ("IDLE", "EXITED") and not self.config.allow_reentry:
            return None

        move_pct = self._drop_from_high_pct(price) if self.config.direction != "SHORT" else self._rise_from_low_pct(price)
        if move_pct < self.config.next_dca_trigger_drop_pct:
            return None

        qty = self._notional_to_qty(price, self.config.base_order_usdt)
        if qty <= 0:
            return None

        logger.info("Entry signal for bot %s move=%.2f%%", self.bot_id, move_pct)
        return StrategyDecision(
            "ENTRY",
            self._entry_side(),
            qty,
            f"Move {move_pct:.2f}% exceeded entry trigger",
        )

    def _maybe_dca(self, price: float) -> Optional[StrategyDecision]:
        position_qty = float(self.state.get("position_qty") or 0)
        if position_qty <= 0:
            return None

        current_count = int(self.state.get("safety_order_count") or 0)
        if current_count >= self.config.max_safety_orders:
            return None

        target_level = current_count + 1
        if target_level in self.meta["dca_levels"]:
            return None

        drawdown = self._drawdown_from_avg_pct(price)
        target_drop = self.config.next_dca_trigger_drop_pct * (self.config.dca_multiplier ** (target_level - 1))
        if drawdown < target_drop:
            return None

        notional = self.config.base_order_usdt * (self.config.dca_multiplier ** (target_level - 1))
        qty = self._notional_to_qty(price, notional)
        if qty <= 0:
            return None

        self.meta["dca_levels"].add(target_level)
        logger.info(
            "DCA signal for bot %s level=%s drawdown=%.2f%% target=%.2f%%",
            self.bot_id,
            target_level,
            drawdown,
            target_drop,
        )
        return StrategyDecision("DCA", self._entry_side(), qty, f"DCA level {target_level} hit ({drawdown:.2f}%)")

    def _maybe_trailing_exit(self, price: float) -> Optional[StrategyDecision]:
        position_qty = float(self.state.get("position_qty") or 0)
        avg_price = float(self.state.get("avg_entry_price") or 0)
        if position_qty <= 0 or avg_price <= 0:
            self.meta["trailing_active"] = False
            self.meta["best_price"] = None
            return None

        if self.config.direction == "SHORT":
            profit_pct = max((avg_price - price) / avg_price * 100, 0.0)
        else:
            profit_pct = max((price - avg_price) / avg_price * 100, 0.0)

        if not self.meta["trailing_active"]:
            if profit_pct >= self.config.trailing_activation_profit_pct:
                self.meta["trailing_active"] = True
                self.meta["best_price"] = price
                logger.info("Trailing activated for bot %s profit=%.2f%%", self.bot_id, profit_pct)
            return None

        best_price = self.meta.get("best_price")
        if best_price is None:
            self.meta["best_price"] = price
            return None

        if self.config.direction == "SHORT":
            if price < best_price:
                self.meta["best_price"] = price
                return None
            drawdown = max((price - best_price) / best_price * 100, 0.0)
        else:
            if price > best_price:
                self.meta["best_price"] = price
                return None
            drawdown = max((best_price - price) / best_price * 100, 0.0)

        if drawdown >= self.config.trailing_drawdown_pct:
            logger.info("Trailing exit for bot %s drawdown=%.2f%%", self.bot_id, drawdown)
            return StrategyDecision(
                "EXIT",
                self._exit_side(),
                position_qty,
                "Trailing drawdown hit",
                reduce_only=True,
            )
        return None

    def _maybe_bounce(self, price: float) -> None:
        if self.meta.get("bounce_notified"):
            return
        local_low = self._as_float(self.state.get("local_low"))
        if local_low <= 0:
            return
        bounce_pct = ((price - local_low) / local_low * 100) if local_low else 0
        if bounce_pct >= self.config.bounce_from_local_low_pct:
            logger.info("Bounce detected for bot %s (%.2f%%)", self.bot_id, bounce_pct)
            self.meta["bounce_notified"] = True

    # ------------------------------------------------------------------
    # Utilities
    # ------------------------------------------------------------------
    def _entry_side(self) -> str:
        return "BUY" if self.config.direction != "SHORT" else "SELL"

    def _exit_side(self) -> str:
        return "SELL" if self.config.direction != "SHORT" else "BUY"

    def _update_extrema(self, price: float) -> None:
        local_low = self._as_float(self.state.get("local_low"))
        local_high = self._as_float(self.state.get("local_high"))
        self.state["local_low"] = min(local_low, price) if local_low > 0 else price
        self.state["local_high"] = max(local_high, price) if local_high > 0 else price

    def _drop_from_high_pct(self, price: float) -> float:
        local_high = self._as_float(self.state.get("local_high")) or price
        if local_high <= 0:
            return 0.0
        return max((local_high - price) / local_high * 100, 0.0)

    def _rise_from_low_pct(self, price: float) -> float:
        local_low = self._as_float(self.state.get("local_low")) or price
        if local_low <= 0:
            return 0.0
        return max((price - local_low) / local_low * 100, 0.0)

    def _drawdown_from_avg_pct(self, price: float) -> float:
        avg = self._as_float(self.state.get("avg_entry_price"))
        if avg <= 0:
            return 0.0
        if self.config.direction == "SHORT":
            return max((price - avg) / avg * 100, 0.0)
        return max((avg - price) / avg * 100, 0.0)

    def _notional_to_qty(self, price: float, notional: float) -> float:
        notional = max(notional, self.config.min_notional)
        if price <= 0:
            return 0.0
        qty = notional / price
        return max(qty, 0.0001)

    def _cooldown_elapsed(self, now_ts: float) -> bool:
        last = float(self.meta.get("last_signal_at") or 0.0)
        return (now_ts - last) >= self.config.signal_cooldown

    @staticmethod
    def _timestamp_from_event(event_time_ms: int) -> float:
        return event_time_ms / 1000 if event_time_ms else time.time()

    @staticmethod
    def _to_bool(value: Any) -> bool:
        if isinstance(value, str):
            return value.strip().lower() in {"1", "true", "yes", "on"}
        return bool(value)

    @staticmethod
    def _as_float(value: Any, default: float = 0.0) -> float:
        if value is None:
            return default
        if isinstance(value, (int, float)):
            return float(value)
        try:
            return float(value)
        except (TypeError, ValueError, InvalidOperation):
            return default


__all__ = ["BotStrategy", "StrategyDecision"]
