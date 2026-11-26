# Setup guide

This guide gets FiscalPoint running locally: the PHP web app, the database and the Python ML service.

## 1. Prerequisites

| Component | Version | Notes |
|---|---|---|
| PHP | 8.1+ | Extensions: `mysqli`, `curl`, `mbstring`, `gd`, `zip`, `xml` (all included in XAMPP) |
| Composer | 2.x | https://getcomposer.org |
| MySQL / MariaDB | MySQL 8.0.16+ or MariaDB 10.4+ | XAMPP ships MariaDB |
| Python | 3.10+ | For training and serving the models |
| Git | any | |

## 2. Get the code and PHP dependencies

```bash
git clone https://github.com/Suzzanpatel/FiscalPoint.git
cd FiscalPoint
composer install
```

With XAMPP, clone into `htdocs` (e.g. `C:\xampp\htdocs\FiscalPoint`). The root `.htaccess` makes only
the `web/` folder reachable over HTTP; everything else (`.env`, SQL, ML code, models, `vendor/`, `.git`)
is blocked. If you control the Apache configuration, pointing `DocumentRoot` at `web/` is even better.

## 3. Configure `.env`

```bash
cp .env.example .env
```

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | Database used by both PHP and Python |
| `APP_URL` | Public URL of `web/`; used in password-reset emails |
| `APP_TIMEZONE` | One timezone for PHP, the MySQL session and the ML service (default `Asia/Kolkata`) |
| `APP_DEBUG` | `true` shows PHP errors in the page. Keep `false` outside development |
| `SMTP_HOST`, `SMTP_PORT`, `EMAIL_USERNAME`, `EMAIL_PASSWORD` | Outgoing mail for password reset and emailed reports (Gmail needs an App Password) |
| `ML_API_URL` | Where PHP reaches the ML service (default `http://127.0.0.1:5001`) |
| `ML_API_TOKEN` | Shared secret; PHP sends it as `X-API-Key`. Generate: `python -c "import secrets; print(secrets.token_urlsafe(32))"` |
| `ML_API_HOST`, `ML_API_PORT` | Bind address of the ML service |
| `ML_API_ALLOW_REMOTE` | `true` only if the ML service runs on another machine (requires `ML_API_TOKEN`) |
| `ML_DATA_SOURCE` | `mysql` (default) or `csv` to serve predictions from a CSV file without a database |
| `ML_MODEL_DIR`, `ML_CSV_PATH`, `ML_LOG_LEVEL` | Optional overrides |

`.env` is git-ignored. Never commit real credentials.

## 4. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE FiscalPoint CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root -p FiscalPoint < database/schema.sql
mysql -u root -p FiscalPoint < database/seed_demo.sql      # optional demo data
```

In phpMyAdmin: create the `FiscalPoint` database, then *Import* `schema.sql`, then `seed_demo.sql`.

Upgrading a database created from the old `FiscalPoint.sql` dump? Use
`database/migrations/001_ml_overhaul.sql` instead, see [DATABASE.md](DATABASE.md).

## 5. Install the ML package and train

```bash
python -m venv .venv
source .venv/bin/activate              # Windows: .venv\Scripts\activate
pip install -r ml/requirements.txt

cd ml
python -m fiscalpoint_ml.train --source db --augment-synthetic
```

Training sources:

| Command | Use when |
|---|---|
| `python -m fiscalpoint_ml.train` | No database needed; trains on generated synthetic data |
| `... --source db` | Your database has plenty of history (dozens of users, 12+ months) |
| `... --source db --augment-synthetic` | **Recommended**: real data plus synthetic users, so a small database still trains well |
| `... --source csv --csv path/to/transactions.csv` | Offline experiments on an exported dataset |

Training takes about a minute. It writes `models/*.joblib`, `models/metadata.json` and the
evaluation report `reports/REPORT.md` with charts. Retrain whenever enough new data has
accumulated (monthly is plenty), then restart the service.

## 6. Run

Two processes:

```bash
# terminal 1: ML service (from ml/)
python -m fiscalpoint_ml.api

# terminal 2: web app (or just use XAMPP's Apache)
php -S 127.0.0.1:8080 -t web
```

Then open `http://127.0.0.1:8080` (or `http://localhost/FiscalPoint/web/` with XAMPP).
Log in with `demo.user1@fiscalpoint.test` / `Demo@1234`, or the admin account
`admin@fiscalpoint.test` / `Admin@1234`.

The web app keeps working if the ML service is down: the dashboard shows "ML service offline",
the predictions page shows start-up instructions, and the add-expense form simply shows no suggestions.

## 7. Tests

```bash
make test               # or: cd ml && python -m pytest -q
```

The Python suite (43 tests) covers data cleaning, leakage-free features, every model, the
registry and every API endpoint including its security checks. `make lint-php` syntax-checks all PHP files.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Missing PHP dependencies" | Run `composer install` in the project root |
| "Database configuration is incomplete" | Create `.env` from `.env.example` |
| Predictions page says *ML service: Offline* | Start `python -m fiscalpoint_ml.api` from `ml/`; check `ML_API_URL` |
| "Unauthorized" from the ML service | `ML_API_TOKEN` differs between the PHP and Python processes; both read the same `.env`, so restart the service after editing it |
| "The forecaster model is not trained yet" | Run `python -m fiscalpoint_ml.train` |
| "Not enough history yet" on predictions | The user needs at least one complete month of expenses |
| Warning about scikit-learn version mismatch | Retrain after upgrading scikit-learn |
| Password-reset link expired immediately | Make sure `APP_TIMEZONE` is set; PHP and MySQL must agree on the clock |
| Import of the old `FiscalPoint.sql` failed | Expected: that dump referenced tables before creating them. Use `database/schema.sql` |
