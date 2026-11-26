# FiscalPoint — System Documentation

> **Note (v2):** This document reviews the original code (the `Frontend/` folder and the old
> forecasting service) as it was before the ML overhaul. That code has since been reorganised into
> `web/` and `ml/`. The issues listed here are resolved; see [ISSUES_FIXED.md](ISSUES_FIXED.md)
> for the fix behind each one and [ARCHITECTURE.md](ARCHITECTURE.md) for the current design.

**Document status:** Repository-based technical documentation  
**Repository:** https://github.com/Suzzanpatel/FiscalPoint  
**Application type:** Local web-based personal finance and expense management system

## 1. Purpose and scope

FiscalPoint is a personal finance application intended to help an individual record income and expenses, establish monthly budgets, review spending patterns, export reports, and obtain category-level estimates of the following month's expenses.

This document describes the implementation visible in the repository. It is not a claim that every feature has been fully tested or is production-ready.

## 2. Technology stack

| Layer | Technology | Responsibility |
|---|---|---|
| User interface | HTML, CSS, JavaScript | Forms, navigation, charts and page interactions |
| Application backend | PHP | Authentication, session handling, financial CRUD operations, reports |
| Database | MySQL / MariaDB | User, expense, budget, income and support-query records |
| Forecasting service | Python, Flask | Reads expense history and returns forecasts and model evaluation |
| Data processing | pandas, NumPy | Monthly aggregation and numerical operations |
| Forecast model | scikit-learn LinearRegression | Linear-trend forecast candidate |
| Visualisation | Matplotlib; Chart.js in browser | Forecast comparison and report charts |
| PHP dependencies | Composer | PhpSpreadsheet, mPDF, PHPMailer, phpdotenv |
| Local runtime | XAMPP | Apache and MySQL/MariaDB |

## 3. High-level architecture

The system has two application runtimes:

1. **PHP web application.** The browser sends page requests and form submissions to PHP scripts under `Frontend/`. PHP checks the session, reads or writes MySQL data, and renders HTML.
2. **Python forecasting service.** The PHP predictions page sends the authenticated session's user ID to a Flask service at `http://127.0.0.1:5001`. The Python service retrieves that user's expenses, aggregates them by month and category, selects a forecasting method using historical backtesting, and returns JSON.

The Python service is a separate local process; it is not started automatically by visiting the predictions page. Start it separately as described below.

## 4. Functional modules

### 4.1 Accounts and access
- User registration and login.
- Password hashing/verification using PHP password functions.
- PHP session state stores the authenticated user's ID, name, email and role.
- Logout and password reset pages are present.
- Administrative pages exist for registered-user and category management.

**Important access-control note:** public registration must never allow a visitor to choose an administrator role. Administrative privileges must be assigned out-of-band by an authorised administrator.

### 4.2 Expense management
- Add an expense with category, amount, date, description and payment method.
- A custom category and custom payment method can be supplied through the “Other” option.
- The expense record is associated with the logged-in user's ID.
- The add-expense page checks the current month's budget before inserting a new expense.

### 4.3 Income management
- Record one monthly income amount per user and month (as intended by the interface).
- Reset a month's income record.
- Month values use the `YYYY-MM` format.

### 4.4 Budget management
- Set a monthly budget.
- Reset a budget for a selected month.
- The interface prevents selection of past months; server-side validation is also present.
- The application checks the current month's spending against the configured budget when adding an expense.

A database uniqueness constraint on `(Uid, Month)` is required to enforce one budget per user-month under concurrent requests. The current SQL dump does not define that constraint.

### 4.5 Reports and insights
The repository includes dashboard, calendar, line graph, pie graph, tabular, category-wise, insights, and export pages. Export dependencies indicate Excel and PDF support. The precise calculations and filters should be verified against the corresponding page queries before being treated as authoritative financial reporting.

### 4.6 Expense forecasting
The Python service exposes:
- `GET /` — service health response.
- `GET /test_db` — database connectivity probe.
- `POST /predict_budget` — category-wise next-month forecast.
- `POST /evaluate_model` — rolling-origin model evaluation.

Both POST routes are restricted by a loopback-address check in the current implementation. Requests use JSON containing `user_id`; the PHP page supplies the logged-in session's ID.

#### Forecast data preparation
1. Expense rows with missing date, category or amount are excluded.
2. Transactions are grouped by calendar month and category.
3. Missing months between the first and last observed month are filled with zero.
4. Each category is forecast separately.

#### Candidate methods
- **Naive:** next month equals the latest observed month.
- **Three-month moving average:** next month equals the mean of the latest three observations.
- **Linear regression:** fits a straight line against a sequential monthly time index and predicts the next point.

#### Selection and evaluation
An expanding-window, one-month-ahead backtest starts with three training observations. Mean Absolute Error (MAE) is calculated for each method. Linear regression is selected only if its MAE is strictly lower than both baseline methods; otherwise, the lower-error baseline is selected. If no backtest errors are available, the selection falls back to a baseline according to the series length.

