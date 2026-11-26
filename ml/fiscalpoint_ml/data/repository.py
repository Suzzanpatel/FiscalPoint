"""Where transaction data comes from: the application's MySQL database or a CSV file.

The API and the training pipeline only depend on the small ``Repository`` interface, so
tests and offline experiments can swap in a CSV / in-memory source.
"""

from __future__ import annotations

import logging
from pathlib import Path
from typing import Protocol

import pandas as pd

from ..config import Settings
from .schema import normalize_transactions

logger = logging.getLogger(__name__)


class Repository(Protocol):
    def transactions(self, user_id: int | None = None) -> pd.DataFrame:
        """All transactions (normalised), optionally for a single user."""

    def budget(self, user_id: int, month: str) -> float | None:
        """Budget amount for ``YYYY-MM`` or ``None`` when no budget is set."""


class FrameRepository:
    """In-memory repository (tests, CSV files, notebooks)."""

    def __init__(self, transactions: pd.DataFrame, budgets: pd.DataFrame | None = None):
        self._tx = normalize_transactions(transactions)
        self._budgets = budgets if budgets is not None else pd.DataFrame(columns=["user_id", "month", "amount"])

    @classmethod
    def from_csv(cls, path: Path, budgets_path: Path | None = None) -> "FrameRepository":
        budgets_path = budgets_path or Path(path).with_name("budgets.csv")
        budgets = pd.read_csv(budgets_path) if budgets_path.exists() else None
        return cls(pd.read_csv(path), budgets)

    def transactions(self, user_id: int | None = None) -> pd.DataFrame:
        if user_id is None:
            return self._tx.copy()
        return self._tx[self._tx["user_id"] == int(user_id)].reset_index(drop=True)

    def budget(self, user_id: int, month: str) -> float | None:
        rows = self._budgets[(self._budgets["user_id"] == int(user_id)) & (self._budgets["month"] == month)]
        return None if rows.empty else float(rows["amount"].iloc[0])


class MySQLRepository:
    """Reads the live FiscalPoint database (``Expense`` and ``Budget`` tables)."""

    def __init__(self, settings: Settings):
        if not settings.db_configured:
            raise RuntimeError("Database settings are missing: set DB_HOST, DB_USER, DB_PASSWORD and DB_NAME in .env")
        self._settings = settings

    def _connect(self):
        import mysql.connector  # imported lazily so CSV-only use needs no driver

        return mysql.connector.connect(
            host=self._settings.db_host,
            port=self._settings.db_port,
            user=self._settings.db_user,
            password=self._settings.db_password,
            database=self._settings.db_name,
            connection_timeout=5,
        )

    def _query(self, sql: str, params: tuple = ()) -> list[dict]:
        conn = self._connect()
        try:
            cursor = conn.cursor(dictionary=True)
            cursor.execute(sql, params)
            rows = cursor.fetchall()
            cursor.close()
            return rows
        finally:
            conn.close()

    def transactions(self, user_id: int | None = None) -> pd.DataFrame:
        sql = (
            "SELECT Uid AS user_id, date, category, amount, description, Payment_Method AS payment_method "
            "FROM Expense WHERE Uid IS NOT NULL AND date IS NOT NULL"
        )
        params: tuple = ()
        if user_id is not None:
            sql += " AND Uid = %s"
            params = (int(user_id),)
        rows = self._query(sql + " ORDER BY Uid, date", params)
        frame = pd.DataFrame(rows, columns=["user_id", "date", "category", "amount", "description", "payment_method"])
        return normalize_transactions(frame)

    def budget(self, user_id: int, month: str) -> float | None:
        rows = self._query("SELECT Amount FROM Budget WHERE Uid = %s AND Month = %s", (int(user_id), month))
        return float(rows[0]["Amount"]) if rows else None

    def ping(self) -> bool:
        try:
            self._query("SELECT 1 AS ok")
            return True
        except Exception:  # pragma: no cover - depends on a live server
            logger.exception("Database ping failed")
            return False


def make_repository(settings: Settings) -> Repository:
    """Build the repository selected by ``ML_DATA_SOURCE`` (``mysql`` or ``csv``)."""
    if settings.data_source == "csv":
        logger.info("Using CSV data source %s", settings.csv_path)
        return FrameRepository.from_csv(settings.csv_path)
    return MySQLRepository(settings)
