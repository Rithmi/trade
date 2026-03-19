"""Phase 10 bot engine: heartbeat + Binance REST/WS + strategy execution."""

from __future__ import annotations

import logging
import os
import signal
import sys
import threading
import time
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Dict, List, Optional, Tuple

from binance_client import BinanceClient
from binance_rest import BinanceREST
from binance_ws import BinanceMarkPriceStream
from repository import Repository
from strategy import BotStrategy, StrategyDecision


logger = logging.getLogger("bot.engine")


class BotEngine:
    def __init__(self, repo: Repository, loop_sleep: int = 10, heartbeat_log_interval: int = 60) -> None:
        self.repo = repo
        self.loop_sleep = loop_sleep
        self.heartbeat_log_interval = max(heartbeat_log_interval, loop_sleep)
        self._running = True
        self._last_logged_at: Dict[int, float] = {}
        self._last_price_tick: Dict[int, float] = {}
        self._state_cache: Dict[int, Dict[str, Any]] = {}
        self._bots: List[Dict[str, Any]] = []
        self._bot_map: Dict[str, List[Dict[str, Any]]] = {}
        self._lock = threading.Lock()
        self.strategies: Dict[int, BotStrategy] = {}
        self._ws_symbols: List[str] = []

        self.binance_client = BinanceClient()
        self.rest = BinanceREST(self.binance_client)
        self.ws_stream: Optional[BinanceMarkPriceStream] = None
        self._price_log_at: Dict[int, float] = {}
        self.enable_order_test = os.getenv("ENABLE_ORDER_TEST", "false").lower() == "true"
        self._order_test_ran = False
        self._symbol_info: Dict[str, Dict[str, Any]] = {}
        self.order_executor = OrderExecutor(self.rest)
        logger.info(
            "Order execution mode resolved to %s (ENABLE_ORDER_TEST=%s)",
            self.order_executor.mode.upper(),
            "true" if self.enable_order_test else "false",
        )

    def register_signal_handlers(self) -> None:
        signal.signal(signal.SIGINT, self._handle_stop)
        if hasattr(signal, "SIGTERM"):
            signal.signal(signal.SIGTERM, self._handle_stop)

    def _handle_stop(self, *_args) -> None:
        logger.info("Stop signal received. Shutting down...")
        self._running = False
        if self.ws_stream:
            self.ws_stream.stop()

    def run(self) -> None:
        logger.info("Bootstrapping bot engine with Binance integration")
        bots = self._load_bots()
        if not bots:
            logger.info("No enabled bots found. Exiting cleanly.")
            return

        self._bots = bots
        self._bot_map = self._group_bots_by_symbol(bots)
        self._initialize_bot_state(bots)
        self._build_strategies(bots)

        valid_symbols, exchange_info = self._bootstrap_binance_checks()
        active_bots = [bot for bot in bots if (bot.get("symbol") or "").upper() in valid_symbols]

        if valid_symbols:
            logger.info("Subscribing to %d Binance symbol(s): %s", len(valid_symbols), ", ".join(valid_symbols))
            self._start_websocket(valid_symbols)
            self._maybe_run_order_test(exchange_info, valid_symbols)
        else:
            logger.warning("No valid Binance symbols found. Running heartbeat only.")

        target_bots = active_bots if active_bots else bots
        logger.info("Heartbeat loop ready for %d bot(s)", len(target_bots))
        try:
            self._heartbeat_loop(target_bots)
        finally:
            if self.ws_stream:
                self.ws_stream.stop()
            self.rest.close()
            logger.info("Bot engine stopped")

    def _load_bots(self) -> List[Dict[str, Any]]:
        try:
            bots = self.repo.get_enabled_bots()
            logger.info("Loaded %d enabled bots", len(bots))
            return bots
        except Exception:
            logger.exception("Failed to load enabled bots")
            return []

    def _group_bots_by_symbol(self, bots: List[Dict[str, Any]]) -> Dict[str, List[Dict[str, Any]]]:
        grouped: Dict[str, List[Dict[str, Any]]] = {}
        for bot in bots:
            symbol = (bot.get("symbol") or "").upper()
            if not symbol:
                continue
            grouped.setdefault(symbol, []).append(bot)
        return grouped

    def _initialize_bot_state(self, bots: List[Dict[str, Any]]) -> None:
        for bot in bots:
            bot_id = bot.get("id")
            if not bot_id:
                logger.warning("Skipping bot row without an id: %s", bot)
                continue

            state = {
                "status": bot.get("state_status") or "IDLE",
                "avg_entry_price": bot.get("state_avg_entry_price"),
                "position_qty": bot.get("state_position_qty"),
                "safety_order_count": bot.get("state_safety_order_count") or 0,
                "last_price": bot.get("state_last_price"),
                "local_low": bot.get("state_local_low"),
                "local_high": bot.get("state_local_high"),
                "last_message": "Bootstrap complete",
            }

            try:
                self.repo.upsert_bot_state(bot_id, state)
                self.repo.insert_log(
                    bot_id,
                    "info",
                    f"Bot '{bot.get('name', bot_id)}' registered",
                    {"symbol": bot.get("symbol"), "status": state["status"]},
                )
                self._state_cache[bot_id] = dict(state)
                self._last_logged_at[bot_id] = 0
            except Exception:
                logger.exception("Failed to initialize bot_state for bot_id=%s", bot_id)

    def _build_strategies(self, bots: List[Dict[str, Any]]) -> None:
        for bot in bots:
            bot_id = bot.get("id")
            if not bot_id:
                continue
            state = self._state_cache.setdefault(bot_id, self._default_state())
            self.strategies[bot_id] = BotStrategy(bot, state)
        logger.info("Initialized %d strategy instance(s)", len(self.strategies))

    def _bootstrap_binance_checks(self) -> Tuple[List[str], Dict[str, Any]]:
        try:
            self.rest.ping()
            logger.info("Binance ping successful")
        except Exception:
            logger.exception("Binance ping failed; aborting symbol subscriptions")
            return [], {}

        try:
            info = self.rest.get_exchange_info(use_cache=False)
        except Exception:
            logger.exception("Unable to load Binance exchange info")
            return [], {}

        self._symbol_info = {
            (item.get("symbol") or "").upper(): item for item in info.get("symbols", []) if item.get("symbol")
        }

        available_symbols = {item.get("symbol", "").upper() for item in info.get("symbols", [])}
        requested_symbols = set(self._bot_map.keys())
        valid_symbols = sorted(sym for sym in requested_symbols if sym in available_symbols)
        invalid_symbols = sorted(requested_symbols - set(valid_symbols))

        for symbol in invalid_symbols:
            logger.error("Symbol %s is not available on Binance Futures", symbol)
            for bot in self._bot_map.get(symbol, []):
                bot_id = bot.get("id")
                if not bot_id:
                    continue
                message = f"Symbol {symbol} not supported on Binance Futures"
                try:
                    self.repo.insert_log(bot_id, "error", message)
                    self._update_state_cache(bot_id, status="ERROR", last_message=message)
                    self._persist_state(bot_id)
                except Exception:
                    logger.exception("Failed to persist invalid symbol state for bot_id=%s", bot_id)

        return valid_symbols, info

    def _start_websocket(self, symbols: List[str]) -> None:
        if not symbols:
            return

        self.ws_stream = BinanceMarkPriceStream(
            base_ws_url=self.binance_client.ws_base_url,
            symbols=symbols,
            on_tick=self._handle_mark_price,
        )
        self.ws_stream.start()
        self._ws_symbols = list(symbols)

    def _heartbeat_loop(self, bots: List[Dict[str, Any]]) -> None:
        while self._running:
            loop_started = time.time()
            for bot in bots:
                self._heartbeat_bot(bot)

            self._ensure_ws_stream()

            elapsed = time.time() - loop_started
            sleep_for = max(self.loop_sleep - int(elapsed), 1)
            time.sleep(sleep_for)

        logger.info("Heartbeat loop stopped")

    def _heartbeat_bot(self, bot: Dict[str, Any]) -> None:
        bot_id = bot.get("id")
        if not bot_id:
            return

        now_iso = datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S')
        heartbeat_message = f"Heartbeat OK @ {now_iso}Z"
        with self._lock:
            state = self._state_cache.setdefault(bot_id, self._default_state())
            state["status"] = state.get("status") or "RUNNING"
            last_tick = self._last_price_tick.get(bot_id)
            stale_threshold = max(self.loop_sleep * 3, 30)
            if last_tick is None:
                heartbeat_message = "Awaiting first price tick…"
            else:
                delta = time.time() - last_tick
                if delta > stale_threshold:
                    heartbeat_message = f"No price data for {int(delta)}s; awaiting stream"
                    state["status"] = "STALE"
            state["last_message"] = heartbeat_message

        self._persist_state(bot_id)

        now = time.time()
        last_log = self._last_logged_at.get(bot_id, 0)
        if (now - last_log) >= self.heartbeat_log_interval:
            try:
                self.repo.insert_log(
                    bot_id,
                    "info",
                    "Heartbeat",
                    {"symbol": bot.get("symbol"), "message": heartbeat_message},
                )
                self._last_logged_at[bot_id] = now
            except Exception:
                logger.exception("Failed to log heartbeat for bot_id=%s", bot_id)

    def _handle_mark_price(self, symbol: str, price: float, event_time_ms: int) -> None:
        bots = self._bot_map.get(symbol.upper(), [])
        if not bots:
            return

        for bot in bots:
            bot_id = bot.get("id")
            if not bot_id:
                continue

            message = f"{symbol} mark price {price:.4f} (E={event_time_ms})"
            self._update_state_cache(bot_id, status="RUNNING", last_price=price, last_message=message)
            self._last_price_tick[bot_id] = time.time()
            strategy = self.strategies.get(bot_id)
            if strategy:
                try:
                    decision = strategy.evaluate(price, event_time_ms)
                    if decision:
                        self._process_strategy_decision(bot, strategy, decision, price)
                except Exception:
                    logger.exception("Strategy evaluation failed for bot_id=%s", bot_id)

            self._persist_state(bot_id)
            self._maybe_log_price(bot_id, symbol, price, message)

    def _maybe_log_price(self, bot_id: int, symbol: str, price: float, message: str) -> None:
        now = time.time()
        last_log = self._price_log_at.get(bot_id, 0)
        if (now - last_log) < self.heartbeat_log_interval:
            return

        try:
            self.repo.insert_log(
                bot_id,
                "info",
                "Price update",
                {"symbol": symbol, "mark_price": price, "message": message},
            )
            self._price_log_at[bot_id] = now
        except Exception:
            logger.exception("Failed to log price update for bot_id=%s", bot_id)

    def _process_strategy_decision(
        self,
        bot: Dict[str, Any],
        strategy: BotStrategy,
        decision: StrategyDecision,
        price: float,
    ) -> None:
        bot_id = bot.get("id")
        if not bot_id:
            return
        symbol = (bot.get("symbol") or "").upper()
        symbol_info = self._symbol_info.get(symbol)
        if not symbol_info:
            logger.warning("No symbol metadata for %s; cannot execute order", symbol)
            return

        raw_qty = max(float(decision.quantity), 0.0)
        if raw_qty <= 0:
            logger.warning("Bot %s produced non-positive quantity", bot_id)
            return

        qty_str, _ = self.rest.apply_filters(symbol_info, raw_qty)
        try:
            executed_qty = float(qty_str)
        except (TypeError, ValueError):
            logger.warning("Unable to parse formatted quantity '%s' for bot %s", qty_str, bot_id)
            return
        if executed_qty <= 0:
            logger.warning("Filtered quantity not tradable for bot %s", bot_id)
            return

        try:
            result = self.order_executor.execute(
                symbol=symbol,
                side=decision.side,
                quantity=qty_str,
                price_reference=price,
                reduce_only=decision.reduce_only,
            )
        except Exception:
            logger.exception("Order execution failed for bot_id=%s", bot_id)
            self.repo.insert_log(
                bot_id,
                "error",
                "Order execution failed",
                {"symbol": symbol, "action": decision.action, "side": decision.side},
            )
            return

        if not result.executed:
            message = result.message or "Order skipped"
            self.repo.insert_log(
                bot_id,
                "info",
                "Strategy signal skipped",
                {
                    "symbol": symbol,
                    "action": decision.action,
                    "side": decision.side,
                    "mode": result.mode,
                    "reason": decision.reason,
                    "details": message,
                },
            )
            self._update_state_cache(bot_id, last_message=message)
            return

        strategy.on_execution(decision, executed_qty, price)
        action_message = f"{decision.action} {decision.side} {executed_qty} @ {price:.4f} [{result.mode}]"
        self.repo.insert_trade(
            bot_id,
            symbol,
            decision.side,
            decision.action,
            executed_qty,
            price,
            result.order_id,
        )
        self.repo.insert_log(
            bot_id,
            "info",
            "Strategy order executed",
            {
                "symbol": symbol,
                "action": decision.action,
                "side": decision.side,
                "qty": executed_qty,
                "price": price,
                "mode": result.mode,
                "order_id": result.order_id,
                "reason": decision.reason,
            },
        )
        with self._lock:
            state = self._state_cache.setdefault(bot_id, self._default_state())
            state["last_message"] = action_message
        self._persist_state(bot_id)

    def _update_state_cache(self, bot_id: int, **fields: Any) -> None:
        with self._lock:
            state = self._state_cache.setdefault(bot_id, self._default_state())
            state.update(fields)
            if "last_price" in fields:
                state["last_price_at"] = datetime.utcnow().isoformat()

    def _persist_state(self, bot_id: int) -> None:
        with self._lock:
            state = self._state_cache.get(bot_id)
            if not state:
                return
            payload = {
                "status": state.get("status") or "IDLE",
                "avg_entry_price": state.get("avg_entry_price"),
                "position_qty": state.get("position_qty"),
                "safety_order_count": state.get("safety_order_count", 0),
                "last_price": state.get("last_price"),
                "local_low": state.get("local_low"),
                "local_high": state.get("local_high"),
                "last_message": state.get("last_message"),
            }

        try:
            self.repo.upsert_bot_state(bot_id, payload)
            logger.debug(
                "Persisted bot_state bot_id=%s status=%s qty=%s price=%s",
                bot_id,
                payload.get("status"),
                payload.get("position_qty"),
                payload.get("last_price"),
            )
        except Exception:
            logger.exception("Failed to persist bot_state for bot_id=%s", bot_id)

    def _maybe_run_order_test(self, exchange_info: Dict[str, Any], symbols: List[str]) -> None:
        if not self.enable_order_test:
            logger.info(
                "Order test disabled. Skipping any order placement (set ENABLE_ORDER_TEST=true to allow single test order)."
            )
            return
        if self._order_test_ran:
            logger.info("Order test already executed this run; skipping.")
            return
        if not symbols:
            logger.warning("Order test requested but no valid symbols available.")
            return

        logger.info("Order test enabled. Attempting single test order...")
        self._order_test_ran = True
        symbol = symbols[0]
        bot = next((b for b in self._bots if (b.get("symbol") or "").upper() == symbol), None)
        if not bot:
            logger.warning("No bot found for symbol %s to run order test", symbol)
            return

        price_reference = float(bot.get("state_last_price") or 1)
        base_order_usdt = float(bot.get("base_order_usdt") or 10)
        qty = base_order_usdt / max(price_reference, 1)
        if qty <= 0:
            qty = 0.001

        symbol_info = next((s for s in exchange_info.get("symbols", []) if s.get("symbol") == symbol), None)
        if not symbol_info:
            logger.warning("No exchange info found for symbol %s", symbol)
            return

        formatted_qty, _ = self.rest.apply_filters(symbol_info, qty)
        params = {
            "symbol": symbol,
            "side": "BUY",
            "type": "MARKET",
            "quantity": formatted_qty,
        }

        try:
            result = self.rest.create_test_order(**params)
            logger.info("Test order request sent for %s qty=%s result=%s", symbol, formatted_qty, result)
            self.repo.insert_log(
                bot.get("id"),
                "info",
                "Test order executed",
                {"symbol": symbol, "qty": formatted_qty},
            )
        except Exception:
            logger.exception("Test order failed for %s", symbol)

    @staticmethod
    def _default_state() -> Dict[str, Any]:
        return {
            "status": "IDLE",
            "avg_entry_price": None,
            "position_qty": None,
            "safety_order_count": 0,
            "last_price": None,
            "last_price_at": None,
            "local_low": None,
            "local_high": None,
            "last_message": None,
        }

    def _ensure_ws_stream(self) -> None:
        if not self._ws_symbols:
            return
        if self.ws_stream and self.ws_stream.is_alive():
            return
        logger.warning("WebSocket stream not active. Restarting…")
        if self.ws_stream:
            self.ws_stream.stop()
        self._start_websocket(self._ws_symbols)