**Interpretation:** these are simple time-series baselines and a linear trend model, not an AI system that understands a user's full financial circumstances. Forecasts are estimates, not financial advice. A short history, unusual one-off purchases, inflation, seasonality and changing behaviour can make predictions unreliable.

## 5. Data model

The SQL dump defines the following core tables:

| Table | Key fields | Purpose |
|---|---|---|
| `User` | `Uid`, `Uname`, `email`, `Password`, `Role` | Account identity and authentication data |
| `Expense` | `Eid`, `Uid`, `category`, `amount`, `date`, `description`, `Payment_Method` | Individual expense transactions |
| `Budget` | `Bid`, `Uid`, `Month`, `Amount` | Monthly budget per user |
| `Income` | `Iid`, `Uid`, `Month`, `Income` | Monthly income per user |
| `Query` | `Qid`, `Uid`, `Email`, `Query_type`, `Description`, `Status`, response fields | User support queries |

The SQL dump defines foreign keys from Expense, Budget, Income and Query to User, with cascading deletion. However, the Expense and Budget `Uid` columns are nullable in the dump. Budget and Income store month as a string; the intended canonical format is `YYYY-MM`.

### Data integrity gaps
- The dump contains expense rows with NULL user IDs and zero amounts.
- Budget sample data includes duplicate user-month entries.
- The schema does not enforce unique user email addresses or unique user-month budgets/income.
- Month values are strings rather than a dedicated date/month representation.
- Some historical test records contain personally identifiable sample data. Do not publish real user data or password hashes in a public repository.

## 6. Local installation

### Prerequisites
- XAMPP with Apache and MySQL/MariaDB.
- PHP version compatible with the installed Composer packages.
- Composer.
- Python 3.
- pip.

### Setup
1. Clone the repository and place it under the XAMPP web root.
2. Start Apache and MySQL from XAMPP.
3. Create a database named `FiscalPoint`.
4. Import `FiscalPoint.sql` into that database.
5. Run `composer install` from the repository root.
6. Create a root-level `.env` file:

```dotenv
DB_HOST=127.0.0.1
DB_USER=your_database_user
DB_PASSWORD=your_database_password
DB_NAME=FiscalPoint
EMAIL_USERNAME=your_smtp_account
EMAIL_PASSWORD=your_smtp_app_password
```

7. Install Python dependencies (a dedicated requirements file should be maintained):

```bash
python3 -m pip install flask flask-cors pandas numpy scikit-learn matplotlib mysql-connector-python python-dotenv
```

8. Start the Python service from the repository root:

```bash
python3 Frontend/predictions.py
```

9. Open `http://localhost/FiscalPoint/Frontend/landing.html`.

### Runtime checks
- PHP database configuration is loaded by `Frontend/db.php`.
- Python loads the same root-level environment file.
- Check the Python health endpoint at `http://127.0.0.1:5001/`.
- Check database connectivity at `http://127.0.0.1:5001/test_db`.
- The predictions page calls the prediction endpoint on port 5001.

Do not expose the Flask development server to a public network. Do not use real financial data in a demo or public test environment.

## 7. Configuration and operational notes
- Keep `.env` out of version control.
- Configure SMTP credentials only in environment variables.
- Use HTTPS, secure session-cookie settings, CSRF protection and production error handling before deployment.
- Do not enable PHP error display or Flask debug mode in production.
- Back up the database before applying schema migrations.
- The repository currently includes multiple Python server scripts. Treat `Frontend/predictions.py` as the documented forecasting service because it contains the backtesting implementation and is the file referenced by the predictions page.

## 8. Known limitations and outstanding work
1. Database sample data is sparse and includes inconsistent records; it is unsuitable for demonstrating forecast quality.
2. Forecasts require sufficient monthly history; with fewer than four observations there is no one-step backtest error.
3. Forecast results do not provide prediction intervals or calibrated uncertainty.
4. The forecasting service accepts a user ID from the request body. Loopback-only access is not a complete service-authentication mechanism if the service is ever exposed beyond a trusted local setup.
5. The SQL schema needs non-null, uniqueness and validation constraints after existing data is cleaned.
6. Password reset uses an environment-configured SMTP account, but reset-link construction is hard-coded to a local URL and token storage should be hardened.
7. There is no comprehensive automated test suite for PHP pages, database isolation, authentication, reporting or end-to-end workflows.
8. The repository needs a consistent Python dependency lock/requirements file, deployment instructions, and CI checks.

## 9. Recommended verification checklist
- Register a normal user and confirm the role is always `user`.
- Confirm a user cannot read or change another user's expenses, income, budget, reports or profile.
- Confirm passwords with special characters authenticate correctly.
- Reject zero, negative, malformed and impossible dates.
- Confirm a user cannot create two budgets or income records for the same month.
- Confirm budget checks remain correct when multiple expenses are added.
- Confirm predictions use only the authenticated user's records.
- Test prediction outputs with sparse, zero-spend, missing-month, increasing, decreasing and irregular histories.
- Verify that no secrets or identifiable sample data are committed.
- Run PHP syntax checks, Python unit tests and an end-to-end test against a disposable database.
