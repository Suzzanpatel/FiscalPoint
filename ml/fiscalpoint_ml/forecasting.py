"""Monthly category-spend forecasting.

Models
------
Baselines (no training, computed from the lag features):
    naive            last month's value
    moving_avg_3     mean of the last 3 months
    seasonal_naive   same month last year (falls back to last month)
    linear_trend     least-squares trend over the last <= 12 months
Learned model:
    GlobalForecaster gradient-boosted trees (scikit-learn HistGradientBoostingRegressor)
                     trained on every user's history at once ("global" model), so a
                     user with only a few months still benefits from patterns learned on
                     everyone else. Three models are fitted:
                       expected  Poisson loss on the raw amount -> estimates the *mean*.
                                 Means add up, so category forecasts sum to an unbiased
                                 monthly total. (A median model scored slightly better
                                 per category but under-forecast totals by ~17 %, because
                                 spending is right-skewed.)
                       low/high  10th / 90th percentile (quantile loss on log1p amount),
                                 forming an 80 % prediction interval.

Evaluation
----------
:func:`rolling_origin_backtest` repeatedly trains on all months before an origin month
and predicts that month, for the last ``n_origins`` months. Every model is scored on
exactly the same cells, so the comparison is fair and contains no look-ahead.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field

import numpy as np
import pandas as pd
from sklearn.ensemble import HistGradientBoostingRegressor

from .features import FEATURE_COLUMNS, inference_frame, training_frame

logger = logging.getLogger(__name__)

BASELINES = ("naive", "moving_avg_3", "seasonal_naive", "linear_trend")
MODEL_NAME = "gradient_boosting"


def baseline_predictions(frame: pd.DataFrame) -> pd.DataFrame:
    """Predictions of every baseline for the rows of a feature frame."""
    return pd.DataFrame({
        "naive": frame["lag_1"],
        "moving_avg_3": frame["roll_mean_3"],
        "seasonal_naive": frame["lag_12"].fillna(frame["lag_1"]),
        "linear_trend": frame["trend_12"].fillna(frame["roll_mean_3"]),
    }, index=frame.index).fillna(0.0).clip(lower=0.0)


@dataclass
class GlobalForecaster:
    """Gradient-boosted global model with an 80 % prediction interval."""

    # Chosen by comparing a few settings in the rolling-origin backtest on two datasets
    # (see docs/ML_PIPELINE.md, "Hyper-parameters"): smaller, more regularised trees were the
    # most robust choice.
    max_iter: int = 250
    learning_rate: float = 0.05
    max_leaf_nodes: int = 15
    min_samples_leaf: int = 40
    l2_regularization: float = 1.0
    random_state: int = 0
    quantiles: tuple[float, float] = (0.1, 0.9)
    models_: dict = field(default_factory=dict, repr=False)
    trained_through_: str | None = None
    n_training_rows_: int = 0

    def _model(self, loss: str, quantile: float | None = None) -> HistGradientBoostingRegressor:
        params = dict(
            loss=loss,
            max_iter=self.max_iter,
            learning_rate=self.learning_rate,
            max_leaf_nodes=self.max_leaf_nodes,
            min_samples_leaf=self.min_samples_leaf,
            l2_regularization=self.l2_regularization,
            categorical_features=[FEATURE_COLUMNS.index("category_code")],
            early_stopping=False,
            random_state=self.random_state,
        )
        if quantile is not None:
            # No L2 penalty for quantile loss: its gradients are only +/-q, so the leaf values
            # are tiny and L2 shrinks them to ~0 (the model then predicts 0 for every row).
            params.update(quantile=quantile, l2_regularization=0.0)
        return HistGradientBoostingRegressor(**params)

    def fit(self, frame: pd.DataFrame) -> "GlobalForecaster":
        if frame.empty:
            raise ValueError("cannot fit a forecaster on an empty training frame")
        X = frame[FEATURE_COLUMNS].to_numpy(dtype=float)
        amount = frame["amount"].to_numpy(dtype=float)
        log_amount = np.log1p(amount)
        low, high = self.quantiles
        self.models_ = {
            "expected": self._model("poisson").fit(X, amount),
            "low": self._model("quantile", low).fit(X, log_amount),
            "high": self._model("quantile", high).fit(X, log_amount),
        }
        self.trained_through_ = str(frame["period"].max())
        self.n_training_rows_ = len(frame)
        return self

    def predict(self, frame: pd.DataFrame) -> pd.DataFrame:
        """Return ``low`` / ``expected`` / ``high`` (rupees, non-negative, low <= expected <= high)."""
        if not self.models_:
            raise RuntimeError("forecaster is not fitted")
        if frame.empty:
            return pd.DataFrame(columns=["low", "expected", "high"])
        X = frame[FEATURE_COLUMNS].to_numpy(dtype=float)
        expected = np.clip(self.models_["expected"].predict(X), 0.0, None)
        low = np.clip(np.expm1(self.models_["low"].predict(X)), 0.0, None)
        high = np.clip(np.expm1(self.models_["high"].predict(X)), 0.0, None)
        low, high = np.minimum(low, high), np.maximum(low, high)
        return pd.DataFrame({
            "low": np.minimum(low, expected),
            "expected": expected,
            "high": np.maximum(high, expected),
        }, index=frame.index)

    def feature_importance(self, frame: pd.DataFrame, n_repeats: int = 3) -> pd.Series:
        """Permutation importance of the expected-value model (MAE increase when a feature is shuffled)."""
        from sklearn.inspection import permutation_importance

        X = frame[FEATURE_COLUMNS].to_numpy(dtype=float)
        y = frame["amount"].to_numpy(dtype=float)
        result = permutation_importance(self.models_["expected"], X, y, n_repeats=n_repeats,
                                        random_state=self.random_state, scoring="neg_mean_absolute_error")
        return pd.Series(result.importances_mean, index=FEATURE_COLUMNS).sort_values(ascending=False)


# ----------------------------------------------------------------------------- metrics

def regression_metrics(actual: np.ndarray, predicted: np.ndarray) -> dict:
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)
    errors = predicted - actual
    total = np.abs(actual).sum()
    return {
        "mae": float(np.mean(np.abs(errors))),
        "rmse": float(np.sqrt(np.mean(errors ** 2))),
        "wape": float(np.abs(errors).sum() / total) if total > 0 else None,   # weighted absolute % error
        "bias": float(np.mean(errors)),
        "n": int(len(actual)),
    }


def summarise_backtest(predictions: pd.DataFrame) -> dict:
    """Metrics per model at the category level and at the user-total level."""
    models = [MODEL_NAME, *BASELINES]
    category_level = {m: regression_metrics(predictions["amount"], predictions[m]) for m in models}
    totals = predictions.groupby(["user_id", "period"])[["amount", *models]].sum()
    user_level = {m: regression_metrics(totals["amount"], totals[m]) for m in models}
    inside = (predictions["amount"] >= predictions["low"]) & (predictions["amount"] <= predictions["high"])
    best_baseline = min(BASELINES, key=lambda m: category_level[m]["mae"])
    improvement = 1 - category_level[MODEL_NAME]["mae"] / category_level[best_baseline]["mae"]
    champion = MODEL_NAME if category_level[MODEL_NAME]["mae"] < category_level[best_baseline]["mae"] else best_baseline
    per_origin = (
        predictions.assign(abs_err=lambda d: (d[MODEL_NAME] - d["amount"]).abs(),
                           abs_err_base=lambda d: (d[best_baseline] - d["amount"]).abs())
        .groupby("period")[["abs_err", "abs_err_base"]].mean()
        .rename(columns={"abs_err": MODEL_NAME, "abs_err_base": best_baseline})
    )
    return {
        "category_level": category_level,
        "user_total_level": user_level,
        "interval_coverage_80": float(inside.mean()),
        "best_baseline": best_baseline,
        "mae_improvement_vs_best_baseline": float(improvement),
        "champion": champion,
        "mae_by_origin": {str(k): {m: float(v) for m, v in row.items()} for k, row in per_origin.iterrows()},
    }


def rolling_origin_backtest(transactions: pd.DataFrame, n_origins: int = 6,
                            forecaster_params: dict | None = None) -> tuple[pd.DataFrame, dict]:
    """Expanding-window, one-month-ahead backtest over the last ``n_origins`` complete months.

    Returns ``(predictions, summary)`` where predictions has one row per scored cell with the
    actual amount, the model's low/expected/high and every baseline's prediction.
    """
    frame = training_frame(transactions)
    last_complete = _last_complete_period(transactions)
    frame = frame[frame["period"] <= last_complete]
    origins = sorted(frame["period"].unique())[-n_origins:]
    results = []
    for origin in origins:
        train = frame[frame["period"] < origin]
        test = frame[frame["period"] == origin]
        if train.empty or test.empty:
            continue
        model = GlobalForecaster(**(forecaster_params or {})).fit(train)
        preds = model.predict(test)
        out = test[["user_id", "category", "period", "amount"]].copy()
        out[["low", MODEL_NAME, "high"]] = preds[["low", "expected", "high"]].to_numpy()
        out[list(BASELINES)] = baseline_predictions(test).to_numpy()
        results.append(out)
        logger.info("backtest origin %s: %d train rows, %d test rows", origin, len(train), len(test))
    if not results:
        raise ValueError("not enough history for a backtest (need at least two complete months)")
    predictions = pd.concat(results, ignore_index=True)
    return predictions, summarise_backtest(predictions)


def _last_complete_period(transactions: pd.DataFrame, today: pd.Timestamp | None = None) -> pd.Period:
    """The most recent month that is fully in the past (relative to ``today``, default now)."""
    from .config import today as app_today

    today = today or app_today()
    current = today.to_period("M")
    latest = transactions["date"].max().to_period("M")
    return min(latest, current - 1)


# ----------------------------------------------------------------------------- inference

def forecast_user(model: GlobalForecaster, transactions: pd.DataFrame, start_period: pd.Period,
                  horizon: int = 2, inactive_months: int = 6) -> pd.DataFrame:
    """Forecast ``horizon`` months for one user, starting at ``start_period``.

    Multi-step forecasts are recursive: the expected value for month t is appended to
    the history as a pseudo-observation before forecasting t+1. Categories with no spending
    in the last ``inactive_months`` months are forecast as 0.
    """
    history = transactions[transactions["date"].dt.to_period("M") < start_period].copy()
    outputs = []
    for step in range(horizon):
        target = start_period + step
        rows = inference_frame(history, target)
        if rows.empty:
            break
        preds = model.predict(rows)
        result = rows[["user_id", "category", "period", "lag_1", "roll_mean_3", "roll_mean_6", "months_of_history"]].copy()
        result[["low", "expected", "high"]] = preds[["low", "expected", "high"]].to_numpy()
        if inactive_months:
            window = [f"lag_{k}" for k in (1, 2, 3, 6) if k <= inactive_months]
            dormant = (rows["roll_max_6"].fillna(0) <= 0) & (rows[window].fillna(0).sum(axis=1) <= 0)
            result.loc[dormant, ["low", "expected", "high"]] = 0.0
        result["step"] = step + 1
        outputs.append(result)
        # pseudo-transactions (one per category) carrying the expected value forward
        pseudo = result.loc[result["expected"] > 0, ["user_id", "category", "expected"]].rename(columns={"expected": "amount"})
        pseudo["date"] = target.to_timestamp()
        pseudo["description"] = ""
        pseudo["payment_method"] = ""
        history = pd.concat([history, pseudo[history.columns.intersection(pseudo.columns)]], ignore_index=True)
    return pd.concat(outputs, ignore_index=True) if outputs else pd.DataFrame()


def total_interval(category_forecasts: pd.DataFrame) -> dict:
    """Aggregate category forecasts into a monthly total with an approximate 80 % interval.

    Category errors are treated as independent, so half-widths combine in quadrature
    (summing the category low/high values directly would assume perfect correlation and
    give an interval that is far too wide).
    """
    expected = float(category_forecasts["expected"].sum())
    low = np.sqrt(((category_forecasts["expected"] - category_forecasts["low"]) ** 2).sum())
    high = np.sqrt(((category_forecasts["high"] - category_forecasts["expected"]) ** 2).sum())
    return {"low": max(0.0, expected - float(low)), "expected": expected, "high": expected + float(high)}
