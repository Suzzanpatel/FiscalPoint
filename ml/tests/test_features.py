import numpy as np
import pandas as pd

from fiscalpoint_ml.features import FEATURE_COLUMNS, add_features, inference_frame, monthly_panel, training_frame


def test_panel_fills_missing_month_across_year_boundary(tiny_transactions):
    panel = monthly_panel(tiny_transactions)
    assert panel["period"].astype(str).tolist() == ["2024-12", "2025-01", "2025-02", "2025-03"]
    assert panel["amount"].tolist() == [20.0, 0.0, 15.0, 10.0]


def test_features_only_use_past_months(tiny_transactions):
    features = add_features(monthly_panel(tiny_transactions))
    features = features.set_index(features["period"].astype(str))
    assert list(features.index) == ["2024-12", "2025-01", "2025-02", "2025-03"]
    assert np.isnan(features.loc["2024-12", "lag_1"])
    assert features.loc["2025-02", "lag_1"] == 0.0           # January (zero) not February itself
    assert features.loc["2025-03", "lag_1"] == 15.0
    assert features.loc["2025-03", "roll_mean_3"] == (20 + 0 + 15) / 3
    assert features.loc["2025-03", "months_of_history"] == 3


def test_changing_target_month_does_not_change_its_features(tiny_transactions):
    altered = tiny_transactions.copy()
    altered.loc[altered["date"] >= "2025-03-01", "amount"] = 9999.0
    a = add_features(monthly_panel(tiny_transactions))
    b = add_features(monthly_panel(altered))
    march_a = a[a["period"] == pd.Period("2025-03", "M")][FEATURE_COLUMNS]
    march_b = b[b["period"] == pd.Period("2025-03", "M")][FEATURE_COLUMNS]
    pd.testing.assert_frame_equal(march_a.reset_index(drop=True), march_b.reset_index(drop=True))


def test_inference_frame_ignores_partial_target_month(tiny_transactions):
    rows = inference_frame(tiny_transactions, pd.Period("2025-03", "M"))
    assert len(rows) == 1
    assert rows["lag_1"].iloc[0] == 15.0
    assert rows["period"].iloc[0] == pd.Period("2025-03", "M")


def test_training_frame_skips_rows_without_history(tiny_transactions):
    frame = training_frame(tiny_transactions)
    assert (frame["months_of_history"] >= 1).all()
    assert len(frame) == 3


def test_unknown_category_gets_other_code(tiny_transactions):
    custom = tiny_transactions.assign(category="Pet supplies")
    frame = add_features(monthly_panel(custom))
    assert (frame["category_code"] == 13).all()
