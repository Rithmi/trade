"""Database helpers for the Python bot engine."""

from __future__ import annotations

import logging
from contextlib import contextmanager
from typing import Generator

import os

import pymysql
from pymysql import MySQLError
from pymysql.connections import Connection
from pymysql.cursors import DictCursor

from settings import Settings

logger = logging.getLogger(__name__)


def _build_db_config() -> dict:
    config = Settings.mysql_kwargs()
    config.update(
        {
            "cursorclass": DictCursor,
            "autocommit": False,
            "connect_timeout": int(os.getenv("DB_CONNECT_TIMEOUT", "5")),
            "read_timeout": int(os.getenv("DB_READ_TIMEOUT", "15")),
            "write_timeout": int(os.getenv("DB_WRITE_TIMEOUT", "15")),
        }
    )
    return config


_DB_CONFIG = _build_db_config()


def open_connection() -> Connection:
    """Return a new MySQL connection using the current environment settings."""

    try:
        return pymysql.connect(**_DB_CONFIG)
    except MySQLError as exc:  # pragma: no cover - log and re-raise for visibility
        logger.exception("Unable to open MySQL connection")
        raise


@contextmanager
def get_connection() -> Generator[Connection, None, None]:
    """Context manager that yields a live database connection."""

    connection = open_connection()
    try:
        yield connection
    finally:
        try:
            connection.close()
        except Exception:
            logger.warning("Failed to close MySQL connection cleanly", exc_info=True)


__all__ = ["get_connection", "open_connection"]