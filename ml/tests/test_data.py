import pandas as pd
import pytest

from fiscalpoint_ml.config import CATEGORIES
from fiscalpoint_ml.data import normalize_transactions, synthetic
from fiscalpoint_ml.data.repository import FrameRepository


def test_normalize_drops_invalid_rows_from_legacy_dump():
    raw = pd.DataFrame({
        "Uid": [None, 12, 12, 22],
        "Category": ["Housing", "Housing", "Food", ""],
        "Amount": [100, 0, 50.5, 10],
        "Date": ["2025-03-07", "2025-03-10", "2025-03-11", "2025-03-12"],
        "Description": ["veggies", "x", "burger", "bus"],
        "Payment_Method": ["", "Cash", "Cash", "Cash"],
    })
    clean = normalize_transactions(raw)
    # NULL user, zero amount and empty category are removed
    assert clean[["user_id", "category", "amount"]].to_dict("records") == [
        {"user_id": 12, "category": "Food", "amount": 50.5}
    ]


def test_normalize_requires_core_columns():
    with pytest.raises(ValueError):
        normalize_transactions(pd.DataFrame({"amount": [1.0]}))


def test_synthetic_is_reproducible_and_valid():
    a = synthetic.generate(n_users=3, n_months=6, seed=1).transactions
    b = synthetic.generate(n_users=3, n_months=6, seed=1).transactions
    pd.testing.assert_frame_equal(a, b)
    assert set(a["category"]) <= set(CATEGORIES)
    assert (a["amount"] > 0).all()
    assert a["is_anomaly"].dtype == bool


def test_synthetic_last_month_is_partial(dataset):
    tx = dataset.transactions
    assert tx["date"].max() <= pd.Timestamp("2026-05-18")


def test_frame_repository_filters_user_and_budget(transactions, dataset):
    repo = FrameRepository(transactions, dataset.budgets)
    user_tx = repo.transactions(3)
    assert set(user_tx["user_id"]) == {3}
    month = dataset.budgets[dataset.budgets["user_id"] == 3]["month"].iloc[-1]
    assert repo.budget(3, month) > 0
    assert repo.budget(3, "1999-01") is None
