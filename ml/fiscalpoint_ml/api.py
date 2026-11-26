"""Flask inference service for the FiscalPoint web application.

Run (from ``ml/``)::

    python -m fiscalpoint_ml.api            # http://127.0.0.1:5001

Security model: the service is meant to be called by the PHP backend on the same host.
By default it only accepts requests from localhost; when ``ML_API_TOKEN`` is set every
request (except ``/health``) must also send it in the ``X-API-Key`` header. Set
``ML_API_ALLOW_REMOTE=true`` (together with a token) to run it on another machine.
Errors never include stack traces or database details.

Endpoints (all JSON)
--------------------
GET  /health                 liveness + which models are loaded
GET  /v1/models              model metadata and evaluation metrics
POST /v1/forecast            {user_id, horizon=2}
POST /v1/budget-risk         {user_id, month?="YYYY-MM", budget?}
POST /v1/categorize          {description, amount?, payment_method?, top_k=3}
POST /v1/anomalies           {user_id, months=3, limit=10}
POST /v1/evaluate            {user_id}   per-user baseline backtest + global model metrics
"""

from __future__ import annotations

import hmac
import logging
from functools import wraps

import numpy as np
import pandas as pd
from flask import Flask, jsonify, request

from . import __version__, registry
from .config import Settings
from .data.repository import Repository, make_repository
from .features import training_frame
from .forecasting import BASELINES, baseline_predictions, forecast_user, regression_metrics, total_interval

logger = logging.getLogger(__name__)

_LOCAL = {"127.0.0.1", "::1", "localhost"}


class ApiError(Exception):
    def __init__(self, message: str, status: int = 400):
        super().__init__(message)
        self.message = message
        self.status = status


def _today() -> pd.Timestamp:
    from .config import today

    return today()


def _round(value, digits=2):
    return None if value is None or (isinstance(value, float) and np.isnan(value)) else round(float(value), digits)


