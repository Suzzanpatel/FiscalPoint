# FiscalPoint — Code Review and Remediation Register

**Review basis:** static inspection of the repository's current `main` branch. No live deployment, database instance, or end-to-end browser session was available during this review. Findings below distinguish directly observed issues from risks requiring runtime verification.

## Executive assessment

FiscalPoint demonstrates a coherent full-stack project: PHP/MySQL transaction management, reporting and exports, and a separate Python forecasting service. The forecasting code has a useful methodological feature: expanding-window, one-month-ahead backtesting against naive and moving-average baselines.

The main weakness is not lack of features. It is that the application has not yet been hardened as a system: sample data and schema integrity are inconsistent, account and session protections need work, and the PHP/Python boundary has operational and trust assumptions that should be made explicit.

## Strengths

1. **End-to-end product scope.** Users can record income and expenses, create budgets, inspect reports and obtain forecasts in one application.
2. **Separation of responsibilities.** PHP handles application workflows while Python handles forecasting and numerical evaluation.
3. **Prepared statements in reviewed transaction/authentication paths.** The reviewed login, expense and income queries use parameter binding.
4. **Password hashing.** Registration uses `password_hash`; login uses `password_verify`.
5. **Per-user query filtering in forecasting.** The forecast data query filters on the supplied user ID.
6. **Chronological evaluation.** The backtest uses only prior observations to predict the next month, avoiding the obvious error of fitting on the full series before scoring earlier months.
7. **Baseline comparison.** Linear regression is not automatically assumed to be best; it must beat both simple baselines on MAE.
8. **Some automated tests exist.** Tests cover missing-month aggregation, method selection, loopback rejection and safe database-probe error responses.

## High-priority findings

### FP-01 — Public registration form exposes an administrator option
**Evidence:** `Frontend/signup.php` renders a role selector with a user and administrator option. The backend currently hard-codes `$role = "user"`, so the option is misleading and the backend assignment is the correct security behaviour.

**Impact:** If a future developer trusts the submitted role field or changes the backend to accept it, any visitor could self-register as an administrator.

**Remediation:** Remove the role selector from the public form and retain server-side assignment of the `user` role. Administrator creation should be a separate protected administrative operation.

### FP-02 — Password is HTML-escaped before verification
**Evidence:** `Frontend/login.php` passes the submitted password through an HTML sanitisation helper before calling `password_verify`.

**Impact:** Passwords containing characters such as ampersands or angle brackets may be transformed and fail authentication. Passwords are secrets, not HTML output; they should not be HTML-escaped before verification.

**Remediation:** Pass the submitted password string unchanged to `password_verify`. Validate its type/length, and escape only when rendering output (which should not include passwords).

### FP-03 — Public SQL dump contains identifiable account data
**Evidence:** `FiscalPoint.sql` inserts user names, email addresses, phone numbers and password hashes, alongside transaction samples. It also includes records with NULL user IDs.

**Impact:** Publishing identifiable data is a privacy risk even when password hashes are not plaintext. Test fixtures should never contain real personal records.

**Remediation:** Replace the dump's sample rows with synthetic, clearly fictional data or publish a schema-only dump plus a separate synthetic seed script. Rotate any credentials if any included account was ever used outside a disposable test environment. Do not expose or reuse the included hashes.

### FP-04 — Database integrity does not enforce intended one-record-per-month rules
**Evidence:** `Budget` and `Income` use string month fields; no unique constraint on (Uid, Month) is defined. The supplied Budget data contains duplicate rows for the same user and month.

**Impact:** Concurrent requests or bypassing the front-end check can create duplicates. A budget lookup using `fetch_assoc()` can then select an arbitrary duplicate amount, making remaining-budget calculations unreliable.

**Remediation:** Clean duplicate records, then add unique constraints on `Budget(Uid, Month)` and `Income(Uid, Month)`. Make `Uid` NOT NULL for user-owned financial rows. Add server-side duplicate handling for duplicate-key errors.

### FP-05 — Expense rows permit invalid ownership and values
**Evidence:** The dump allows nullable `Expense.Uid`, category, amount and date; sample data includes NULL UIDs and zero amounts.

**Impact:** Unowned or invalid transactions can contaminate reports and forecasting, and weaken auditability.

**Remediation:** Clean or remove invalid rows, then make required fields NOT NULL, enforce positive amounts and retain a valid user foreign key.

## Medium-priority findings

### FP-06 — Date validation is not strict enough
**Evidence:** `Frontend/addexpense.php` uses `DateTime::createFromFormat('Y-m-d', ...)` without checking warnings/errors or round-tripping the formatted date.

**Impact:** Some impossible or normalised dates may be accepted.

