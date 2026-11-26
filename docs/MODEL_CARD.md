# Model card: FiscalPoint ML models (v2.0.0)

## Overview

| | |
|---|---|
| Models | Spending forecaster, budget-overrun risk, expense categorizer, anomaly detector |
| Framework | scikit-learn (gradient boosting, logistic regression, isolation forest), SciPy |
| Developed for | FiscalPoint, a personal finance web application (academic project) |
| Artifacts | `models/*.joblib` + `models/metadata.json`, produced by `python -m fiscalpoint_ml.train` |

## Intended use

* Help an individual **understand and plan** their own spending: expected monthly spend, an early
  warning when the budget is at risk, quick categorisation, and a prompt to double-check unusual amounts.
* All outputs are **informational**. They are not financial advice, credit decisions or fraud
  determinations, and they should not be used to make decisions about other people.

**Out of scope:** credit scoring, lending, insurance pricing, fraud adjudication, profiling users for
marketing, or any use where a wrong prediction harms the user.

## Training data

* **Synthetic** transactions from `fiscalpoint_ml/data/synthetic.py` (default 40 users × 30 months,
  ~37k transactions), optionally combined with the application's real database (`--source db --augment-synthetic`).
* The synthetic generator encodes assumptions about Indian urban households: income levels, rent
  share, festival seasonality, merchant names. Real users whose lives differ from these assumptions
  (irregular income, very high or low spending, rural patterns, other countries) may get less accurate results.
* Anomaly labels exist only for synthetic data, and are defined as "amount multiplied by 5–15". Real
  anomalies (fraud, duplicate entries, typos in amounts) may look different.

## Metrics (latest run, database + synthetic)

| Model | Metric | Value | Reference |
|---|---|---:|---|
| Forecast | MAE per user-category-month | ₹1,791 | best baseline ₹1,925 (−6.9 %); −11.3 % on synthetic only |
| Forecast | WAPE per category | 30.5 % | 32.8 % best baseline |
| Forecast | MAE per monthly total | ₹9,786 | 3-month average ₹9,575 (about level) |
| Forecast | 80 % interval coverage | 86.5 % | target 80 % |
| Budget risk | Brier score day 5 / 15 / 25 | 0.18 / 0.13 / 0.09 | 0.25 no-skill |
| Categorizer | Accuracy / macro-F1 / top-3 | 94.9 % / 0.886 / 96.6 % | stratified hold-out |
| Anomalies | Precision / recall / F1 | 0.58 / 0.28 / 0.38 | ratio rule 0.25 / 0.75 / 0.375 |

Method details: [ML_PIPELINE.md](ML_PIPELINE.md). Every training run regenerates `reports/REPORT.md`.

## Limitations

* **Evaluation is mostly on synthetic data.** The metrics show the method works on data with
  realistic structure. They do not guarantee accuracy on real users. Re-run the training report once
  real history accumulates and compare.
* **Cold start**: users with fewer than 3 complete months get forecasts driven mostly by patterns
  learned from other users. The UI shows a warning. No forecast is produced before the first complete month.
* **Monthly totals**: the model clearly improves per-category forecasts but is only on par with a
  3-month average for total monthly spend on mixed data.
* **Interval shape**: the lower bound is often ₹0 for sporadic categories. Category intervals are
  combined assuming independence.
* **Budget risk** is somewhat over-confident at the extremes (predicted ~95 % → observed ~80–87 %)
  and is only computed for the current month.
* **Categorizer** suggestions for very short or vague text ("paid", "LIC" with no amount) are
  uncertain. Suggestions under 20 % probability are hidden, and the user always makes the final choice.
* **Anomaly detector** recall is modest (~30 %). It is tuned for precision so that it does not
  nag. It is not a fraud detector.
* **Drift**: inflation and life changes shift spending. Retrain periodically. The registry
  records when and on what data each model was trained.

## Ethical considerations and privacy

* The models are trained on financial data. Real transaction data stays in the application's own
  database and model directory. Nothing is sent to third parties, and the ML service only accepts
  local, authenticated requests.
* A global model trained on many users' data could in principle memorise individual patterns.
  Artifacts in `models/` must therefore be handled as sensitive as the database: they are
  git-ignored and must not be published.
* The demo dataset is entirely synthetic. The original repository's dump with real names, emails
  and phone numbers was removed (see [ISSUES_FIXED.md](ISSUES_FIXED.md)).
* Predictions are presented with uncertainty (ranges, probabilities) and plain-language explanations.
