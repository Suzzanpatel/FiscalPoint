# Machine-learning pipeline

This document explains the data, the features, each model, how they are evaluated and how
to retrain or extend them. Code lives in `ml/fiscalpoint_ml/`.

## 1. Problem framing

| Task | Type | Unit of prediction | Target |
|---|---|---|---|
| Spending forecast | Regression (time series, many short series) | user × category × month | Amount spent (₹) |
| Budget-overrun risk | Probabilistic classification | user × current month | P(month total > budget) |
| Expense categorizer | Multi-class text classification | one transaction | Category (13 classes) |
| Anomaly detection | Unsupervised outlier detection | one transaction | Anomaly score + flag |

## 2. Data

### Sources

`data/repository.py` loads transactions from the `Expense` table (`MySQLRepository`) or from a
CSV / DataFrame (`FrameRepository`). `data/schema.py::normalize_transactions` produces the
canonical table used everywhere:

`user_id, date, category, amount, description, payment_method`

Rows without a user, date or category, or with a non-positive amount, are dropped. The original
dump contained such rows (NULL `Uid`, `0.00` amounts).

### Synthetic data

The original repository had one month of data for two users. That is not enough to train
anything or to measure accuracy. `data/synthetic.py` generates realistic histories instead:

* per-user income (log-normal around ₹60k), a spend ratio and gradual inflation;
* recurring payments on fixed days: rent with yearly revisions, EMIs, SIPs, quarterly or annual insurance;
* variable categories with Poisson transaction counts and log-normal amounts;
* Indian seasonality: Diwali gifts (Oct/Nov), school fees (Jun/Jul, Jan), winter healthcare, December entertainment;
* users joining at different times, so the data contains cold-start cases;
* free-text descriptions with merchants (Swiggy, Uber, LIC…), casing changes, ~8 % typos and ~6 % vague texts ("paid", "bill");
* **1.5 % injected anomalies** (amount × 5–15) labelled `is_anomaly`, used to evaluate the detector.

The generator is seeded and reproducible. It also produces `database/seed_demo.sql`
(`python -m fiscalpoint_ml.data.seed_sql`), so the demo database and the training data share the same structure.

`--augment-synthetic` combines real database users with synthetic users (ids offset by 10,000,000).
A global model then learns general spending dynamics from the synthetic users and applies them to real users.

## 3. Features (forecasting)

`features.py` builds a dense monthly panel: every category a user has used gets one row per month
from the user's first month on, and months without spending are explicit **zeros**. For month *t*
every feature is computed from months **before t only** (`shift(1)` before any window).
`tests/test_features.py` checks this: changing month *t*'s data must not change month *t*'s features.

| Feature | Meaning |
|---|---|
| `lag_1, lag_2, lag_3, lag_6, lag_12` | Spend 1/2/3/6/12 months earlier (`lag_12` = same month last year) |
| `roll_mean_3, roll_mean_6, roll_std_6, roll_max_6` | Rolling statistics of past months |
| `nonzero_frac_6` | Share of the last 6 months with any spending (sporadic categories) |
| `expanding_mean` | Long-run average for this user and category |
| `trend_12` | Linear-trend extrapolation from up to 12 past months |
| `months_of_history` | How much history exists (cold-start signal) |
| `user_total_lag_1, user_total_mean_3, share_lag_1` | The user's overall spending level and this category's share |
| `month, month_sin, month_cos` | Seasonality |
| `category_code` | Category (native categorical feature; custom categories share one code) |

`inference_frame()` builds the same features for a future month, ignoring any transactions in or
after that month. A half-finished current month therefore never leaks into its own forecast.

Permutation importance from the latest run: `expanding_mean` > `lag_1` ≈ `category_code` > `lag_3`
> `roll_std_6` > `roll_mean_3` (chart: `reports/forecast_feature_importance.png`).

## 4. Models

### 4.1 Spending forecast (`forecasting.py`)

