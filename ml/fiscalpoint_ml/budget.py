"""Probability of exceeding this month's budget.

Inputs: the forecast for the month (made before it started), the spending so far and the
day of the month. Two things are *learned* from history rather than assumed:

1. **Within-month spending curve** ``F(d)``: the fraction of a month's spending that is
   typically done by day ``d``. Rent, EMIs and SIPs land in the first week, so a naive
   "spent so far x 30 / day" projection grossly over-estimates early in the month (the
   first version of this module did exactly that and was badly over-confident). A global
   curve is learned from all users and blended with the user's own curve when they have
   enough history.
2. **Forecast error scale**: the relative standard deviation of monthly-total forecast
   errors, measured on the rolling-origin backtest. Summing category intervals assuming
   independence under-states the uncertainty of the total, so the empirical value is used.

Estimate of the month-end total::

    pace      = spent / F(d)                      # what the curve implies
    projected = F(d) * pace + (1 - F(d)) * max(forecast, spent)
    sigma     = relative_sigma * forecast * (1 - F(d))
    P(over)   = 1 - Phi((budget - projected) / sigma)

:func:`BudgetRiskModel.backtest` checks calibration on historical months at several
checkpoints (Brier score vs. a no-skill baseline, ROC-AUC, reliability table).
"""

from __future__ import annotations

import calendar
from dataclasses import dataclass, field

import numpy as np
import pandas as pd
from scipy.stats import norm
from sklearn.metrics import brier_score_loss, roc_auc_score

_Z80 = float(norm.ppf(0.9))
_DAYS = 31


def spending_curve(transactions: pd.DataFrame, min_months: int = 1) -> np.ndarray | None:
    """Average cumulative share of monthly spending by day 1..31 over complete months."""
    if transactions.empty:
        return None
    tx = transactions.assign(period=transactions["date"].dt.to_period("M"), day=transactions["date"].dt.day)
    from .config import today

    current = today().to_period("M")
    tx = tx[tx["period"] < current]
    daily = tx.groupby(["user_id", "period", "day"])["amount"].sum().unstack("day", fill_value=0.0)
    daily = daily.reindex(columns=range(1, _DAYS + 1), fill_value=0.0)
    totals = daily.sum(axis=1)
    daily = daily[totals > 0]
    if len(daily) < min_months:
        return None
    cumulative = daily.cumsum(axis=1).div(daily.sum(axis=1), axis=0)
    return cumulative.mean(axis=0).to_numpy()


@dataclass
class BudgetRisk:
    month: str
    budget: float
    spent_to_date: float
    days_elapsed: int
    days_in_month: int
    typical_share_spent: float
    forecast_expected: float
    projected_total: float
    projected_low: float
    projected_high: float
    probability_over_budget: float

    @property
    def level(self) -> str:
        if self.spent_to_date > self.budget:
            return "exceeded"
        if self.probability_over_budget >= 0.6:
            return "high"
        if self.probability_over_budget >= 0.3:
            return "medium"
        return "low"

    def to_dict(self) -> dict:
        data = {k: (round(v, 4) if isinstance(v, float) else v) for k, v in self.__dict__.items()}
        data["risk_level"] = self.level
        data["remaining_budget"] = round(self.budget - self.spent_to_date, 2)
        return data


