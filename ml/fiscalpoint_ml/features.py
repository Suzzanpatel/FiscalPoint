"""Monthly panel construction and leakage-free feature engineering for forecasting.

Unit of prediction: one (user, category, month) cell — "how much will this user spend
on this category in month t?". Every feature for month t is computed only from months
strictly before t (``shift(1)`` before any rolling window), so the same function can be
used for training, rolling-origin backtesting and live inference without leakage.
"""

from __future__ import annotations

import numpy as np
import pandas as pd

from .config import CATEGORIES

KEY = ["user_id", "category"]

#: Numeric feature columns fed to the gradient-boosting model (``category_code`` is categorical).
FEATURE_COLUMNS = [
    "category_code",
    "lag_1", "lag_2", "lag_3", "lag_6", "lag_12",
    "roll_mean_3", "roll_mean_6", "roll_std_6", "roll_max_6",
    "nonzero_frac_6", "expanding_mean", "trend_12",
    "months_of_history",
    "user_total_lag_1", "user_total_mean_3", "share_lag_1",
    "month", "month_sin", "month_cos",
]

_CATEGORY_INDEX = {name: i for i, name in enumerate(CATEGORIES)}
OTHER_CATEGORY_CODE = len(CATEGORIES)   # any custom user-defined category


def category_code(category: pd.Series) -> pd.Series:
    return category.map(_CATEGORY_INDEX).fillna(OTHER_CATEGORY_CODE).astype("int64")


def monthly_panel(transactions: pd.DataFrame, end_period: pd.Period | None = None) -> pd.DataFrame:
    """Aggregate transactions into a dense (user, category, month) panel.

    For every user, each category they have ever used gets one row per month from the
    user's first month up to ``end_period`` (default: the last month with data). Months
    without spending are explicit zeros — "spent nothing" is information, not missing data.
    """
    if transactions.empty:
        return pd.DataFrame(columns=KEY + ["period", "amount"])

    tx = transactions.assign(period=transactions["date"].dt.to_period("M"))
    totals = tx.groupby(KEY + ["period"], observed=True)["amount"].sum()
    end_period = end_period or tx["period"].max()

    frames = []
    for user_id, user_tx in tx.groupby("user_id"):
        periods = pd.period_range(user_tx["period"].min(), end_period, freq="M")
        if len(periods) == 0:
            continue
        index = pd.MultiIndex.from_product(
            [[user_id], sorted(user_tx["category"].unique()), periods], names=KEY + ["period"]
        )
        frames.append(totals.reindex(index, fill_value=0.0))
    panel = pd.concat(frames).rename("amount").reset_index()
    return panel.sort_values(KEY + ["period"]).reset_index(drop=True)


def _trend_forecast(window: np.ndarray) -> float:
    """One-step-ahead linear-trend extrapolation from the values in ``window``."""
    values = window[~np.isnan(window)]
    n = len(values)
    if n < 3:
        return np.nan
    x = np.arange(n, dtype=float)
    slope, intercept = np.polyfit(x, values, 1)
    return max(0.0, slope * n + intercept)


def add_features(panel: pd.DataFrame) -> pd.DataFrame:
    """Return ``panel`` with all :data:`FEATURE_COLUMNS` added (rows with no history keep NaNs)."""
    df = panel.sort_values(KEY + ["period"]).reset_index(drop=True).copy()
    grouped = df.groupby(KEY, sort=False)["amount"]
    past = grouped.shift(1)                       # value of the previous month, never the current one
    df["_past"] = past
    past_groups = df.groupby(KEY, sort=False)["_past"]

    for lag in (1, 2, 3, 6, 12):
        df[f"lag_{lag}"] = grouped.shift(lag)
    df["roll_mean_3"] = past_groups.transform(lambda s: s.rolling(3, min_periods=1).mean())
    df["roll_mean_6"] = past_groups.transform(lambda s: s.rolling(6, min_periods=1).mean())
    df["roll_std_6"] = past_groups.transform(lambda s: s.rolling(6, min_periods=2).std())
    df["roll_max_6"] = past_groups.transform(lambda s: s.rolling(6, min_periods=1).max())
    df["nonzero_frac_6"] = past_groups.transform(lambda s: (s > 0).astype(float).where(s.notna()).rolling(6, min_periods=1).mean())
    df["expanding_mean"] = past_groups.transform(lambda s: s.expanding().mean())
    df["trend_12"] = past_groups.transform(lambda s: s.rolling(12, min_periods=3).apply(_trend_forecast, raw=True))
    df["months_of_history"] = df.groupby(KEY, sort=False).cumcount()

    # User-level context: overall spending of the user in previous months
    user_totals = df.groupby(["user_id", "period"], sort=False)["amount"].sum().rename("user_total").reset_index()
    user_totals = user_totals.sort_values(["user_id", "period"])
    user_past = user_totals.groupby("user_id")["user_total"].shift(1)
    user_totals["user_total_lag_1"] = user_past
    user_totals["user_total_mean_3"] = user_past.groupby(user_totals["user_id"]).transform(
        lambda s: s.rolling(3, min_periods=1).mean()
    )
    df = df.merge(user_totals[["user_id", "period", "user_total_lag_1", "user_total_mean_3"]],
                  on=["user_id", "period"], how="left")
    df["share_lag_1"] = np.where(df["user_total_lag_1"] > 0, df["lag_1"] / df["user_total_lag_1"], np.nan)

    month = df["period"].dt.month
    df["month"] = month
    df["month_sin"] = np.sin(2 * np.pi * month / 12)
    df["month_cos"] = np.cos(2 * np.pi * month / 12)
    df["category_code"] = category_code(df["category"])
    return df.drop(columns="_past").sort_values(KEY + ["period"]).reset_index(drop=True)


def training_frame(transactions: pd.DataFrame, end_period: pd.Period | None = None) -> pd.DataFrame:
    """Feature table for model fitting: every panel cell that has at least one month of history."""
    features = add_features(monthly_panel(transactions, end_period))
    return features[features["months_of_history"] >= 1].reset_index(drop=True)


def inference_frame(transactions: pd.DataFrame, target_period: pd.Period) -> pd.DataFrame:
    """Feature rows for ``target_period`` built from all complete months before it.

    Transactions on or after ``target_period`` are ignored, so a partially elapsed current
    month never leaks into its own forecast.
    """
    history = transactions[transactions["date"].dt.to_period("M") < target_period]
    if history.empty:
        return pd.DataFrame(columns=KEY + ["period"] + FEATURE_COLUMNS)
    panel = monthly_panel(history, end_period=target_period)
    panel.loc[panel["period"] == target_period, "amount"] = np.nan   # unknown future value
    features = add_features(panel)
    return features[features["period"] == target_period].reset_index(drop=True)
