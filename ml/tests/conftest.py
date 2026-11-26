"""Shared fixtures: a small synthetic dataset and models trained on it (built once per session)."""

from __future__ import annotations

import sys
from datetime import date
from pathlib import Path

import pandas as pd
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from fiscalpoint_ml.anomaly import AnomalyDetector  # noqa: E402
from fiscalpoint_ml.budget import BudgetRiskModel  # noqa: E402
from fiscalpoint_ml.categorizer import ExpenseCategorizer  # noqa: E402
from fiscalpoint_ml.data import normalize_transactions, synthetic  # noqa: E402
from fiscalpoint_ml.features import training_frame  # noqa: E402
from fiscalpoint_ml.forecasting import GlobalForecaster  # noqa: E402
from fiscalpoint_ml.registry import ModelBundle  # noqa: E402

TODAY = date(2026, 5, 18)          # fixed "today" so tests do not depend on the calendar


@pytest.fixture(scope="session")
def dataset():
    return synthetic.generate(n_users=12, n_months=20, end=TODAY, seed=7)


@pytest.fixture(scope="session")
def transactions(dataset):
    return normalize_transactions(dataset.transactions)


@pytest.fixture(scope="session")
def bundle(transactions):
    complete = transactions[transactions["date"] < "2026-05-01"]
    frame = training_frame(complete)
    forecaster = GlobalForecaster(max_iter=80).fit(frame)
    return ModelBundle(
        forecaster=forecaster,
        categorizer=ExpenseCategorizer().fit(transactions),
        anomaly=AnomalyDetector(n_estimators=100).fit(transactions),
        budget_risk=BudgetRiskModel().fit(transactions),
        metadata={"trained_at": "test", "metrics": {"forecasting": {"champion": "gradient_boosting"}}},
    )


@pytest.fixture
def tiny_transactions():
    return pd.DataFrame({
        "user_id": [1, 1, 1, 1, 1],
        "date": pd.to_datetime(["2024-12-10", "2024-12-20", "2025-02-01", "2025-03-05", "2025-03-06"]),
        "category": ["Food"] * 5,
        "amount": [12.0, 8.0, 15.0, 5.0, 5.0],
        "description": ["veggies"] * 5,
        "payment_method": ["Cash"] * 5,
    })
