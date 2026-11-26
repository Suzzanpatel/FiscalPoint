"""Central configuration, read from environment variables / the project .env file."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

from dotenv import load_dotenv

PACKAGE_DIR = Path(__file__).resolve().parent
ML_DIR = PACKAGE_DIR.parent
PROJECT_ROOT = ML_DIR.parent

load_dotenv(PROJECT_ROOT / ".env")

#: Expense categories offered by the web app (must match EXPENSE_CATEGORIES in web/includes/bootstrap.php).
CATEGORIES: tuple[str, ...] = (
    "Housing",
    "Food",
    "Transportation",
    "Healthcare",
    "Education",
    "Entertainment",
    "Personal Care",
    "Debt Repayment",
    "Savings & Investments",
    "Insurance",
    "Childcare/Dependents",
    "Gifts & Donations",
    "Miscellaneous",
)

PAYMENT_METHODS: tuple[str, ...] = ("Cash", "Credit / Debit card", "UPI Payment")


def today():
    """Current date in the application timezone (APP_TIMEZONE, default Asia/Kolkata).

    The web app uses the same setting, so "this month" means the same thing in PHP, MySQL
    and the ML service.
    """
    import pandas as pd

    tz = os.getenv("APP_TIMEZONE") or "Asia/Kolkata"
    try:
        return pd.Timestamp.now(tz=tz).tz_localize(None).normalize()
    except Exception:
        return pd.Timestamp.now().normalize()


def _env(key: str, default: str | None = None) -> str | None:
    value = os.getenv(key)
    return default if value in (None, "") else value


@dataclass(frozen=True)
class Settings:
    """Runtime settings. Construct with ``Settings.from_env()``."""

    db_host: str | None = None
    db_port: int = 3306
    db_user: str | None = None
    db_password: str | None = None
    db_name: str | None = None
    data_source: str = "mysql"                       # "mysql" or "csv"
    csv_path: Path = PROJECT_ROOT / "data" / "synthetic" / "transactions.csv"
    model_dir: Path = PROJECT_ROOT / "models"
    api_host: str = "127.0.0.1"
    api_port: int = 5001
    api_token: str | None = None                     # shared secret expected in X-API-Key
    allow_remote: bool = False                       # accept non-localhost clients (needs api_token)
    log_level: str = "INFO"
    extra: dict = field(default_factory=dict)

    @classmethod
    def from_env(cls) -> "Settings":
        csv_path = _env("ML_CSV_PATH")
        model_dir = _env("ML_MODEL_DIR")
        return cls(
            db_host=_env("DB_HOST"),
            db_port=int(_env("DB_PORT", "3306")),
            db_user=_env("DB_USER"),
            db_password=os.getenv("DB_PASSWORD"),    # may legitimately be empty
            db_name=_env("DB_NAME"),
            data_source=_env("ML_DATA_SOURCE", "mysql").lower(),
            csv_path=Path(csv_path) if csv_path else cls.csv_path,
            model_dir=Path(model_dir) if model_dir else cls.model_dir,
            api_host=_env("ML_API_HOST", "127.0.0.1"),
            api_port=int(_env("ML_API_PORT", "5001")),
            api_token=_env("ML_API_TOKEN"),
            allow_remote=_env("ML_API_ALLOW_REMOTE", "false").lower() == "true",
            log_level=_env("ML_LOG_LEVEL", "INFO").upper(),
        )

    @property
    def db_configured(self) -> bool:
        return bool(self.db_host and self.db_user and self.db_name and self.db_password is not None)
