# FiscalPoint — ML-powered personal finance

FiscalPoint is a personal finance web application that **learns from spending history** to
forecast future expenses, warn about budget overruns, categorise expenses automatically and
flag unusual transactions. It started as a BSc IT expense-tracker project (PHP + MySQL); version 2
turns it into a machine-learning project with a proper training pipeline, evaluation against
baselines, a model registry and an inference API that the web app consumes.

| ML capability | What the user sees | Model | Evaluation (latest run) |
|---|---|---|---|
| **Spending forecast** | Expected spend this month and next, per category, with an 80 % range | Global gradient-boosted trees (scikit-learn `HistGradientBoostingRegressor`), Poisson loss + quantile models | Rolling-origin backtest: **7–11 % lower MAE** than the best of 4 baselines; 80 % interval covers 78–87 % |
| **Budget-overrun risk** | "18 % chance of exceeding your budget", projected month-end total | Forecast + learned within-month spending curve + empirical error scale | Brier score 0.18 / 0.13 / 0.09 at day 5 / 15 / 25 vs 0.25 no-skill |
| **Expense categorizer** | Category suggestions while typing a description | TF-IDF (char + word n-grams) + payment method + amount bins → logistic regression | Hold-out accuracy **94.9 %**, macro-F1 0.89, top-3 96.6 % |
| **Anomaly detection** | "₹8,231 is 28.5× your usual Food spend" | Isolation Forest on user-relative features + explainable ratio rule | Precision 0.58 / recall 0.28 on labelled anomalies (beats two rule baselines on F1 and precision) |

All numbers come from `reports/REPORT.md`, which the training pipeline regenerates on every run.
They are measured on **synthetic data** (see [Model card](docs/MODEL_CARD.md) for what that means).

---

## Architecture at a glance

```
 Browser ──► Apache/PHP (web/)  ──SQL──►  MySQL / MariaDB
                    │                          ▲
                    │ HTTP + X-API-Key         │ reads Expense / Budget
                    ▼                          │
            Flask ML service (ml/fiscalpoint_ml/api.py)
                    │ loads
                    ▼
            models/*.joblib  ◄── python -m fiscalpoint_ml.train  (backtest, fit, report)
```

The browser never talks to the ML service directly: PHP calls it server-side, so the service
can stay bound to `127.0.0.1` and protected by a shared token. Details: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Repository layout

```text
FiscalPoint/
├── web/                     PHP application (Apache document root)
│   ├── includes/            bootstrap (session, CSRF, escaping, auth), DB, ML client, sidebars
│   ├── admin/               administrator pages (users, categories, queries, ML model status)
│   ├── api/                 small JSON/AJAX endpoints (budget check, account deletion, ML proxy)
│   ├── assets/              css/, js/, img/, vendor/ (pinned Chart.js, jQuery, Font Awesome)
│   └── *.php                user pages (dashboard, expenses, reports, predictions, ...)
├── ml/                      Python machine-learning package
│   ├── fiscalpoint_ml/      data, features, forecasting, categorizer, anomaly, budget, api, train
│   ├── tests/               pytest suite (43 tests)
│   ├── requirements.txt
│   └── pyproject.toml
├── database/
│   ├── schema.sql           clean schema (fresh installs)
│   ├── seed_demo.sql        synthetic demo users + 2 years of history (no real personal data)
│   └── migrations/          upgrade script for databases created from the old dump
├── models/                  trained artifacts (generated, git-ignored)
├── reports/                 evaluation report + charts (generated, git-ignored)
├── data/                    optional CSV exports of synthetic data (generated, git-ignored)
├── docs/                    documentation (see below)
├── composer.json            PHP dependencies (PhpSpreadsheet, mPDF, PHPMailer, phpdotenv)
├── .env.example             all configuration options
└── Makefile                 shortcuts for the common commands
```

## Quick start

Prerequisites: PHP 8.0+ with `mysqli`, `curl`, `mbstring`, `gd`, `zip`; Composer; MySQL 8 or MariaDB 10.4+
(XAMPP works); Python 3.10+.

```bash
git clone https://github.com/Suzzanpatel/FiscalPoint.git && cd FiscalPoint
composer install
cp .env.example .env                      # then edit DB_* and set ML_API_TOKEN

# database (schema + optional demo data)
mysql -u root -p -e "CREATE DATABASE FiscalPoint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p FiscalPoint < database/schema.sql
mysql -u root -p FiscalPoint < database/seed_demo.sql

# machine learning
python -m venv .venv && source .venv/bin/activate      # Windows: .venv\Scripts\activate
pip install -r ml/requirements.txt
cd ml
python -m fiscalpoint_ml.train --source db --augment-synthetic   # ~1 minute; writes models/ and reports/
python -m fiscalpoint_ml.api                                     # ML service on http://127.0.0.1:5001
```

Open `http://localhost/FiscalPoint/web/` (XAMPP) or run `php -S 127.0.0.1:8080 -t web` and open
`http://127.0.0.1:8080`. Demo logins from `seed_demo.sql`:

| Account | Email | Password |
|---|---|---|
| User | `demo.user1@fiscalpoint.test` (… `demo.user8`) | `Demo@1234` |
| Admin | `admin@fiscalpoint.test` | `Admin@1234` |

Change or delete these accounts on anything other than a local machine. Full instructions,
Windows/XAMPP notes and troubleshooting: [docs/SETUP.md](docs/SETUP.md).

## Documentation

| Document | Contents |
|---|---|
| [docs/SETUP.md](docs/SETUP.md) | Installation, configuration, running, troubleshooting |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Components, request flow, security model, folder conventions |
| [docs/ML_PIPELINE.md](docs/ML_PIPELINE.md) | Data, features, every model, evaluation method, how to retrain and extend |
| [docs/MODEL_CARD.md](docs/MODEL_CARD.md) | Intended use, metrics, limitations, ethical considerations |
| [docs/API.md](docs/API.md) | ML service endpoints with request / response examples |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, constraints, demo data, upgrading an existing database |
| [docs/ISSUES_FIXED.md](docs/ISSUES_FIXED.md) | Audit of the original code: every issue found and how it was fixed |

## Common commands

```bash
make test          # Python tests + PHP syntax check
make train         # train on synthetic data
make train-db      # train on the database (+ synthetic augmentation)
make api           # start the ML service
make serve         # PHP development server on :8080
make seed          # regenerate database/seed_demo.sql
```

## Technology

PHP 8 · MySQL/MariaDB · Chart.js · Python 3 · pandas · scikit-learn · SciPy · Flask · pytest.

## License and credits

Originally developed as a BSc IT minor project by Suzzan Patel. Bundled front-end libraries keep
their own licenses (see `web/assets/vendor/README.md`).
