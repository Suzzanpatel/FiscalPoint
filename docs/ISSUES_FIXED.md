# Issues found and fixed

An audit of the original repository (commit `d1ee723`). Each entry lists the problem, its impact
and the fix. Severity: **Critical** (exploitable or data-destroying), **High** (security weakness or
broken feature), **Medium** (wrong results or fragile), **Low** (quality / maintainability).

## 1. Security

| # | Sev. | Where | Problem | Fix |
|---|---|---|---|---|
| S1 | Critical | `run_prediction_server.php` | Any visitor could make the web server spawn Python processes via `shell_exec` (unauthenticated). It also checked the wrong port and wrong import names | File removed. The ML service is started from the command line |
| S2 | Critical | `minimal_server.py`, `localhost_server.py` | Extra Flask servers with `debug=True` (the Werkzeug debugger allows remote code execution), `CORS(*)` and error messages echoing exceptions | Both removed. The single service never runs in debug mode and returns generic errors |
| S3 | High | Every form | No CSRF protection on any state-changing action (delete user/admin/expense, reset budget, rename categories, add admin…) | Per-session CSRF token on every POST form and AJAX call (`csrf_field()`, `is_post()`, `X-CSRF-Token`) |
| S4 | High | `tabularreport.php` | Reflected XSS: `date_order`, `amount_order`, `payment_type` and `month` echoed unescaped into hidden inputs | All output escaped with `e()`; filters whitelisted (`includes/expense_filters.php`) |
| S5 | High | `piegraph.php`, `calendarview.php` | Reflected XSS via unvalidated `month` (also echoed into a script) | Month validated with `valid_month()`, output escaped |
| S6 | High | `calendarview.php` | DOM XSS: expense categories inserted with `innerHTML` | Popup built with `textContent` |
| S7 | High | `admin/edit_user.php` | `redirect_page` POST value injected into `window.location.href='…'` (XSS / open redirect) | Whitelisted target, JSON-encoded |
| S8 | High | `add_admin.php` | Creating an admin **replaced the current admin's session** with the new account; role taken from the request | Session untouched; role forced to `admin`; password policy enforced |
| S9 | High | `signup.php` | Sign-up form offered an "Administrator" role (ignored by the server, but it invited privilege-escalation attempts) | Option removed |
| S10 | High | `forgot_password.php` | Reset tokens stored in plain text; account enumeration (different messages for known/unknown emails); reset URL hard-coded to `localhost` | SHA-256 of the token stored; identical response; `APP_URL` setting; token single-use |
| S11 | High | `login.php` | Different messages for "invalid email" and "invalid password" (enumeration); no brute-force limit | Generic message; 5 failed attempts per session trigger a 5-minute lock |
| S12 | High | `manage_admin.php`, `admin_registered_users.php` | An admin could delete themselves or the last admin; the user list showed and deleted admins too | Self-deletion and last-admin deletion blocked; user list shows only users |
| S13 | Medium | `predictions.php` | The browser polled `http://127.0.0.1:5001` (the *visitor's* machine) and diagnostics leaked the raw server response and user id | Server-side calls only (`includes/ml_client.php`); no diagnostics shown to users |
| S14 | Medium | ML service | Accepted any local caller with no authentication; OPTIONS handler returned `Access-Control-Allow-Origin: *` | Optional shared `ML_API_TOKEN` (constant-time compare); CORS removed (not needed) |
| S15 | Medium | Sessions | Default cookie flags | HttpOnly, SameSite=Lax, strict mode, Secure on HTTPS; session regenerated on login |
| S16 | Medium | `query.php` | Reply email taken from a free-text field (anyone could impersonate any address); DB error text echoed in an alert | Email taken from the session; errors not exposed |
| S17 | Medium | `.htaccess` | Only `.env` and `.sql` were protected: `vendor/`, `.git`, Python code and models were web-accessible when the repo sits in `htdocs` | Only `web/` is reachable; `web/includes/` denied; directory listings disabled |
| S18 | Medium | `predictions.py` | DEBUG logging wrote users' financial records to the log | Removed; INFO-level logging without data |
| S19 | Medium | Sample data | `FiscalPoint.sql` shipped real-looking names, email addresses, phone numbers and password hashes | Replaced by a synthetic seed generator (`database/seed_demo.sql`) |
| S20 | Low | `export_report.php` | User data written into the PDF HTML unescaped; page reachable without the auth redirect | Escaped; `require_login()`; CSRF; temp files no longer written to disk |

## 2. Bugs and wrong behaviour

| # | Sev. | Where | Problem | Fix |
|---|---|---|---|---|
| B1 | Critical | `FiscalPoint.sql` | Import failed: `Income` and `Query` declared foreign keys to `User` before `User` existed | New `database/schema.sql` in dependency order (verified on MariaDB) |
| B2 | High | Auth | Passwords were passed through `htmlspecialchars()` before hashing at sign-up/login but not at password reset. Users with `&`, `<`, `>`, `"` or `'` in their password were **locked out** after a reset | Raw passwords everywhere; login still accepts legacy-hashed passwords once and rehashes them |
| B3 | High | Password reset | PHP wrote `token_expires` on its own clock, MySQL compared it with `NOW()` in another timezone, so fresh links could be already expired | Expiry computed by MySQL; one `APP_TIMEZONE` for PHP, the MySQL session and Python |
| B4 | High | `manage_Admin.php` | File name differed in case from every link (`manage_admin.php`): 404 on Linux | Renamed |
| B5 | High | `addexpense.php` | Expenses above the remaining budget were **rejected** (real spending silently not recorded, which distorted reports and predictions); the check used the current month's budget even for expenses dated in other months; JS cleared the amount field with an alert on every keystroke | Expense always saved; warning based on the expense's own month; non-blocking inline warning |
| B6 | High | `admin_query.php` | Changing a query's status did nothing (select had no submit); responding overwrote the response with an empty string | Auto-submit; status-only changes keep the existing response |
| B7 | Medium | `insights.php` | Division by zero when all amounts were 0; "Over Budget" shown when no budget existed; tips keyed on categories that don't exist (`Dining`, `Shopping`, `Transport`) | Guards; "No Budget Set" state; tips for the real categories |
| B8 | Medium | `setbudget.php`, `addincome.php` | Reset always reported success; past months could not get an income entry (needed for history); budget check AJAX was never called | Real success / not-found responses; past income allowed; month change triggers the check |
| B9 | Medium | `calendarview.php` | `GROUP BY` with a non-aggregated column fails under MySQL's `ONLY_FULL_GROUP_BY`; totals with thousands separators parsed as 1 (`parseFloat("1,234")`); month navigation stuck in negative-UTC timezones; needed `ext-calendar` | `GROUP_CONCAT`; raw numbers in data attributes; arithmetic month stepping; `date('t')` |
| B10 | Medium | `tabularreport.php` | Calendar button nested inside the Excel button (invalid HTML; clicking it downloaded Excel); "Credit / Debit Card" filter never matched stored "Credit / Debit card"; "Other" filter matched nothing | Separate actions; consistent values; "Other" = any custom method |
| B11 | Medium | `download_excel.php` | Amounts written as formatted text, so Excel could not sum them | Numeric cells, number format, total row |
| B12 | Medium | `admin_category.php` | Edit row selected by display index (wrong row after reordering); `<form>` inside `<tr>` (invalid); `window.location.reload()` after POST re-submitted the delete; deleted categories moved to "Other" | Edit by name; valid markup; redirect after POST; moved to "Miscellaneous" |
| B13 | Medium | `dashboard.php` | Stray `</div>`; clicking a card called an undefined `toggleCalendar()`; unused jsPDF/html2canvas loaded | Fixed markup; card opens the calendar view; unused libraries removed |
| B14 | Medium | `query.php` | Loaded `addexpense.js`, which threw errors on that page; title "Add Expense" | Script removed; users now see their previous queries and responses |
| B15 | Medium | Layout (all CSS) | `body { height: 100vh }` with flex centring pushed tall pages above the top of the window, where they could not be scrolled to (e.g. the predictions page) | `min-height: 100vh`; sidebar anchored to the top |
| B16 | Medium | Front end | Chart.js loaded unpinned from a CDN (`npm/chart.js` = latest major), so a future release could break every chart; the app needed internet access | Pinned copies in `web/assets/vendor/` |
| B17 | Low | `signup.js` | Password regex rejected valid characters (`#`, `^`, spaces…) and differed from the server rules | Same policy on client and server (8+ chars, upper, lower, digit) |
| B18 | Low | `delete_account.php` | No JSON content type; an admin could delete the last admin account | Fixed; last-admin guard |
| B19 | Low | Dead code | `linegraph.js` (called a non-existent `fetch_expense.php`), `setbudget.js` (localStorage budgets conflicting with the server), duplicate prediction scripts | Removed |

## 3. Data model

| # | Problem | Fix |
|---|---|---|
| D1 | `Expense.Uid` nullable: orphaned expenses existed | NOT NULL + FK; migration removes orphans |
| D2 | Duplicate budgets for the same user and month (e.g. user 12, 2025-03 twice) | `UNIQUE (Uid, Month)` on `Budget` and `Income` |
| D3 | `User.email` not unique (only checked in PHP) | Unique key |
| D4 | Zero-amount expenses | `CHECK (amount > 0)` plus validation |
| D5 | No indexes for the per-user date queries | `(Uid, date)` index |

## 4. Machine learning (v1 prediction service)

| # | Problem | Fix |
|---|---|---|
| M1 | One month of data for two users: no model could be trained or evaluated ("N/A" everywhere) | Realistic synthetic data generator; database + synthetic training |
| M2 | Per-user, per-category model choice among three very simple methods; nothing learned across users | Global gradient-boosted model with 20 features, compared against four baselines |
| M3 | No uncertainty: one number per category | 80 % prediction intervals (quantile models), calibrated in backtest |
| M4 | Forecast for "the month after the last month with data": a half-finished current month was treated as complete | Forecasts built only from complete months; current-month spending feeds the budget-risk model |
| M5 | PHP parsed the forecast out of an emoji-decorated text summary with regexes | Structured JSON API |
| M6 | Predictions rendered as base64 matplotlib PNGs (slow, not interactive) | Data returned as JSON; Chart.js renders it |
| M7 | No model persistence or versioning: everything was recomputed on each request | Model registry with metadata, versions and metrics |
| M8 | Tests imported the stub servers and only checked a few helpers | 43 tests: leakage, models, registry, API security, error handling |

## 5. Code organisation

| Problem | Fix |
|---|---|
| 30+ PHP files, CSS, images, JS, Python and tests all in `Frontend/` | `web/` (pages), `web/admin/`, `web/api/`, `web/includes/`, `web/assets/{css,js,img,vendor}`; `ml/`; `database/`; `docs/`; `models/`; `reports/` |
| Sidebar HTML copy-pasted into ~25 pages | `includes/sidebar_user.php` / `sidebar_admin.php` |
| Session, DB and auth boilerplate repeated (and inconsistent) in every page | `includes/bootstrap.php` with `require_login()`, `require_admin()`, `e()`, `js()`, `csrf_*()`, `url()`, `asset()` |
| Images stored under `css/` | `assets/img/` |
| README described a `.env` but there was no template | `.env.example` documenting every key |
| No contributor tooling | `Makefile`, `pyproject.toml`, `requirements.txt` |

## Known remaining limitations

* The login throttle is per session, so a client that drops cookies can bypass it. Use a web-server
  or WAF rate limit in production.
* Logging out uses a GET link (logout CSRF is low impact but possible).
* The original page CSS (fixed-width sidebars, `%` margins) is not responsive on phones.
* See [MODEL_CARD.md](MODEL_CARD.md) for model limitations.