**Remediation:** Parse with `!Y-m-d`, check `getLastErrors()`, and require the formatted result to equal the submitted value.

### FP-07 — Budget enforcement can be inconsistent with duplicate data and concurrent writes
**Evidence:** Expense insertion reads the current budget and current total before inserting. The check and insert are not in a transaction, and duplicate budget rows are possible.

**Impact:** Concurrent expense submissions can both pass a budget check and jointly exceed the budget. The current implementation also rejects an expense larger than the remaining budget, which is a product rule that should be documented and tested.

**Remediation:** After adding the unique budget constraint, use a transaction and an appropriate locking strategy for budget-sensitive writes, or clearly treat the budget as an alert rather than a hard spending limit.

### FP-08 — Password reset is environment/deployment dependent
**Evidence:** `Frontend/forgot_password.php` builds a hard-coded localhost reset URL, stores the raw reset token, and relies on SMTP variables. The reset link will not work unchanged outside the local XAMPP path.

**Impact:** Deployment breaks unless the URL is changed; raw database access exposes valid reset tokens.

**Remediation:** Use a configured application base URL, store a hash of the reset token, enforce single use and expiry, invalidate old tokens, and return a generic response regardless of whether the email exists.

### FP-09 — Multiple Python server implementations create ambiguity
**Evidence:** `Frontend/predictions.py` contains the actual forecasting and evaluation logic. `Frontend/localhost_server.py` contains a separate stub prediction endpoint returning a hard-coded test response. `run_prediction_server.php` has a stale port 5000 status link while the actual app uses port 5001.

**Impact:** Developers can start the wrong service or follow misleading operational instructions.

**Remediation:** Keep one canonical service, remove or clearly label the stub, use one port constant/configuration source, and do not start background services through a web-accessible PHP script.

### FP-10 — Loopback check is not a full authentication design
**Evidence:** Flask prediction routes accept `user_id` in JSON and rely on `REMOTE_ADDR` being loopback. The PHP caller passes the session ID, but the Python service does not independently verify a signed identity assertion.

**Impact:** This is acceptable only as a tightly local development arrangement. It is not sufficient if the service is exposed, proxied, containerised or otherwise reachable by untrusted clients.

**Remediation:** Keep the service bound to loopback, firewall it, and use a shared secret or signed short-lived service token between PHP and Python if the deployment boundary changes. Never trust a browser-supplied user ID.

### FP-11 — Prediction quality and communication need improvement
**Evidence:** The model uses monthly category totals, zero-fills missing months and compares three simple methods. It reports MAE but no uncertainty interval. The README states the sample dataset contains only one month.

**Impact:** A numerical forecast can look precise despite insufficient or unrepresentative data. Zero-filling a missing month assumes no spending occurred; this may be false if data entry was incomplete.

**Remediation:** Show history length and backtest sample count, suppress or label forecasts with insufficient history, add prediction uncertainty or at least historical error context, and distinguish a true zero-spend month from a month with missing/incomplete data.

### FP-12 — Automated test coverage is narrow
**Evidence:** Current tests focus on Python forecasting and service routes. No equivalent suite is visible for PHP authentication, ownership checks, budgets, income, database constraints, report calculations or exports.

**Impact:** Important user-facing workflows can regress without detection.

**Remediation:** Add PHP linting, Python unit tests, database integration tests with synthetic fixtures, and end-to-end tests for registration/login, user isolation, expense entry, budgets and reports.

## Recommended implementation order

| Priority | Work | Acceptance condition |
|---|---|---|
| P0 | Remove public admin-role selector; preserve server-side user role | Registration cannot create an admin |
| P0 | Stop escaping password before verification | Passwords containing special characters authenticate |
| P0 | Replace identifiable SQL seed data | No real names, emails, phones or reused hashes in public fixtures |
| P1 | Clean data and add database constraints | No duplicate user-month budgets/income; financial rows have valid owners |
| P1 | Strict date and amount validation | Invalid dates and non-positive amounts are rejected |
| P1 | Fix reset-token lifecycle and configurable URL | Single-use, hashed, expiring tokens; environment-specific base URL |
| P1 | Consolidate Python service and port configuration | One documented service and no stale port instructions |
| P2 | Improve forecast transparency and data sufficiency | UI explains history length, MAE and forecast limitations |
| P2 | Expand automated test coverage and CI | Core workflows run automatically against disposable test data |

## Review limitations

- This was a static repository inspection, not a penetration test.
- The database dump was inspected, but no live database was migrated.
- PHP workflows were not executed in XAMPP.
- No browser-based functional test or load/concurrency test was performed.
- The findings should be revalidated after remediation and against the actual deployment configuration.