@dataclass
class OrderResult:
    executed: bool
    order_id: str
    quantity: float
    price: float
    mode: str
    message: str = ""


class OrderExecutor:
    """Encapsulate Binance order placement with safety toggles."""

    def __init__(self, rest: BinanceREST) -> None:
        self.rest = rest
        self.mode = self._resolve_mode()

    def _resolve_mode(self) -> str:
        mode = (os.getenv("ORDER_EXECUTION_MODE") or "").strip().lower()
        if not mode:
            mode = "test" if os.getenv("ENABLE_ORDER_TEST", "false").lower() == "true" else "off"
        if mode not in {"off", "test", "live"}:
            logger.warning("Unknown ORDER_EXECUTION_MODE=%s. Falling back to OFF.", mode)
            return "off"
        return mode

    def execute(
        self,
        *,
        symbol: str,
        side: str,
        quantity: str,
        price_reference: float,
        reduce_only: bool = False,
    ) -> OrderResult:
        if self.mode == "off":
            return OrderResult(False, "DISABLED", float(quantity), price_reference, self.mode, "Order execution disabled")

        payload: Dict[str, Any] = {
            "symbol": symbol,
            "side": side,
            "type": "MARKET",
            "quantity": quantity,
        }
        if reduce_only:
            payload["reduceOnly"] = True

        try:
            if self.mode == "test":
                response = self.rest.create_test_order(**payload)
                order_id = str(response.get("orderId") or "TEST")
            else:
                response = self.rest.create_order(**payload)
                order_id = str(response.get("orderId") or "LIVE")
            return OrderResult(True, order_id, float(quantity), price_reference, self.mode, "Order submitted")
        except Exception:
            logger.exception("Binance order request failed for %s", symbol)
            raise


def main() -> None:
    level_name = os.getenv("PYTHON_BOT_LOG_LEVEL", "INFO").upper()
    log_level = getattr(logging, level_name, logging.INFO)
    logging.basicConfig(
        level=log_level,
        format="%(asctime)s [%(levelname)s] %(name)s - %(message)s",
        handlers=[logging.StreamHandler(sys.stdout)],
    )

    engine = BotEngine(Repository())
    engine.register_signal_handlers()

    try:
        engine.run()
    except KeyboardInterrupt:
        logger.info("Keyboard interrupt received. Goodbye!")


if __name__ == "__main__":
    main()