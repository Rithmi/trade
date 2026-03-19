"""Binance Futures WebSocket helpers for mark price streams."""

from __future__ import annotations

import json
import logging
import threading
import time
from typing import Callable, Iterable, Optional, Sequence

from websocket import WebSocketApp


logger = logging.getLogger("binance.ws")


class BinanceMarkPriceStream:
    """Manage a resilient Binance Futures mark price multi-stream connection."""

    def __init__(
        self,
        base_ws_url: str,
        symbols: Sequence[str],
        on_tick: Callable[[str, float, int], None],
        reconnect_delay: int = 5,
    ) -> None:
        self.base_ws_url = base_ws_url.rstrip("/")
        self.symbols = self._normalize_symbols(symbols)
        self.on_tick = on_tick
        self.reconnect_delay = reconnect_delay
        self._thread: Optional[threading.Thread] = None
        self._stop_event = threading.Event()
        self._ws_app: Optional[WebSocketApp] = None

    def start(self) -> None:
        if not self.symbols:
            logger.warning("No symbols provided for WebSocket stream; skipping start")
            return
        if self._thread and self._thread.is_alive():
            return
        self._stop_event.clear()
        self._thread = threading.Thread(target=self._run_forever, name="BinanceWS", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop_event.set()
        if self._ws_app:
            try:
                self._ws_app.close()
            except Exception:
                logger.exception("Failed to close WebSocket")
        if self._thread:
            self._thread.join(timeout=5)
            self._thread = None

    def is_alive(self) -> bool:
        return bool(self._thread and self._thread.is_alive() and not self._stop_event.is_set())

    def _run_forever(self) -> None:
        url = self._build_url(self.symbols)
        logger.info("Connecting to Binance WS: %s", url)

        while not self._stop_event.is_set():
            self._ws_app = WebSocketApp(
                url,
                on_open=self._handle_open,
                on_message=self._handle_message,
                on_error=self._handle_error,
                on_close=self._handle_close,
            )

            try:
                self._ws_app.run_forever(ping_interval=15, ping_timeout=10)
            except Exception:
                logger.exception("WebSocket run_forever crashed")

            if not self._stop_event.is_set():
                logger.warning("WebSocket disconnected; retrying in %ds", self.reconnect_delay)
                time.sleep(self.reconnect_delay)

    def _handle_open(self, _ws) -> None:
        logger.info("Binance WebSocket connected (%d streams)", len(self.symbols))

    def _handle_message(self, _ws, message: str) -> None:
        try:
            payload = json.loads(message)
            data = payload.get("data", payload)
            symbol = (data.get("s") or data.get("symbol") or "").upper()
            price_str = data.get("p") or data.get("markPrice")
            event_time = int(data.get("E")) if data.get("E") else int(time.time() * 1000)

            if not symbol or price_str is None:
                return

            price = float(price_str)
            try:
                self.on_tick(symbol, price, event_time)
            except Exception:
                logger.exception("on_tick handler raised")
        except Exception:
            logger.exception("Failed to process Binance WS message")

    def _handle_error(self, _ws, error) -> None:
        logger.error("Binance WebSocket error: %s", error)

    def _handle_close(self, _ws, status_code, msg) -> None:
        logger.warning("Binance WebSocket closed code=%s msg=%s", status_code, msg)

    def _build_url(self, symbols: Iterable[str]) -> str:
        streams = "/".join(f"{symbol.lower()}@markPrice" for symbol in symbols)
        return f"{self.base_ws_url}/stream?streams={streams}"

    @staticmethod
    def _normalize_symbols(symbols: Sequence[str]) -> list[str]:
        normalized = []
        for symbol in symbols:
            if not symbol:
                continue
            sym = symbol.upper()
            if sym not in normalized:
                normalized.append(sym)
        return normalized


__all__ = ["BinanceMarkPriceStream"]