def create_app(settings: Settings | None = None, repository: Repository | None = None,
               bundle: registry.ModelBundle | None = None, today=None) -> Flask:
    """Application factory. Tests inject an in-memory repository, a model bundle and a fixed date."""
    settings = settings or Settings.from_env()
    app = Flask(__name__)
    app.json.sort_keys = False          # keep model / field order as produced
    state = {"repo": repository, "bundle": bundle}
    today_fn = (lambda: pd.Timestamp(today)) if today is not None else _today

    def repo() -> Repository:
        if state["repo"] is None:
            try:
                state["repo"] = make_repository(settings)
            except Exception:
                logger.exception("could not create data repository")
                raise ApiError("Data source is not configured", 503)
        return state["repo"]

    def models() -> registry.ModelBundle:
        if state["bundle"] is None:
            state["bundle"] = registry.load(settings.model_dir)
        return state["bundle"]

    def require(name: str):
        model = getattr(models(), name)
        if model is None:
            raise ApiError(f"The {name.replace('_', ' ')} model is not trained yet. Run: python -m fiscalpoint_ml.train", 503)
        return model

    # ------------------------------------------------------------------ security
    def protected(view):
        @wraps(view)
        def wrapped(*args, **kwargs):
            if not settings.allow_remote and request.remote_addr not in _LOCAL:
                return jsonify({"error": "Forbidden"}), 403
            if settings.allow_remote and not settings.api_token:
                return jsonify({"error": "Server misconfigured: remote access requires ML_API_TOKEN"}), 503
            if settings.api_token:
                supplied = request.headers.get("X-API-Key", "")
                if not hmac.compare_digest(supplied.encode(), settings.api_token.encode()):
                    return jsonify({"error": "Unauthorized"}), 401
            return view(*args, **kwargs)
        return wrapped

    @app.errorhandler(ApiError)
    def handle_api_error(error: ApiError):
        return jsonify({"error": error.message}), error.status

    @app.errorhandler(Exception)
    def handle_unexpected(error):
        from werkzeug.exceptions import HTTPException

        if isinstance(error, HTTPException):
            return jsonify({"error": error.description}), error.code
        logger.exception("unhandled error")
        return jsonify({"error": "Internal error"}), 500

    # ------------------------------------------------------------------- helpers
    def body() -> dict:
        data = request.get_json(silent=True)
        if not isinstance(data, dict):
            raise ApiError("Request body must be a JSON object")
        return data

    def int_field(data: dict, name: str, default=None, low=None, high=None) -> int:
        value = data.get(name, default)
        if value is None:
            raise ApiError(f"{name} is required")
        try:
            value = int(value)
        except (TypeError, ValueError):
            raise ApiError(f"{name} must be an integer")
        if (low is not None and value < low) or (high is not None and value > high):
            raise ApiError(f"{name} must be between {low} and {high}")
        return value

    def user_transactions(user_id: int) -> pd.DataFrame:
        try:
            return repo().transactions(user_id)
        except ApiError:
            raise
        except Exception:
            logger.exception("loading transactions failed")
            raise ApiError("Could not load expense data", 503)

    def monthly_history(tx: pd.DataFrame, months: int = 12) -> list[dict]:
        if tx.empty:
            return []
        totals = tx.groupby(tx["date"].dt.to_period("M"))["amount"].sum()
        current = today_fn().to_period("M")
        periods = pd.period_range(current - months, current, freq="M")
        return [{"period": str(p), "total": _round(totals.get(p, 0.0)), "partial": p == current} for p in periods]

    def user_forecast(user_id: int, horizon: int):
        tx = user_transactions(user_id)
        start = today_fn().to_period("M")
        complete = tx[tx["date"].dt.to_period("M") < start]
        if complete.empty:
            return tx, None
        forecast = forecast_user(require("forecaster"), tx, start, horizon=horizon)
        return tx, forecast

    # ----------------------------------------------------------------- endpoints
    @app.get("/health")
    def health():
        bundle = models()
        return jsonify({
            "status": "online",
            "version": __version__,
            "models_loaded": bundle.loaded,
            "trained_at": (bundle.metadata or {}).get("trained_at"),
        })

    @app.get("/v1/models")
    @protected
    def model_info():
        meta = models().metadata
        if not meta:
            raise ApiError("No trained models found. Run: python -m fiscalpoint_ml.train", 503)
        metrics = meta.get("metrics", {})
        cat = dict(metrics.get("categorizer", {}))
        cat.pop("confusion_matrix", None)
        cat.pop("per_class", None)
        return jsonify({
            "package_version": meta.get("package_version"),
            "trained_at": meta.get("trained_at"),
            "sklearn_version": meta.get("sklearn_version"),
            "data": meta.get("data"),
            "forecaster": meta.get("forecaster"),
            "metrics": {
                "forecasting": {k: v for k, v in metrics.get("forecasting", {}).items() if k != "mae_by_origin"},
                "categorizer": cat,
                "anomaly": metrics.get("anomaly"),
                "budget_risk": metrics.get("budget_risk"),
            },
        })

    @app.post("/v1/forecast")
    @protected
    def forecast():
        data = body()
        user_id = int_field(data, "user_id", low=1)
        horizon = int_field(data, "horizon", default=2, low=1, high=3)
        tx, result = user_forecast(user_id, horizon)
        history = monthly_history(tx)
        if result is None or result.empty:
            return jsonify({
                "user_id": user_id,
                "message": "Not enough history yet: forecasts start after your first complete month of expenses.",
                "history": history,
                "months": [],
            })
        months_out = []
        for step, rows in result.groupby("step"):
            rows = rows.sort_values("expected", ascending=False)
            months_out.append({
                "period": str(rows["period"].iloc[0]),
                "label": rows["period"].iloc[0].strftime("%B %Y"),
                "total": {k: _round(v) for k, v in total_interval(rows).items()},
                "categories": [
                    {
                        "category": r.category,
                        "expected": _round(r.expected),
                        "low": _round(r.low),
                        "high": _round(r.high),
                        "last_month": _round(r.lag_1),
                        "avg_3_months": _round(r.roll_mean_3),
                    }
                    for r in rows.itertuples() if r.expected > 0.5 or (r.lag_1 or 0) > 0
                ],
            })
        months_of_history = int(result.loc[result["step"] == 1, "months_of_history"].max())
        warnings = []
        if months_of_history < 3:
            warnings.append("Less than 3 months of history: forecasts rely mostly on patterns learned from other users.")
        meta = models().metadata or {}
        return jsonify({
            "user_id": user_id,
            "generated_at": today_fn().strftime("%Y-%m-%d"),
            "model": {
                "name": "gradient_boosting",
                "trained_at": meta.get("trained_at"),
                "interval": "80% prediction interval",
                "backtest_mae_improvement": (meta.get("metrics", {}).get("forecasting", {})
                                             .get("mae_improvement_vs_best_baseline")),
            },
            "months_of_history": months_of_history,
            "warnings": warnings,
            "history": history,
            "months": months_out,
        })

    @app.post("/v1/budget-risk")
    @protected
    def budget_risk():
        data = body()
        user_id = int_field(data, "user_id", low=1)
        today = today_fn()
        month = str(data.get("month") or today.strftime("%Y-%m"))
        try:
            period = pd.Period(month, freq="M")
        except Exception:
            raise ApiError("month must be YYYY-MM")
        if period != today.to_period("M"):
            raise ApiError("budget risk is only available for the current month")
        budget = data.get("budget")
        if budget is None:
            try:
                budget = repo().budget(user_id, month)
            except Exception:
                logger.exception("loading budget failed")
                raise ApiError("Could not load budget", 503)
        if budget is None:
            return jsonify({"user_id": user_id, "month": month, "message": "No budget set for this month."})
        try:
            budget = float(budget)
        except (TypeError, ValueError):
            raise ApiError("budget must be a number")
        if budget <= 0:
            raise ApiError("budget must be positive")

        tx, result = user_forecast(user_id, horizon=1)
        model = require("budget_risk")
        expected = float(result["expected"].sum()) if result is not None and not result.empty else 0.0
        if result is None or result.empty:
            # no history: fall back to pace only (the forecast equals what is spent so far)
            month_tx = tx[tx["date"].dt.to_period("M") == period]
            expected = float(month_tx["amount"].sum())
        risk = model.for_month(budget, tx, period, today, expected)
        return jsonify({"user_id": user_id, **risk.to_dict()})

    @app.post("/v1/categorize")
    @protected
    def categorize():
        data = body()
        description = str(data.get("description") or "").strip()[:255]
        if not description:
            raise ApiError("description is required")
        amount = data.get("amount")
        try:
            amount = float(amount) if amount not in (None, "") else None
        except (TypeError, ValueError):
            raise ApiError("amount must be a number")
        top_k = int_field(data, "top_k", default=3, low=1, high=5)
        suggestions = require("categorizer").suggest(description, amount, str(data.get("payment_method") or ""), top_k)
        return jsonify({"description": description, "suggestions": suggestions})

    @app.post("/v1/anomalies")
    @protected
    def anomalies():
        data = body()
        user_id = int_field(data, "user_id", low=1)
        months = int_field(data, "months", default=3, low=1, high=24)
        limit = int_field(data, "limit", default=10, low=1, high=50)
        tx = user_transactions(user_id)
        if tx.empty:
            return jsonify({"user_id": user_id, "anomalies": []})
        since = (today_fn().to_period("M") - (months - 1)).to_timestamp()
        flagged = require("anomaly").user_anomalies(tx, since=since, limit=limit)
        return jsonify({
            "user_id": user_id,
            "since": since.strftime("%Y-%m-%d"),
            "anomalies": [
                {
                    "date": r.date.strftime("%Y-%m-%d"),
                    "category": r.category,
                    "description": r.description,
                    "amount": _round(r.amount),
                    "typical_amount": _round(r.category_median),
                    "ratio": _round(r.ratio_category, 1),
                    "score": _round(r.anomaly_score, 3),
                    "reason": r.reason,
                }
                for r in flagged.itertuples()
            ],
        })

    @app.post("/v1/evaluate")
    @protected
    def evaluate():
        data = body()
        user_id = int_field(data, "user_id", low=1)
        tx = user_transactions(user_id)
        complete = tx[tx["date"].dt.to_period("M") < today_fn().to_period("M")]
        frame = training_frame(complete) if not complete.empty else pd.DataFrame()
        meta = models().metadata or {}
        global_metrics = meta.get("metrics", {}).get("forecasting", {})
        response = {
            "user_id": user_id,
            "global_backtest": {
                "category_level": global_metrics.get("category_level"),
                "champion": global_metrics.get("champion"),
                "interval_coverage_80": global_metrics.get("interval_coverage_80"),
            },
        }
        if frame.empty or frame["period"].nunique() < 2:
            response["user_baselines"] = None
            response["message"] = "At least three complete months are needed for a personal backtest."
            return jsonify(response)
        frame = frame[frame["months_of_history"] >= 2]
        if frame.empty:
            response["user_baselines"] = None
            response["message"] = "At least three complete months are needed for a personal backtest."
            return jsonify(response)
        preds = baseline_predictions(frame)
        response["user_baselines"] = {m: regression_metrics(frame["amount"], preds[m]) for m in BASELINES}
        response["months_evaluated"] = int(frame["period"].nunique())
        return jsonify(response)

    return app


def main() -> None:
    settings = Settings.from_env()
    logging.basicConfig(level=settings.log_level, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    app = create_app(settings)
    if settings.allow_remote and not settings.api_token:
        raise SystemExit("ML_API_ALLOW_REMOTE=true requires ML_API_TOKEN to be set.")
    logger.info("FiscalPoint ML API %s on http://%s:%d", __version__, settings.api_host, settings.api_port)
    # debug is always off: the Werkzeug debugger allows remote code execution
    app.run(host=settings.api_host, port=settings.api_port, debug=False)


if __name__ == "__main__":
    main()
