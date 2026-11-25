# FiscalPoint – Personal Expense Tracker

FiscalPoint is a web-based personal finance and expense management application developed as a BSc IT minor project. It provides users with a structured platform to record daily expenses, manage monthly budgets, monitor spending patterns, and generate financial reports.

The project demonstrates the practical application of web development, database management, and predictive analytics to personal financial management.

Features

- Expense Tracking: Add, manage, and monitor daily expenses.
- Expense Categorisation: Organise transactions into categories for better financial visibility.
- Budget Management: Set monthly budgets and track spending against allocated limits.
- Income Management: Record and manage monthly income.
- User Accounts: Maintain individual user accounts and financial records.
- Financial Reports: Generate reports to analyse spending patterns.
- Data Export: Export financial records to Excel and PDF.
- Expense Prediction: Forecast future category-wise expenses using historical spending data and evaluate prediction accuracy through backtesting.

Technology Stack

 Frontend - HTML, CSS, JavaScript 
 Backend - PHP 
 Database - MySQL 
 Prediction Service - Python 
 Dependency Management -  Composer 
 Local Development - XAMPP 

Installation and Setup

Prerequisites

Ensure the following are installed:

- XAMPP (Apache and MySQL)
- PHP
- Composer
- Python
- Git

1. Clone the Repository

```bash
git clone https://github.com/Suzzanpatel/FiscalPoint.git
```

2. Navigate to the Project Directory

```bash
cd FiscalPoint
```

3. Install PHP Dependencies

```bash
composer install
```

4. Configure the Database

    1. Start Apache and MySQL using XAMPP.
    2. Open phpMyAdmin at `http://localhost/phpmyadmin`.
    3. Create a MySQL database named `FiscalPoint`.
    4. Import the `FiscalPoint.sql` file into the database.

5. Configure Environment Variables

Create a `.env` file in the project root directory and configure your database credentials:

```env
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=FiscalPoint
```

The PHP application and Python prediction service read their database configuration from these environment variables.

**Important:** The `.env` file is excluded from Git for security reasons. Configure the same database values for both local runtimes. Never commit database credentials to the repository.

6. Launch the Application

Open the following URL in your browser:

```text
http://localhost/FiscalPoint/Frontend/
```

Expense Prediction Methodology

FiscalPoint includes a category-wise expense forecasting mechanism that evaluates historical spending patterns to predict future monthly expenses.

Data Preparation

- Expense transactions are aggregated into monthly totals for each category.
- Missing months are represented by zero.
- Monthly observations are evaluated chronologically.

Forecasting Models

Three forecasting approaches are evaluated:

1. **Naive Forecast:** Uses the previous month's expense as the next month's prediction.
2. **Three-Month Moving Average:** Uses the average of the previous three months.
3. **Linear Regression:** Models the relationship between monthly expense totals and a continuous month index (0, 1, 2, ...).

Model Evaluation

An expanding-window, one-month-ahead backtesting approach is used.

- The first three monthly observations are used to initialise the forecasting methods.
- Each subsequent month is predicted using only previously available observations.
- Mean Absolute Error (MAE) measures the accuracy of each forecasting method.
- Linear regression is selected only when its rolling MAE is strictly lower than both baseline methods. Otherwise, the better-performing baseline is selected.

Sample Data Limitations

The included SQL sample currently contains expense records for only one month. Therefore, reliable rolling prediction errors cannot yet be calculated.

At least four consecutive monthly observations per category are required to generate one backtest error.

The `/evaluate_model` endpoint returns category-wise and overall rolling MAE results when sufficient historical data is available.

`N/A` indicates insufficient historical observations, not zero prediction error.

Database Migration Considerations

Budget and income records store their month in `YYYY-MM` format.

If an existing database contains legacy records storing only month names, the correct year must be assigned manually before deploying this version.

Legacy month values cannot be migrated automatically because they do not contain enough information to identify the correct year.

 Project Structure

```text
FiscalPoint/
│
├── Frontend/          # User interface
├── vendor/            # Composer dependencies
├── FiscalPoint.sql    # Database structure and sample data
├── composer.json      # PHP dependency configuration
├── composer.lock      # Locked dependency versions
├── .env               # Local environment configuration
└── README.md           # Project documentation
```

