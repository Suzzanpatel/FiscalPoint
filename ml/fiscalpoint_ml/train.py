"""Training pipeline: evaluate every model, fit final versions, save artifacts and a report.

Usage (from the ``ml/`` directory)::

    python -m fiscalpoint_ml.train                      # synthetic data (default; no database needed)
    python -m fiscalpoint_ml.train --source db          # the live FiscalPoint MySQL database
    python -m fiscalpoint_ml.train --source csv --csv path/to/transactions.csv
    python -m fiscalpoint_ml.train --source db --augment-synthetic   # real data + synthetic history

Steps
-----
1. load and normalise transactions
2. forecasting: rolling-origin backtest (model vs 4 baselines), then fit on all complete months
3. categorizer: stratified hold-out evaluation, then fit on everything
4. anomaly detector: fit; evaluate when labels exist (synthetic data)
5. budget risk: learn spending curve + error scale from the backtest, check calibration
6. write ``models/*.joblib``, ``models/metadata.json`` and ``reports/`` (Markdown + charts)
"""

from __future__ import annotations

import argparse
import logging
import sys
import time
from pathlib import Path

import pandas as pd

from . import anomaly, categorizer, registry, reporting
from .budget import BudgetRiskModel
from .config import PROJECT_ROOT, Settings
from .data import normalize_transactions, synthetic
from .data.repository import FrameRepository, MySQLRepository
from .features import training_frame
from .forecasting import GlobalForecaster, _last_complete_period, rolling_origin_backtest

logger = logging.getLogger("fiscalpoint_ml.train")


def load_transactions(args, settings: Settings) -> tuple[pd.DataFrame, str]:
    if args.source == "synthetic":
        dataset = synthetic.generate(n_users=args.users, n_months=args.months, seed=args.seed)
        if args.save_synthetic:
            synthetic.save(dataset, args.save_synthetic)
            logger.info("synthetic CSVs written to %s", args.save_synthetic)
        return normalize_transactions(dataset.transactions), f"synthetic (seed {args.seed})"
    if args.source == "csv":
        return FrameRepository.from_csv(Path(args.csv)).transactions(), f"csv ({args.csv})"
    tx = MySQLRepository(settings).transactions()
    label = "mysql database"
    if args.augment_synthetic:
        # Real users keep their ids; synthetic users are shifted to ids that cannot collide.
        extra = normalize_transactions(synthetic.generate(n_users=args.users, n_months=args.months, seed=args.seed).transactions)
        extra["user_id"] += 10_000_000
        tx = pd.concat([tx, extra], ignore_index=True)
        label += " + synthetic augmentation"
    return tx, label


