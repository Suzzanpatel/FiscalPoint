# FiscalPoint — Personal Finance and Expense Forecasting

FiscalPoint is a web-based personal finance application for recording expenses and income, setting monthly budgets, reviewing spending reports, exporting records, and generating category-wise estimates of next month's expenses.

## Features

- User registration and login
- Expense entry with category and payment method
- Monthly income and budget management
- Dashboard, calendar, category and graphical reports
- Excel/PDF export dependencies
- Category-wise expense forecasting using naive, three-month moving-average and linear-regression candidates
- Expanding-window, one-month-ahead backtesting with Mean Absolute Error (MAE)

## Technology

- Frontend: HTML, CSS, JavaScript
- Backend: PHP
- Database: MySQL / MariaDB
- Forecast service: Python, Flask, pandas, NumPy, scikit-learn, Matplotlib
- PHP dependencies: Composer
- Local development: XAMPP

## Local setup

### Requirements
- XAMPP (Apache and MySQL/MariaDB)
- PHP and Composer
- Python 3 and pip

### Install

1. Clone the repository into the XAMPP web root.
2. Start Apache and MySQL.
3. Create a database named `FiscalPoint` and import `FiscalPoint.sql`.
4. From the repository root, run:

   ```bash
   composer install
   ```

5. Create a root-level `.env` file:

   ```dotenv
   DB_HOST=127.0.0.1
   DB_USER=your_database_user
   DB_PASSWORD=your_database_password
   DB_NAME=FiscalPoint
   EMAIL_USERNAME=your_smtp_account
   EMAIL_PASSWORD=your_smtp_app_password
   ```

6. Install Python packages:

   ```bash
   python3 -m pip install flask flask-cors pandas numpy scikit-learn matplotlib mysql-connector-python python-dotenv
   ```

7. Start the prediction service in a terminal from the repository root:

   ```bash
   python3 Frontend/predictions.py
   ```

8. Open `http://localhost/FiscalPoint/Frontend/landing.html`.

The prediction service listens on `127.0.0.1:5001`. Verify it at `http://127.0.0.1:5001/`.

## Forecasting methodology

Expense rows are grouped into monthly totals for each category. Missing calendar months within the observed range are currently filled with zero. Three candidates are compared:

- **Naive:** repeats the latest monthly total.
- **Three-month moving average:** averages the latest three monthly totals.
- **Linear regression:** fits a linear trend over a sequential monthly index.

An expanding-window backtest predicts each subsequent month using only earlier observations. The method with the lowest MAE is selected, except linear regression is selected only when it beats both baselines strictly. The current sample SQL data contains only a short history and is not suitable for evaluating real forecast performance. Forecasts are estimates, not financial advice.

## Documentation

- [System documentation](docs/SYSTEM_DOCUMENTATION.md)
- [Code review and remediation register](docs/SECURITY_AND_REMEDIATION.md)

## Security and data note

Do not commit `.env`, real financial records, personal contact details or production password hashes. The included SQL dump should be treated as development data and sanitised before public distribution. Do not expose the Flask development service to a public network. Review the remediation register before treating the application as production-ready.

## Project status

This repository is an academic/personal project. The documentation describes the code currently present; it does not imply that all modules have passed integration, security or production-readiness testing.