**Baselines** (no training): `naive` (last month), `moving_avg_3`, `seasonal_naive` (same month
last year), `linear_trend`. Version 1 of the project chose between the first three per category.

**GlobalForecaster**: one model for all users and categories, made of three
`HistGradientBoostingRegressor`s:

| Output | Loss | Why |
|---|---|---|
| `expected` | Poisson on the raw amount | Estimates the **mean**. Means add up, so category forecasts sum to an unbiased monthly total |
| `low` / `high` | Quantile 0.1 / 0.9 on `log1p(amount)` | 80 % prediction interval |

Two design decisions came out of evaluation:

* A median model (absolute-error loss) had slightly better per-category MAE but under-forecast
  **monthly totals by ~17 %**, because spending is right-skewed and medians do not add up. Poisson
  loss removed most of that bias.
* With `l2_regularization > 0`, the quantile models predicted ~0 for every row: quantile-loss
  gradients are tiny, so L2 shrinks the leaves to zero. The quantile models therefore use no L2.
  A regression test checks this.

Multi-step forecasts (next month) are recursive: month *t*'s expected value is appended as a
pseudo-observation before forecasting *t+1*. Categories with no spending in the last 6 months are
forecast as 0. The monthly-total interval combines category half-widths in quadrature
(independence assumption). The budget-risk model uses an empirical error scale instead.

**Hyper-parameters.** Four settings were compared in the rolling-origin backtest on two datasets
(synthetic; database + synthetic):

| Setting | Synthetic: MAE gain vs best baseline | DB + synthetic |
|---|---:|---:|
| 31 leaves, ≥20 per leaf, 400 iters | 11.6 % | 2.5 % |
| 15 leaves, ≥40 per leaf, 400 iters | 10.9 % | 6.0 % |
| **15 leaves, ≥40 per leaf, 250 iters** (default) | **11.3 %** | **6.9 %** |
| lr 0.03, ≥40 per leaf, 500 iters | 10.5 % | 2.8 % |

The more regularised setting is the most robust across datasets and is the default.

### 4.2 Budget-overrun risk (`budget.py`)

Inputs: the forecast for the month, the amount spent so far and today's date. Two quantities are
**learned from data**:

1. **Within-month spending curve F(d)**: the share of a month's spend typically done by day *d*.
   Rent, EMIs and SIPs land early, so on average ~35 % is spent by day 5 and ~68 % by day 15. A user's
   own curve is blended in once they have 4+ months of history.
2. **Error scale σ**: the spread of relative monthly-total forecast errors in the backtest
   (10th–90th percentile range).

```
pace      = spent / F(d)
projected = F(d)·pace + (1 − F(d))·max(forecast, spent)
σ_rest    = σ · forecast · (1 − F(d))
P(over)   = 1 − Φ((budget − projected) / σ_rest)
```

The first version projected linearly (`spent × days / day`) and used category intervals for σ. It
was badly over-confident: its Brier score was worse than always predicting the base rate. The
learned curve and the empirical σ fixed this:

| Checkpoint | Brier (v1, linear pace) | Brier (final) | No-skill | ROC-AUC (final) |
|---|---:|---:|---:|---:|
| Day 5 | 0.330 | 0.18–0.20 | 0.25 | 0.78–0.83 |
| Day 15 | 0.337 | 0.13–0.16 | 0.25 | 0.88–0.91 |
| Day 25 | 0.147 | 0.08–0.09 | 0.25 | 0.96 |

### 4.3 Expense categorizer (`categorizer.py`)

`ColumnTransformer` → `LogisticRegression(class_weight="balanced")`:

* description → TF-IDF character n-grams (2–5, word-boundary), robust to typos like "swigy" or "electrcity";
* description → TF-IDF word uni/bi-grams ("loan emi");
* payment method → one-hot;
* amount → quantile bins of log(amount). A **missing amount is encoded as "no information"**, not as
  ₹0: the naive encoding put "LIC" and "sip" into the snack-sized bin and suggested Food/Transport.

