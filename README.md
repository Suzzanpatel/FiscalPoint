# Fiscal-Point-Expense-Tracker-
This repository contains our college minor project, developed as part of my BSc IT curriculum. The project showcases our skills in web development and focuses on building a daily expense tracker. It allows users to add expenses, track their spending, and generate detailed reports. Feel free to explore the code and contribute!

## Database configuration

PHP pages and the prediction service read `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` from the root `.env` file. The `.env` file is intentionally ignored by Git; configure the same values for the local PHP and Python runtimes. Run `composer install` in the project root to restore the PHP dependencies and autoloader.

Budget and income records store their month as `YYYY-MM`. Existing records containing only a month name do not identify a year and cannot be migrated automatically. Before deploying this version against an existing database, assign the correct year to each legacy `Budget.Month` and `Income.Month` value; do not map them without confirming the year.

## Prediction methodology and backtest

Expense rows are first aggregated into monthly totals per category, with missing months represented as zero. Each category is evaluated with an expanding-window, one-month-ahead backtest: the first three monthly totals train each method, then each later month is predicted using only earlier months. The reported error is mean absolute error (MAE) across those forecasts. The regression time feature is a continuous month index (`0, 1, 2, ...`). Linear regression is selected for forecasting only when its rolling MAE is strictly lower than both the last-month naive forecast and the three-month moving average; otherwise the better-performing baseline is used.

The checked-in SQL sample contains expense data for one month only, so it cannot produce an honest rolling error for any category. The minimum needed for one backtest error is four consecutive monthly totals per category. Current sample-data results:

| Category | Naive MAE | 3-month average MAE | Linear regression MAE |
| --- | ---: | ---: | ---: |
| Education | N/A | N/A | N/A |
| Entertainment | N/A | N/A | N/A |
| Food | N/A | N/A | N/A |
| Healthcare | N/A | N/A | N/A |
| Housing | N/A | N/A | N/A |
| Insurance | N/A | N/A | N/A |
| Personal Care | N/A | N/A | N/A |
| Transportation | N/A | N/A | N/A |

`N/A` means the checked-in sample has fewer than four monthly observations, not zero prediction error. The `/evaluate_model` endpoint returns per-category and overall rolling MAEs once sufficient data is available.
