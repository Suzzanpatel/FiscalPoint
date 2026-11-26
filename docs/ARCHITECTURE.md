# Architecture

## Components

| Component | Location | Responsibility |
|---|---|---|
| Web application | `web/` (PHP 8) | Accounts, expenses, income, budgets, reports, admin, rendering ML results |
| Database | MySQL / MariaDB (`database/schema.sql`) | Single source of truth for users and transactions |
| ML package | `ml/fiscalpoint_ml/` (Python) | Data preparation, features, models, evaluation, training CLI |
| ML service | `ml/fiscalpoint_ml/api.py` (Flask) | Serves predictions to the web app over local HTTP |
| Model registry | `models/` | Trained artifacts + `metadata.json` (versions, data summary, metrics) |
| Reports | `reports/` | Evaluation report and charts produced by each training run |

## Request flow

1. The browser requests a page, e.g. `predictions.php`.
2. `includes/bootstrap.php` loads `.env`, starts a hardened session, opens the DB connection and
   checks authentication (`require_login()` / `require_admin()`).
3. The page calls `ml_request('/v1/forecast', ['user_id' => $uid])` in `includes/ml_client.php`
   (cURL with a short timeout and the `X-API-Key` header).
4. The ML service reads that user's transactions from MySQL (`data/repository.py`), builds features
   (`features.py`), runs the loaded model and returns JSON.
5. PHP renders the result (escaped) and Chart.js draws the charts from data embedded as JSON.

The add-expense form makes one AJAX call, to `api/ml_categorize.php`. That endpoint checks the
session and CSRF token, then proxies the request to `/v1/categorize`. The browser never sees the ML
service URL or the API key.

## Training flow

```
transactions (MySQL / CSV / synthetic)
  └─ normalize_transactions()          clean + type-cast, drop invalid rows
      ├─ monthly_panel() + add_features()      (user, category, month) panel, lag/rolling features
      │     ├─ rolling_origin_backtest()      model vs 4 baselines, out-of-time
      │     └─ GlobalForecaster.fit()         final model on all complete months
      ├─ categorizer.evaluate() + fit()       stratified hold-out
      ├─ AnomalyDetector.fit() + evaluate()   labelled synthetic anomalies
      └─ BudgetRiskModel.fit() + backtest()   curve + error scale from the backtest
  └─ registry.save()  → models/*.joblib, metadata.json
  └─ reporting        → reports/REPORT.md + PNG charts
```

Training is deliberately command-line only. The original project had a web page that spawned
Python processes with `shell_exec`; that was removed (see [ISSUES_FIXED.md](ISSUES_FIXED.md)).

## Security model

| Concern | Mechanism |
|---|---|
| Authentication | `password_hash` / `password_verify`; session id regenerated on login; generic error message; 5 failed attempts per session trigger a 5-minute lock |
| Sessions | HttpOnly, SameSite=Lax, strict mode, Secure when served over HTTPS |
| CSRF | Per-session token on every state-changing form and AJAX call (`csrf_field()`, `is_post()`, `X-CSRF-Token`) |
| XSS | All output goes through `e()` (HTML) or `js()` (JSON inside `<script>`); dynamic DOM uses `textContent` |
| SQL injection | Prepared statements everywhere; ORDER BY and filter values are whitelisted |
| Authorisation | `require_admin()` on all admin pages; users can only touch their own rows (`WHERE Uid = ?`) |
| Password reset | 256-bit random token, only its SHA-256 hash is stored, 1-hour expiry, single use, identical response for unknown emails |
| ML service | Bound to 127.0.0.1, rejects non-local clients, optional shared token (`hmac.compare_digest`), never runs in debug mode, errors never include internals |
| Files | `.htaccess` exposes only `web/`; `web/includes/` is denied |
| Secrets | `.env` only, git-ignored; `.env.example` documents every key |

## Folder conventions in `web/`

* Every page starts with `require_once __DIR__ . '/includes/bootstrap.php';` (`/../includes/` from sub-folders).
* Links and assets use `url('page.php')` and `asset('css/x.css')`, so pages work from `web/`, `web/admin/` and `web/api/`.
* Navigation comes from `includes/sidebar_user.php` / `includes/sidebar_admin.php`. It is no longer copy-pasted into 25 files.
* JSON endpoints live in `web/api/`; administrator pages live in `web/admin/`.
* Third-party front-end code is pinned in `web/assets/vendor/`, not loaded from CDNs.

## Timezones

`APP_TIMEZONE` is applied to PHP (`date_default_timezone_set`), to every MySQL session
(`SET time_zone`) and to the ML service (`config.today()`). "Today" and "this month" therefore mean
the same thing in PHP code, SQL (`NOW()`, `CURDATE()`) and the models.
