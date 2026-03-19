import os
from pathlib import Path
from typing import Iterable, List, Set

from dotenv import load_dotenv


_ENV_LOADED = False


def _iter_env_paths() -> Iterable[Path]:
    override = os.getenv("PYTHON_BOT_ENV_FILE")
    if override:
        yield Path(override)

    current = Path(__file__).resolve().parent
    yield current / ".env"

    parents: List[Path] = []
    for idx, parent in enumerate(current.parents):
        if idx >= 3:
            break
        parents.append(parent)

    for parent in parents:
        yield parent / ".env"

    yield current.parent / ".env.local"


def _load_environment() -> None:
    global _ENV_LOADED
    if _ENV_LOADED:
        return

    loaded_any = False
    seen: Set[Path] = set()

    for candidate in _iter_env_paths():
        try:
            resolved = candidate.resolve()
        except OSError:
            continue

        if resolved in seen or not resolved.is_file():
            continue

        loaded_any = True
        seen.add(resolved)
        load_dotenv(dotenv_path=resolved, override=False)

    if not loaded_any:
        load_dotenv()

    _ENV_LOADED = True


_load_environment()


def str_to_bool(value: str) -> bool:
    return str(value).strip().lower() in ["1", "true", "yes", "on"]


class Settings:
    DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
    DB_PORT = int(os.getenv("DB_PORT", "3306"))
    DB_NAME = os.getenv("DB_NAME", "trades")
    DB_USER = os.getenv("DB_USER", "root")
    DB_PASS = os.getenv("DB_PASS", "")

    BINANCE_API_KEY = os.getenv("BINANCE_API_KEY", "")
    BINANCE_API_SECRET = os.getenv("BINANCE_API_SECRET", "")
    BINANCE_TESTNET = str_to_bool(os.getenv("BINANCE_TESTNET", "true"))

    @classmethod
    def mysql_url(cls) -> str:
        return (
            f"mysql+pymysql://{cls.DB_USER}:{cls.DB_PASS}"
            f"@{cls.DB_HOST}:{cls.DB_PORT}/{cls.DB_NAME}?charset=utf8mb4"
        )

    @classmethod
    def mysql_kwargs(cls) -> dict:
        return {
            "host": cls.DB_HOST,
            "port": cls.DB_PORT,
            "user": cls.DB_USER,
            "password": cls.DB_PASS,
            "database": cls.DB_NAME,
            "charset": "utf8mb4",
        }

    @classmethod
    def binance_rest_url(cls) -> str:
        if cls.BINANCE_TESTNET:
            return "https://testnet.binancefuture.com"
        return "https://fapi.binance.com"