@dataclass
class BudgetRiskModel:
    relative_sigma: float = 0.25
    global_curve: list[float] = field(default_factory=lambda: list(np.linspace(1 / _DAYS, 1.0, _DAYS)))
    user_curve_months: int = 4          # months of history before a user's own curve is trusted

    # ------------------------------------------------------------------ fitting
    def fit(self, transactions: pd.DataFrame, backtest_predictions: pd.DataFrame | None = None) -> "BudgetRiskModel":
        curve = spending_curve(transactions)
        if curve is not None:
            self.global_curve = [float(v) for v in curve]
        if backtest_predictions is not None and not backtest_predictions.empty:
            totals = backtest_predictions.groupby(["user_id", "period"])[["amount", "gradient_boosting"]].sum()
            totals = totals[totals["gradient_boosting"] > 0]
            relative_errors = (totals["amount"] - totals["gradient_boosting"]) / totals["gradient_boosting"]
            # Spread from the empirical 10th-90th percentile range: robust to a few extreme
            # months but, unlike the MAD, it reflects the heavy tails of spending errors
            # (a MAD-based value made mid-month probabilities over-confident).
            q10, q90 = np.quantile(relative_errors, [0.1, 0.9])
            self.relative_sigma = float(np.clip((q90 - q10) / (2 * _Z80), 0.05, 1.0))
        return self

    def share_spent(self, day: int, days_in_month: int, user_transactions: pd.DataFrame | None = None) -> float:
        """Typical fraction of the month's spending done by ``day`` (0 before the month, 1 after)."""
        if day <= 0:
            return 0.0
        if day >= days_in_month:
            return 1.0
        curve = np.asarray(self.global_curve)
        if user_transactions is not None and not user_transactions.empty:
            months = user_transactions["date"].dt.to_period("M").nunique()
            user = spending_curve(user_transactions)
            if user is not None and months >= self.user_curve_months:
                weight = min(1.0, months / 12)
                curve = weight * user + (1 - weight) * curve
        # rescale so the last day of *this* month maps to 1 (months shorter than 31 days)
        return float(np.clip(curve[day - 1] / curve[days_in_month - 1], 0.0, 1.0))

    # ---------------------------------------------------------------- inference
    def estimate(self, budget: float, spent: float, day: int, days_in_month: int,
                 forecast_expected: float, share: float) -> BudgetRisk:
        share = float(np.clip(share, 0.0, 1.0))
        pace = spent / share if share > 0.02 else forecast_expected
        forecast_part = max(forecast_expected, spent)
        projected = share * pace + (1 - share) * forecast_part
        projected = max(projected, spent)
        sigma = self.relative_sigma * max(forecast_expected, projected) * (1 - share)
        if spent > budget:
            probability = 1.0
        elif sigma < 1e-6:
            probability = float(projected > budget)
        else:
            probability = float(1 - norm.cdf((budget - projected) / sigma))
        return BudgetRisk(
            month="", budget=float(budget), spent_to_date=float(spent), days_elapsed=int(day),
            days_in_month=int(days_in_month), typical_share_spent=share, forecast_expected=float(forecast_expected),
            projected_total=float(projected), projected_low=float(max(spent, projected - _Z80 * sigma)),
            projected_high=float(projected + _Z80 * sigma), probability_over_budget=probability,
        )

    def for_month(self, budget: float, user_transactions: pd.DataFrame, period: pd.Period,
                  today: pd.Timestamp, forecast_expected: float) -> BudgetRisk:
        days = calendar.monthrange(period.year, period.month)[1]
        current = today.to_period("M")
        day = days if current > period else (0 if current < period else today.day)
        month_tx = user_transactions[user_transactions["date"].dt.to_period("M") == period]
        month_tx = month_tx[month_tx["date"].dt.day <= max(day, 0)]
        history = user_transactions[user_transactions["date"].dt.to_period("M") < period]
        share = self.share_spent(day, days, history)
        risk = self.estimate(budget, float(month_tx["amount"].sum()), day, days, forecast_expected, share)
        risk.month = str(period)
        return risk

    # --------------------------------------------------------------- evaluation
    def backtest(self, transactions: pd.DataFrame, backtest_predictions: pd.DataFrame,
                 checkpoints: tuple[int, ...] = (5, 15, 25), seed: int = 0) -> dict:
        """Calibration on the months scored by the forecasting backtest.

        Budgets are simulated as the user's previous-3-month average x U(0.85, 1.15). Note:
        the spending curve and error scale are fitted on overlapping data, so these numbers
        are mildly optimistic; the forecasts themselves are strictly out-of-sample.
        """
        rng = np.random.default_rng(seed)
        tx = transactions.assign(period=transactions["date"].dt.to_period("M"))
        monthly = tx.groupby(["user_id", "period"])["amount"].sum()
        cases = []
        for (user_id, period), cells in backtest_predictions.groupby(["user_id", "period"]):
            history = [monthly.get((user_id, period - k)) for k in (1, 2, 3)]
            history = [h for h in history if h is not None and h > 0]
            if history:
                cases.append((user_id, period, float(cells["gradient_boosting"].sum()),
                              float(np.mean(history)) * rng.uniform(0.85, 1.15)))
        results = {}
        for checkpoint in checkpoints:
            rows = []
            for user_id, period, expected, budget in cases:
                user_tx = tx[tx["user_id"] == user_id]
                days = calendar.monthrange(period.year, period.month)[1]
                day = min(checkpoint, days)
                month_tx = user_tx[user_tx["period"] == period]
                spent = float(month_tx.loc[month_tx["date"].dt.day <= day, "amount"].sum())
                share = self.share_spent(day, days, user_tx[user_tx["period"] < period])
                risk = self.estimate(budget, spent, day, days, expected, share)
                rows.append({"p": risk.probability_over_budget, "actual": float(month_tx["amount"].sum() > budget)})
            frame = pd.DataFrame(rows)
            if frame.empty:
                continue
            entry = {
                "n_user_months": int(len(frame)),
                "overrun_rate": float(frame["actual"].mean()),
                "brier_score": float(brier_score_loss(frame["actual"], frame["p"])),
                "brier_score_no_skill": float(brier_score_loss(frame["actual"], np.full(len(frame), frame["actual"].mean()))),
            }
            if frame["actual"].nunique() == 2:
                entry["roc_auc"] = float(roc_auc_score(frame["actual"], frame["p"]))
            bins = pd.cut(frame["p"], [0, 0.2, 0.4, 0.6, 0.8, 1.0], include_lowest=True)
            entry["reliability"] = [
                {"bin": str(b), "mean_predicted": round(float(g["p"].mean()), 3),
                 "observed_rate": round(float(g["actual"].mean()), 3), "n": int(len(g))}
                for b, g in frame.groupby(bins, observed=True)
            ]
            results[f"day_{checkpoint}"] = entry
        return results
