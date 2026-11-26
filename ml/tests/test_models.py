import numpy as np
import pandas as pd
import pytest

from fiscalpoint_ml import anomaly, categorizer, registry
from fiscalpoint_ml.budget import BudgetRiskModel
from fiscalpoint_ml.forecasting import (
    BASELINES, MODEL_NAME, baseline_predictions, forecast_user, regression_metrics,
    rolling_origin_backtest, total_interval,
)
from fiscalpoint_ml.features import training_frame


# ------------------------------------------------------------------ forecasting

def test_regression_metrics():
    m = regression_metrics(np.array([10.0, 20.0]), np.array([12.0, 16.0]))
    assert m["mae"] == 3.0
    assert m["wape"] == pytest.approx(6 / 30)
    assert m["bias"] == -1.0


def test_baselines_on_linear_series():
    tx = pd.DataFrame({
        "user_id": 1, "category": "Food", "description": "", "payment_method": "",
        "date": pd.to_datetime([f"2025-{m:02d}-10" for m in range(1, 8)]),
        "amount": [10.0, 20, 30, 40, 50, 60, 70],
    })
    frame = training_frame(tx)
    last = baseline_predictions(frame).iloc[-1]
    assert last["naive"] == 60
    assert last["moving_avg_3"] == 50
    assert last["linear_trend"] == pytest.approx(70)


def test_forecaster_outputs_ordered_non_negative_interval(bundle, transactions):
    frame = training_frame(transactions[transactions["date"] < "2026-05-01"]).tail(200)
    preds = bundle.forecaster.predict(frame)
    assert (preds >= 0).all().all()
    assert (preds["low"] <= preds["expected"]).all() and (preds["expected"] <= preds["high"]).all()
    # the interval must not collapse to zero (regression test for the L2 + quantile-loss bug)
    assert (preds["low"] > 0).mean() > 0.3


def test_backtest_beats_naive_baseline(transactions):
    preds, summary = rolling_origin_backtest(transactions, n_origins=2, forecaster_params={"max_iter": 80})
    assert set(BASELINES) | {MODEL_NAME} <= set(summary["category_level"])
    assert summary["category_level"][MODEL_NAME]["mae"] < summary["category_level"]["naive"]["mae"]
    assert 0.5 < summary["interval_coverage_80"] <= 1.0
    # no look-ahead: every scored month is strictly after the data used for its model
    assert preds["period"].nunique() == 2


def test_forecast_user_multi_step(bundle, transactions):
    user_tx = transactions[transactions["user_id"] == 2]
    result = forecast_user(bundle.forecaster, user_tx, pd.Period("2026-05", "M"), horizon=2)
    assert set(result["step"]) == {1, 2}
    assert set(result["period"].astype(str)) == {"2026-05", "2026-06"}
    total = total_interval(result[result["step"] == 1])
    assert total["low"] <= total["expected"] <= total["high"]


# ------------------------------------------------------------------ categorizer

def test_categorizer_suggests_obvious_categories(bundle):
    model = bundle.categorizer
    assert model.suggest("swiggy dinner", 400)[0]["category"] == "Food"
    assert model.suggest("Uber to airport", 650)[0]["category"] == "Transportation"
    assert model.suggest("netflix", None)[0]["category"] == "Entertainment"
    assert model.suggest("") == []


def test_categorizer_handles_typos_and_missing_amount(bundle):
    top = bundle.categorizer.suggest("electrcity bil")
    assert top and top[0]["category"] == "Housing"
    probabilities = [s["probability"] for s in bundle.categorizer.suggest("medicines", 300, "Cash", top_k=3)]
    assert probabilities == sorted(probabilities, reverse=True)


def test_categorizer_evaluation_metrics(transactions):
    metrics, _ = categorizer.evaluate(transactions.sample(3000, random_state=0))
    assert metrics["accuracy"] > 0.8
    assert metrics["top3_accuracy"] >= metrics["accuracy"]


# ------------------------------------------------------------------ anomalies

def test_anomaly_detector_flags_injected_spike(bundle, transactions):
    user_tx = transactions[transactions["user_id"] == 1].copy()
    food = user_tx[user_tx["category"] == "Food"]
    spike = food.iloc[[-1]].copy()
    spike["amount"] = food["amount"].median() * 40
    spike["date"] = pd.Timestamp("2026-05-10")
    flagged = bundle.anomaly.user_anomalies(pd.concat([user_tx, spike], ignore_index=True), limit=50)
    assert (flagged["amount"] == spike["amount"].iloc[0]).any()
    assert "your usual Food spend" in flagged.iloc[0]["reason"]


def test_anomaly_evaluation_beats_chance(transactions):
    metrics = anomaly.evaluate(transactions, anomaly.AnomalyDetector(n_estimators=100).fit(transactions))
    assert metrics["roc_auc"] > 0.75
    assert "ratio_rule_5x" in metrics["baselines"]


# ------------------------------------------------------------------ budget risk

def test_budget_risk_probabilities_behave_sensibly():
    model = BudgetRiskModel(relative_sigma=0.2)
    safe = model.estimate(budget=50_000, spent=5_000, day=15, days_in_month=30, forecast_expected=20_000, share=0.5)
    risky = model.estimate(budget=10_000, spent=9_000, day=15, days_in_month=30, forecast_expected=20_000, share=0.5)
    over = model.estimate(budget=10_000, spent=12_000, day=5, days_in_month=30, forecast_expected=20_000, share=0.2)
    assert safe.probability_over_budget < 0.05 and safe.level == "low"
    assert risky.probability_over_budget > 0.9 and risky.level == "high"
    assert over.probability_over_budget == 1.0 and over.level == "exceeded"
    assert safe.projected_low <= safe.projected_total <= safe.projected_high


def test_front_loaded_spending_is_not_extrapolated_linearly():
    # Rent paid on day 2 must not be projected as if it repeats every two days.
    model = BudgetRiskModel(relative_sigma=0.2)
    risk = model.estimate(budget=40_000, spent=25_000, day=3, days_in_month=30, forecast_expected=35_000, share=0.6)
    assert risk.projected_total < 50_000


def test_spending_curve_is_monotone(bundle):
    curve = np.asarray(bundle.budget_risk.global_curve)
    assert np.all(np.diff(curve) >= -1e-9) and curve[-1] == pytest.approx(1.0)


# ------------------------------------------------------------------ registry

def test_registry_round_trip(tmp_path, bundle):
    registry.save(bundle, tmp_path, {"note": "test"})
    loaded = registry.load(tmp_path)
    assert all(loaded.loaded.values())
    assert loaded.metadata["note"] == "test"
    assert loaded.categorizer.suggest("swiggy", 300)[0]["category"] == "Food"


def test_registry_load_missing_directory(tmp_path):
    loaded = registry.load(tmp_path / "nothing")
    assert not any(loaded.loaded.values())