def run(args) -> dict:
    settings = Settings.from_env()
    model_dir = Path(args.model_dir or settings.model_dir)
    report_dir = Path(args.report_dir)
    report_dir.mkdir(parents=True, exist_ok=True)
    started = time.time()

    tx, source = load_transactions(args, settings)
    if tx.empty:
        raise SystemExit("No transactions found - nothing to train on.")
    logger.info("loaded %d transactions for %d users from %s", len(tx), tx["user_id"].nunique(), source)

    # ---- 1. forecasting
    logger.info("forecasting: rolling-origin backtest over %d months", args.backtest_months)
    predictions, forecast_summary = rolling_origin_backtest(tx, n_origins=args.backtest_months)
    forecast_summary["n_origins"] = args.backtest_months
    last_complete = _last_complete_period(tx)
    train_frame = training_frame(tx[tx["date"].dt.to_period("M") <= last_complete])
    train_frame = train_frame[train_frame["period"] <= last_complete]
    forecaster = GlobalForecaster().fit(train_frame)
    importance = forecaster.feature_importance(train_frame.sample(min(len(train_frame), 4000), random_state=0))
    forecast_summary["feature_importance"] = {k: round(float(v), 3) for k, v in importance.items()}
    logger.info("forecasting: %s MAE %.0f vs best baseline %s (%.1f%% better)", forecast_summary["champion"],
                forecast_summary["category_level"]["gradient_boosting"]["mae"], forecast_summary["best_baseline"],
                100 * forecast_summary["mae_improvement_vs_best_baseline"])

    # ---- 2. categorizer
    logger.info("categorizer: hold-out evaluation")
    cat_metrics, _ = categorizer.evaluate(tx)
    cat_model = categorizer.ExpenseCategorizer().fit(tx)
    logger.info("categorizer: accuracy %.3f, macro-F1 %.3f", cat_metrics["accuracy"], cat_metrics["macro_f1"])

    # ---- 3. anomalies
    detector = anomaly.AnomalyDetector().fit(tx)
    if "is_anomaly" in tx.columns and tx["is_anomaly"].notna().any():
        labelled = tx.dropna(subset=["is_anomaly"])
        an_metrics = {"evaluated": True, **anomaly.evaluate(labelled, detector)}
        logger.info("anomaly: precision %.2f recall %.2f", an_metrics["precision"], an_metrics["recall"])
    else:
        an_metrics = {"evaluated": False}

    # ---- 4. budget risk
    budget_model = BudgetRiskModel().fit(tx, predictions)
    budget_metrics = budget_model.backtest(tx, predictions)

    metadata = {
        "data": {
            "source": source,
            "n_transactions": int(len(tx)),
            "n_users": int(tx["user_id"].nunique()),
            "first_date": str(tx["date"].min().date()),
            "last_date": str(tx["date"].max().date()),
        },
        "forecaster": {
            "trained_through": forecaster.trained_through_,
            "n_training_rows": forecaster.n_training_rows_,
            "champion": forecast_summary["champion"],
        },
        "budget_risk_sigma": budget_model.relative_sigma,
        "metrics": {
            "forecasting": forecast_summary,
            "categorizer": cat_metrics,
            "anomaly": an_metrics,
            "budget_risk": budget_metrics,
        },
        "training_seconds": None,
    }
    metadata["training_seconds"] = round(time.time() - started, 1)
    bundle = registry.ModelBundle(forecaster=forecaster, categorizer=cat_model, anomaly=detector, budget_risk=budget_model)
    registry.save(bundle, model_dir, metadata)
    saved = registry.load(model_dir).metadata                # includes versions + timestamp

    charts = reporting.forecast_charts(predictions, forecast_summary, importance, report_dir)
    charts["confusion"] = reporting.confusion_chart(cat_metrics, report_dir)
    charts["reliability"] = reporting.reliability_chart(budget_metrics, report_dir)
    report = reporting.write_markdown(saved, charts, report_dir)
    logger.info("models saved to %s, report written to %s (%.0fs)", model_dir, report, metadata["training_seconds"])
    return saved


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description="Train and evaluate the FiscalPoint ML models.")
    parser.add_argument("--source", choices=["synthetic", "db", "csv"], default="synthetic")
    parser.add_argument("--csv", help="transactions CSV (with --source csv)")
    parser.add_argument("--augment-synthetic", action="store_true",
                        help="with --source db: add synthetic users so small real datasets can still train")
    parser.add_argument("--users", type=int, default=40, help="synthetic users")
    parser.add_argument("--months", type=int, default=30, help="synthetic months of history")
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--backtest-months", type=int, default=6)
    parser.add_argument("--model-dir", help="where to save artifacts (default: ML_MODEL_DIR or <project>/models)")
    parser.add_argument("--report-dir", default=str(PROJECT_ROOT / "reports"))
    parser.add_argument("--save-synthetic", help="also write the synthetic dataset as CSV files to this directory")
    args = parser.parse_args(argv)
    if args.source == "csv" and not args.csv:
        parser.error("--csv is required with --source csv")
    return args


def main(argv=None) -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    logging.getLogger("matplotlib").setLevel(logging.WARNING)
    run(parse_args(argv))
    return 0


if __name__ == "__main__":
    sys.exit(main())
