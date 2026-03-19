"""Utility helpers for ad-hoc Binance order placement/tests."""

from __future__ import annotations

import os
from typing import Any, Dict, Optional

from binance_rest import BinanceAPIError, BinanceREST


class BinanceOrder:
    """Simple convenience wrapper around BinanceREST create/cancel calls."""

    def __init__(self, rest: Optional[BinanceREST] = None, mode: Optional[str] = None) -> None:
        self.rest = rest or BinanceREST()
        self.mode = (mode or os.getenv("ORDER_EXECUTION_MODE") or "test").strip().lower()
        if self.mode not in {"off", "test", "live"}:
            self.mode = "test"

    # ------------------------------------------------------------------
    # Public helpers
    # ------------------------------------------------------------------
    def place_market_buy(self, symbol: str, quantity: float, *, reduce_only: bool = False) -> Dict[str, Any]:
        return self._place_market(symbol, "BUY", quantity, reduce_only=reduce_only)

    def place_market_sell(self, symbol: str, quantity: float, *, reduce_only: bool = False) -> Dict[str, Any]:
        return self._place_market(symbol, "SELL", quantity, reduce_only=reduce_only)

    def cancel(self, symbol: str, *, order_id: Optional[int] = None, client_order_id: Optional[str] = None) -> Dict[str, Any]:
        return self.rest.cancel_order(symbol, order_id=order_id, client_order_id=client_order_id)

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------
    def _place_market(self, symbol: str, side: str, quantity: float, *, reduce_only: bool = False) -> Dict[str, Any]:
        if self.mode == "off":
            return {"success": False, "message": "Order execution disabled", "mode": self.mode}

        payload: Dict[str, Any] = {
            "symbol": symbol.upper(),
            "side": side.upper(),
            "type": "MARKET",
            "quantity": quantity,
        }
        if reduce_only:
            payload["reduceOnly"] = True

        test_mode = self.mode != "live"

        try:
            response = self.rest.create_test_order(**payload) if test_mode else self.rest.create_order(**payload)
        except BinanceAPIError as exc:
            return {"success": False, "message": str(exc), "mode": self.mode, "payload": exc.payload}

        return {"success": True, "mode": "test" if test_mode else "live", "payload": response}


__all__ = ["BinanceOrder"]