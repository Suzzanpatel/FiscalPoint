"""The canonical transaction table used by every model.

Whatever the source (MySQL ``Expense`` table, CSV export, synthetic generator), data is
normalised to these columns before it reaches feature engineering:

=============== ============== ==============================================
column          dtype          meaning
=============== ============== ==============================================
user_id         int64          owner (``Expense.Uid``)
date            datetime64     transaction date
category        str            expense category
amount          float64        amount in rupees (> 0)
description     str            free-text description entered by the user
payment_method  str            Cash / UPI Payment / Credit / Debit card / ...
=============== ============== ==============================================
"""

from __future__ import annotations

import pandas as pd

TRANSACTION_COLUMNS = ["user_id", "date", "category", "amount", "description", "payment_method"]

_ALIASES = {
    "uid": "user_id",
    "Uid": "user_id",
    "Category": "category",
    "Amount": "amount",
    "Date": "date",
    "Description": "description",
    "Payment_Method": "payment_method",
    "payment_mode": "payment_method",
}


def normalize_transactions(frame: pd.DataFrame) -> pd.DataFrame:
    """Rename, type-cast and clean a raw transaction frame.

    Rows without a user, date, category or a strictly positive amount are dropped:
    the legacy database contained such rows (NULL ``Uid``, zero amounts) and they would
    otherwise distort both training and evaluation.
    """
    df = frame.rename(columns={k: v for k, v in _ALIASES.items() if k in frame.columns}).copy()
    for column in ("description", "payment_method"):
        if column not in df.columns:
            df[column] = ""

    missing = {"user_id", "date", "category", "amount"} - set(df.columns)
    if missing:
        raise ValueError(f"transaction data is missing columns: {sorted(missing)}")

    df["date"] = pd.to_datetime(df["date"], errors="coerce")
    df["amount"] = pd.to_numeric(df["amount"], errors="coerce")
    df["user_id"] = pd.to_numeric(df["user_id"], errors="coerce")
    df["category"] = df["category"].astype("string").str.strip()
    df = df.dropna(subset=["user_id", "date", "category", "amount"])
    df = df[(df["amount"] > 0) & (df["category"] != "")]

    df["user_id"] = df["user_id"].astype("int64")
    df["category"] = df["category"].astype(str)
    df["description"] = df["description"].fillna("").astype(str).str.strip()
    df["payment_method"] = df["payment_method"].fillna("").astype(str).str.strip()
    extra = [c for c in df.columns if c not in TRANSACTION_COLUMNS]
    return df[TRANSACTION_COLUMNS + extra].sort_values(["user_id", "date"]).reset_index(drop=True)
