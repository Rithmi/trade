"""REST helpers for Binance Futures."""

from __future__ import annotations

import logging
import os
import time
from decimal import Decimal, ROUND_DOWN
from typing import Any, Dict, List, Optional, Tuple, Union

import requests

from binance_client import BinanceAuthError, BinanceClient

logger = logging.getLogger("binance.rest")

Json = Union[Dict[str, Any], List[Any]]


class BinanceAPIError(RuntimeError):
    """Raised when Binance returns an error payload or a request fails."""

    def __init__(self, message: str, *, status: int | None = None, payload: Optional[Dict[str, Any]] = None) -> None:
        super().__init__(message)
        self.status = status or 0
        self.payload = payload or {}


class BinanceREST:
    """Thin wrapper around requests.Session with Binance Futures helpers."""

    def __init__(self, client: BinanceClient | None = None) -> None:
        self.client = client or BinanceClient()
        self.session = requests.Session()

        headers = self.client.auth_headers()
        if headers:
            self.session.headers.update(headers)

        proxy_url = os.getenv("BINANCE_HTTP_PROXY")
        if proxy_url:
            self.session.proxies.update({"http": proxy_url, "https": proxy_url})

        self._exchange_info_cache: Optional[Dict[str, Any]] = None
        self._exchange_info_cached_at: float = 0.0
        self.exchange_info_ttl = int(os.getenv("BINANCE_INFO_CACHE_SECONDS", "300"))
        self.retry_statuses = {418, 429}

    # ------------------------------------------------------------------
    # Lifecycle helpers
    # ------------------------------------------------------------------
    def close(self) -> None:
        self.session.close()

    def __enter__(self) -> "BinanceREST":  # pragma: no cover
        return self

    def __exit__(self, exc_type, exc, tb) -> None:  # pragma: no cover
        self.close()

    # ------------------------------------------------------------------
    # Public REST endpoints
    # ------------------------------------------------------------------
    def ping(self) -> Dict[str, Any]:
        result = self._request("GET", "/fapi/v1/ping")
        if isinstance(result, dict):
            return result
        return {}

    def get_exchange_info(self, *, use_cache: bool = True) -> Dict[str, Any]:
        if use_cache and self._exchange_info_cache and (time.time() - self._exchange_info_cached_at) < self.exchange_info_ttl:
            return self._exchange_info_cache

        info = self._request("GET", "/fapi/v1/exchangeInfo")
        if not isinstance(info, dict):
            raise BinanceAPIError("Unexpected exchangeInfo payload")
        self._exchange_info_cache = info
        self._exchange_info_cached_at = time.time()
        return info

    def get_symbol_rules(self, symbol: str, *, refresh: bool = False) -> Optional[Dict[str, Any]]:
        info = self.get_exchange_info(use_cache=not refresh)
        symbol = symbol.upper()
        return next((entry for entry in info.get("symbols", []) if entry.get("symbol") == symbol), None)

    def get_mark_price(self, symbol: str) -> Dict[str, Any]:
        params = {"symbol": symbol.upper()}
        result = self._request("GET", "/fapi/v1/premiumIndex", params=params)
        if isinstance(result, dict):
            return result
        raise BinanceAPIError("Unexpected mark price payload")

    def get_position_risk(self, symbol: Optional[str] = None) -> List[Dict[str, Any]]:
        params: Dict[str, Any] = {}
        if symbol:
            params["symbol"] = symbol.upper()
        data = self._request("GET", "/fapi/v3/positionRisk", params=params, signed=True)
        return data if isinstance(data, list) else []

    def get_position_information(self, symbol: Optional[str] = None) -> List[Dict[str, Any]]:
        return self.get_position_risk(symbol)

    def get_open_orders(self, symbol: Optional[str] = None) -> List[Dict[str, Any]]:
        params: Dict[str, Any] = {}
        if symbol:
            params["symbol"] = symbol.upper()
        data = self._request("GET", "/fapi/v1/openOrders", params=params, signed=True)
        return data if isinstance(data, list) else []

    def get_futures_account(self) -> Dict[str, Any]:
        data = self._request("GET", "/fapi/v2/account", signed=True)
        if isinstance(data, dict):
            return data
        raise BinanceAPIError("Unexpected account payload")

    def change_margin_type(self, symbol: str, margin_type: str) -> Dict[str, Any]:
        params = {"symbol": symbol.upper(), "marginType": margin_type.upper()}
        result = self._request("POST", "/fapi/v1/marginType", params=params, signed=True)
        if isinstance(result, dict):
            return result
        raise BinanceAPIError("Unexpected margin type payload")

    def change_leverage(self, symbol: str, leverage: int) -> Dict[str, Any]:
        params = {"symbol": symbol.upper(), "leverage": int(leverage)}
        result = self._request("POST", "/fapi/v1/leverage", params=params, signed=True)
        if isinstance(result, dict):
            return result
        raise BinanceAPIError("Unexpected leverage payload")

    def create_test_order(self, **payload: Any) -> Dict[str, Any]:
        return self._create_order("/fapi/v1/order/test", payload)

    def create_order(self, **payload: Any) -> Dict[str, Any]:
        return self._create_order("/fapi/v1/order", payload)

    def get_order(
        self,
        symbol: str,
        order_id: Optional[int] = None,
        client_order_id: Optional[str] = None,
    ) -> Dict[str, Any]:
        params: Dict[str, Any] = {"symbol": symbol.upper()}
        if order_id is not None:
            params["orderId"] = order_id
        if client_order_id is not None:
            params["origClientOrderId"] = client_order_id
        result = self._request("GET", "/fapi/v1/order", params=params, signed=True)
        if isinstance(result, dict):
            return result
        raise BinanceAPIError("Unexpected order payload")

    def cancel_order(
        self,
        symbol: str,
        order_id: Optional[int] = None,
        client_order_id: Optional[str] = None,
    ) -> Dict[str, Any]:
        params: Dict[str, Any] = {"symbol": symbol.upper()}
        if order_id is not None:
            params["orderId"] = order_id
        if client_order_id is not None:
            params["origClientOrderId"] = client_order_id
        result = self._request("DELETE", "/fapi/v1/order", params=params, signed=True)
        if isinstance(result, dict):
            return result
        raise BinanceAPIError("Unexpected cancel payload")

    def apply_filters(
        self,
        symbol_info: Dict[str, Any],
        quantity: float,
        price: Optional[float] = None,
    ) -> Tuple[str, Optional[str]]:
        qty = self._apply_step_filter(quantity, symbol_info, "LOT_SIZE", "stepSize")
        price_str: Optional[str] = None
        if price is not None:
            price_str = self._apply_step_filter(price, symbol_info, "PRICE_FILTER", "tickSize")
        return qty, price_str

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------
    def _create_order(self, path: str, payload: Dict[str, Any]) -> Dict[str, Any]:
        params = self._prepare_signed_params(payload)
        body = self._send("POST", path, params=params)
        if path.endswith("/test") and not body:
            return {"success": True}
        if isinstance(body, dict):
            return body
        raise BinanceAPIError("Unexpected order response")

    def _request(
        self,
        method: str,
        path: str,
        params: Optional[Dict[str, Any]] = None,
        *,
        signed: bool = False,
    ) -> Json:
        payload = params.copy() if params else {}
        if signed:
            payload = self._prepare_signed_params(payload)
        return self._send(method, path, params=payload)

    def _prepare_signed_params(self, params: Dict[str, Any]) -> Dict[str, Any]:
        payload = params.copy()
        payload.setdefault("timestamp", self.client.timestamp_ms())
        payload.setdefault("recvWindow", self.client.recv_window)
        try:
            return self.client.sign_params(payload)
        except BinanceAuthError as exc:
            raise BinanceAPIError(str(exc)) from exc

    def _send(self, method: str, path: str, *, params: Optional[Dict[str, Any]] = None) -> Json:
        url = self.client.rest_url(path)
        try:
            response = self.session.request(
                method,
                url,
                params=params,
                timeout=self.client.timeout,
            )
            if response.status_code in self.retry_statuses:
                logger.warning("Binance returned %s for %s %s", response.status_code, method, path)
            response.raise_for_status()
            try:
                data: Json = response.json()
            except ValueError as exc:
                raise BinanceAPIError("Binance response not JSON", status=response.status_code) from exc
            logger.debug("Binance %s %s OK", method, path)
            return data
        except requests.HTTPError as exc:
            payload = self._safe_json(response=exc.response)
            message = payload.get("msg") or payload.get("message") or str(exc)
            logger.error("Binance HTTP error %s %s %s", method, path, payload)
            raise BinanceAPIError(
                message,
                status=exc.response.status_code if exc.response else None,
                payload=payload,
            ) from exc
        except requests.RequestException as exc:
            logger.exception("Binance request failed: %s %s", method, path)
            raise BinanceAPIError(f"Request failed: {exc}") from exc

    def _apply_step_filter(self, value: float, symbol_info: Dict[str, Any], filter_key: str, step_key: str) -> str:
        filt = next((f for f in symbol_info.get("filters", []) if f.get("filterType") == filter_key), None)
        if not filt:
            return f"{value}"

        step = Decimal(filt.get(step_key, "1"))
        precision = max(-step.as_tuple().exponent, 0)
        quant = Decimal(value).quantize(step, rounding=ROUND_DOWN)

        if filter_key == "LOT_SIZE":
            min_qty = Decimal(filt.get("minQty", "0"))
            if quant < min_qty:
                quant = min_qty
        if filter_key == "PRICE_FILTER":
            min_price = Decimal(filt.get("minPrice", "0"))
            if quant < min_price:
                quant = min_price

        formatted = format(quant, f".{precision}f") if precision > 0 else str(int(quant))
        return formatted.rstrip("0").rstrip(".") if "." in formatted else formatted

    @staticmethod
    def _safe_json(*, response: Optional[requests.Response]) -> Dict[str, Any]:
        if response is None:
            return {}
        try:
            data = response.json()
            return data if isinstance(data, dict) else {"data": data}
        except ValueError:
            return {"text": response.text}


__all__ = ["BinanceREST", "BinanceAPIError"]
