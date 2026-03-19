"""Binance Futures client configuration helpers."""

from __future__ import annotations

import hashlib
import hmac
import os
import time
from dataclasses import dataclass
from typing import Dict, Mapping
from urllib.parse import urlencode

from settings import Settings


@dataclass(frozen=True)
class BinanceEndpoints:
    rest_base_url: str
    ws_base_url: str


class BinanceAuthError(RuntimeError):
    """Raised when a signed request is attempted without credentials."""


class BinanceClient:
    """Encapsulates API credentials, endpoints, and signing helpers."""

    _MAINNET = BinanceEndpoints(
        rest_base_url="https://fapi.binance.com",
        ws_base_url="wss://fstream.binance.com",
    )
    _TESTNET = BinanceEndpoints(
        rest_base_url="https://testnet.binancefuture.com",
        ws_base_url="wss://fstream.binancefuture.com",
    )

    def __init__(
        self,
        api_key: str | None = None,
        api_secret: str | None = None,
        testnet: bool | None = None,
        recv_window: int | None = None,
        timeout: int | None = None,
    ) -> None:
        self.api_key = (api_key or Settings.BINANCE_API_KEY or "").strip()
        self.api_secret = (api_secret or Settings.BINANCE_API_SECRET or "").strip()
        self.recv_window = recv_window or int(os.getenv("BINANCE_RECV_WINDOW", "5000"))
        self.timeout = timeout or int(os.getenv("BINANCE_HTTP_TIMEOUT", "20"))

        use_testnet = Settings.BINANCE_TESTNET if testnet is None else bool(testnet)
        endpoints = self._TESTNET if use_testnet else self._MAINNET

        rest_override = os.getenv("BINANCE_REST_URL")
        ws_override = os.getenv("BINANCE_WS_URL")
        self.rest_base_url = (rest_override or endpoints.rest_base_url).rstrip("/")
        self.ws_base_url = (ws_override or endpoints.ws_base_url).rstrip("/")

    @staticmethod
    def timestamp_ms() -> int:
        return int(time.time() * 1000)

    def rest_url(self, path: str) -> str:
        return f"{self.rest_base_url}{path}"

    @property
    def has_credentials(self) -> bool:
        return bool(self.api_key and self.api_secret)

    def require_credentials(self) -> None:
        if not self.has_credentials:
            raise BinanceAuthError(
                "Binance API key/secret missing. Set BINANCE_API_KEY and BINANCE_API_SECRET in your .env file."
            )

    def sign_params(self, params: Mapping[str, str | int | float]) -> Dict[str, str | int | float]:
        self.require_credentials()
        query_string = urlencode(params, doseq=True)
        signature = hmac.new(
            self.api_secret.encode("utf-8"),
            query_string.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        params_with_sig: Dict[str, str | int | float] = dict(params)
        params_with_sig["signature"] = signature
        return params_with_sig

    def auth_headers(self) -> Dict[str, str]:
        if not self.api_key:
            return {}
        return {"X-MBX-APIKEY": self.api_key}

__all__ = ["BinanceClient", "BinanceAuthError"]