Custom user categories are excluded from training (too sparse). Suggestions below 20 % probability
are not shown.

### 4.4 Anomaly detection (`anomaly.py`)

Each transaction is described relative to the same user's history: robust z-scores of log(amount)
within the category and across all of the user's spending (median/MAD), log ratio to the category
median, category frequency and log amount. An `IsolationForest` (2 % contamination) scores the
transactions. A transaction is **flagged** only when the score is above threshold **and** the amount is
≥ 3× the user's usual amount for that category **and** the user has ≥ 4 transactions in that
category. Each flag carries a readable reason.

Alternatives were evaluated and rejected: a pure ratio rule, a pure robust-z rule, a two-sided
forest without the ratio rule, and a day-of-week feature (it lowered precision). Precision was
favoured over recall because every flag is shown to the user.

## 5. Evaluation methodology

* **Forecasting**: expanding-window **rolling-origin backtest** over the last 6 complete months. For
  each origin month the model is trained only on earlier months and scored on that month, and every
  baseline is scored on exactly the same cells. Metrics: MAE, RMSE, WAPE and bias, at category level
  and at monthly-total level, plus interval coverage.
* **Categorizer**: stratified 80/20 hold-out; accuracy, macro-F1, top-3 accuracy, per-class report and confusion matrix.
* **Anomalies**: precision / recall / F1 / ROC-AUC against the injected labels, compared with two rule baselines.
  This is in-sample (the model is unsupervised and never sees the labels).
* **Budget risk**: Brier score, ROC-AUC and a reliability table at days 5/15/25, using the backtest's
  out-of-sample forecasts and simulated budgets (previous-3-month average × U(0.85, 1.15)).

### Latest results (database + synthetic, 48 users, 43k transactions)

| Model | MAE / category | WAPE | MAE / monthly total |
|---|---:|---:|---:|
| **gradient_boosting** | **₹1,791** | **30.5 %** | ₹9,786 |
| moving_avg_3 | ₹1,925 | 32.8 % | **₹9,575** |
| naive | ₹2,151 | 36.6 % | ₹11,387 |
| linear_trend | ₹2,337 | 39.8 % | ₹12,280 |
| seasonal_naive | ₹2,560 | 43.6 % | ₹15,600 |

To be clear about these numbers: the model clearly wins per category, but on monthly totals it is
level with a 3-month average on this dataset (better by 6 % on pure synthetic data). Interval
coverage was 86.5 % (target 80 %, slightly conservative). Categorizer: 94.9 % accuracy, macro-F1
0.886, top-3 96.6 %. Anomalies: precision 0.58, recall 0.28 (F1 0.38 vs 0.375 / 0.353 for the rule
baselines, at a 6× lower flag rate than the ratio rule).

## 6. Retraining and model management

```bash
cd ml
python -m fiscalpoint_ml.train --source db --augment-synthetic
# options: --backtest-months 6  --users 40 --months 30 --seed 42
#          --model-dir ../models  --report-dir ../reports  --save-synthetic ../data/synthetic
```

`models/metadata.json` records the package, scikit-learn and Python versions, the training time,
a data summary and all metrics. The service warns if the installed scikit-learn differs from the
one used for training. Restart the service after retraining. The admin page *ML Models* shows
which models are loaded and their metrics.

## 7. Extending

* **New feature**: add it in `features.add_features()` using only shifted (past) values, append it
  to `FEATURE_COLUMNS`, add a leakage test, retrain and compare the backtest table.
* **New model**: implement `fit(frame)` / `predict(frame)` returning `low/expected/high`, plug it
  into `rolling_origin_backtest`, and keep it only if it beats the current champion.
* **New category**: add it to `CATEGORIES` in `config.py` **and** `EXPENSE_CATEGORIES` in
  `web/includes/bootstrap.php`, plus descriptions in `data/synthetic.py`.

Ideas for future work: log the forecasts the service serves and score them once the month is over
(live monitoring); use per-user conformal intervals; learn from category corrections made in the add-expense form.
