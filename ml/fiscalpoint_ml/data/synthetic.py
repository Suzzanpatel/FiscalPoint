"""Realistic synthetic personal-finance data.

Why: the original project shipped one month of data for two users, which is far too
little to train or honestly evaluate any model. This generator produces multi-year
transaction histories with the structure real household spending has:

* recurring fixed payments (rent, EMI, SIP, insurance premiums) on fixed days
* variable spending with per-user levels, gradual inflation and noise
* Indian seasonality: Diwali gifts (Oct/Nov), school fees (Jun/Jul and Jan),
  winter healthcare, December entertainment
* free-text descriptions with merchants, casing differences, typos and vague entries
* a small share of injected anomalies (labelled ``is_anomaly``) for evaluating the
  anomaly detector

Everything is driven by a seeded ``numpy`` generator, so runs are reproducible.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date

import numpy as np
import pandas as pd

from ..config import CATEGORIES

# (category, share of monthly spend, mean transactions per month, recurring?, participation probability)
_CATEGORY_PROFILE = {
    "Housing":               (0.30, 1.0, True, 0.95),
    "Food":                  (0.20, 14.0, False, 1.00),
    "Transportation":        (0.08, 10.0, False, 0.95),
    "Healthcare":            (0.04, 1.2, False, 0.85),
    "Education":             (0.06, 0.6, False, 0.45),
    "Entertainment":         (0.05, 3.0, False, 0.90),
    "Personal Care":         (0.03, 2.0, False, 0.90),
    "Debt Repayment":        (0.10, 1.0, True, 0.40),
    "Savings & Investments": (0.08, 1.0, True, 0.55),
    "Insurance":             (0.03, 0.34, True, 0.60),
    "Childcare/Dependents":  (0.05, 2.0, False, 0.35),
    "Gifts & Donations":     (0.02, 0.8, False, 0.80),
    "Miscellaneous":         (0.03, 2.5, False, 0.95),
}

# Month-of-year multipliers (index 0 = January)
_SEASONALITY = {
    "Gifts & Donations": [0.8, 0.7, 0.9, 0.8, 0.7, 0.7, 0.8, 1.0, 1.0, 2.6, 2.2, 1.3],
    "Education":         [1.6, 0.6, 0.5, 0.8, 0.8, 2.4, 2.0, 0.6, 0.6, 0.6, 0.6, 0.6],
    "Healthcare":        [1.3, 1.2, 1.0, 0.9, 0.9, 0.9, 1.1, 1.1, 1.0, 0.9, 1.0, 1.3],
    "Entertainment":     [1.0, 0.9, 0.9, 1.0, 1.1, 1.0, 0.9, 0.9, 1.0, 1.2, 1.2, 1.5],
    "Food":              [1.0, 0.95, 1.0, 1.0, 1.05, 1.0, 1.0, 1.0, 1.0, 1.15, 1.1, 1.1],
    "Transportation":    [1.0, 1.0, 1.0, 1.1, 1.15, 1.0, 0.9, 0.9, 1.0, 1.05, 1.1, 1.1],
}

_DESCRIPTIONS = {
    "Housing": ["house rent", "monthly rent", "rent for flat", "electricity bill", "bescom bill", "water bill",
                "maintenance charges", "society maintenance", "gas cylinder", "broadband bill", "jio fiber", "plumber"],
    "Food": ["swiggy order", "zomato", "groceries", "bigbasket", "vegetables", "veggies", "milk", "dmart groceries",
             "blinkit", "zepto order", "lunch", "dinner with friends", "burger", "pizza", "chai and snacks",
             "fruits", "restaurant", "cafe coffee", "biryani", "tiffin service"],
    "Transportation": ["uber ride", "ola cab", "auto rickshaw", "metro card recharge", "bus pass", "bus ticket",
                       "petrol", "fuel", "rapido bike", "train ticket irctc", "parking fee", "fastag recharge",
                       "car service"],
    "Healthcare": ["apollo pharmacy", "medicines", "doctor consultation", "clinic visit", "blood test",
                   "dental checkup", "1mg medicines", "eye checkup", "hospital bill", "physiotherapy"],
    "Education": ["school fees", "college fees", "tuition fees", "books", "udemy course", "coaching classes",
                  "exam fees", "stationery", "coursera subscription", "uniform"],
    "Entertainment": ["netflix", "amazon prime", "hotstar subscription", "spotify", "movie tickets", "pvr cinemas",
                      "bookmyshow", "concert", "gaming", "weekend outing", "bowling", "youtube premium"],
    "Personal Care": ["haircut", "salon", "skin care", "skincare products", "nykaa order", "spa", "gym membership",
                      "shampoo and soap", "perfume", "grooming kit"],
    "Debt Repayment": ["home loan emi", "car loan emi", "personal loan emi", "credit card bill payment",
                       "education loan emi", "emi payment"],
    "Savings & Investments": ["sip mutual fund", "mutual fund sip", "ppf deposit", "recurring deposit",
                              "stocks purchase zerodha", "gold savings", "fixed deposit"],
    "Insurance": ["lic premium", "health insurance premium", "car insurance", "term insurance premium",
                  "bike insurance renewal", "insurance premium"],
    "Childcare/Dependents": ["daycare fees", "baby food", "diapers", "kids clothes", "toys", "nanny salary",
                             "parents medicines", "school van"],
    "Gifts & Donations": ["birthday gift", "wedding gift", "diwali gifts", "donation to temple", "charity donation",
                          "gift for friend", "rakhi gift", "anniversary gift", "crackers diwali"],
    "Miscellaneous": ["amazon order", "flipkart order", "household items", "mobile recharge", "courier",
                      "laundry", "stationary", "repairs", "misc", "hardware store", "phone cover"],
}

_VAGUE = ["payment", "paid", "shopping", "misc expense", "online payment", "transfer", "bill", "card swipe"]

_PAYMENT_WEIGHTS = {
    "Housing": [0.10, 0.20, 0.70],
    "Debt Repayment": [0.0, 0.30, 0.70],
    "Savings & Investments": [0.0, 0.20, 0.80],
    "Insurance": [0.0, 0.50, 0.50],
    "Food": [0.30, 0.20, 0.50],
    "Transportation": [0.35, 0.15, 0.50],
}
_DEFAULT_PAYMENT = [0.25, 0.35, 0.40]
_PAYMENT_METHODS = ["Cash", "Credit / Debit card", "UPI Payment"]

_FIRST = ["Aarav", "Vivaan", "Aditya", "Ananya", "Diya", "Ishaan", "Kavya", "Meera", "Rohan", "Saanvi", "Arjun",
          "Priya", "Neha", "Rahul", "Sneha", "Karan", "Pooja", "Vikram", "Riya", "Aditi", "Nikhil", "Tara"]
_LAST = ["Sharma", "Patel", "Iyer", "Reddy", "Nair", "Gupta", "Mehta", "Shah", "Das", "Kulkarni", "Joshi", "Singh"]


@dataclass
class SyntheticDataset:
    users: pd.DataFrame          # user_id, name, email, phone, monthly_income
    transactions: pd.DataFrame   # user_id, date, category, amount, description, payment_method, is_anomaly
    incomes: pd.DataFrame        # user_id, month, income
    budgets: pd.DataFrame        # user_id, month, amount


def _typo(text: str, rng: np.random.Generator) -> str:
    """Introduce a realistic typo (drop, double or swap a character)."""
    if len(text) < 4:
        return text
    i = int(rng.integers(1, len(text) - 1))
    kind = rng.integers(0, 3)
    if kind == 0:
        return text[:i] + text[i + 1:]
    if kind == 1:
        return text[:i] + text[i] + text[i:]
    return text[:i - 1] + text[i] + text[i - 1] + text[i + 1:]


def _describe(category: str, rng: np.random.Generator, vague_rate: float) -> str:
    if rng.random() < vague_rate:
        return str(rng.choice(_VAGUE))
    text = str(rng.choice(_DESCRIPTIONS[category]))
    roll = rng.random()
    if roll < 0.08:
        text = _typo(text, rng)
    elif roll < 0.25:
        text = text.title()
    elif roll < 0.30:
        text = text.upper()
    return text


def generate(
    n_users: int = 40,
    n_months: int = 30,
    end: date | None = None,
    seed: int = 42,
    anomaly_rate: float = 0.015,
    vague_rate: float = 0.06,
) -> SyntheticDataset:
    """Generate ``n_months`` of history for ``n_users``, ending on ``end`` (default: today).

    The final month is partial (up to ``end``), mirroring what the live app sees.
    """
    rng = np.random.default_rng(seed)
    end = end or date.today()
    end_ts = pd.Timestamp(end)
    end_period = end_ts.to_period("M")
    start_period = end_period - (n_months - 1)

    users, rows, incomes, budgets = [], [], [], []
    for user_id in range(1, n_users + 1):
        income = float(np.round(rng.lognormal(mean=np.log(60000), sigma=0.45), -2))
        spend_ratio = rng.uniform(0.55, 0.9)
        monthly_spend = income * spend_ratio
        drift = rng.normal(0.004, 0.003)                       # ~5% yearly inflation per user
        # Users join at different times, so the panel has varied history lengths (cold start cases).
        join_offset = int(rng.integers(0, max(1, n_months - 6))) if rng.random() < 0.35 else 0
        user_start = start_period + join_offset
        name = f"{rng.choice(_FIRST)} {rng.choice(_LAST)}"
        users.append({
            "user_id": user_id,
            "name": name,
            "email": f"demo.user{user_id}@fiscalpoint.test",
            "phone": f"9{rng.integers(100000000, 999999999)}",
            "monthly_income": income,
        })

        active = {c: rng.random() < p[3] for c, p in _CATEGORY_PROFILE.items()}
        level = {c: monthly_spend * p[0] * rng.lognormal(0, 0.3) for c, p in _CATEGORY_PROFILE.items()}
        fixed_day = {c: int(rng.integers(1, 8)) for c in _CATEGORY_PROFILE}
        fixed_amount = {c: float(np.round(level[c], -2)) for c in _CATEGORY_PROFILE}
        insurance_month = int(rng.integers(1, 13))

        for step, period in enumerate(pd.period_range(user_start, end_period, freq="M")):
            growth = (1 + drift) ** step
            month_index = period.month - 1
            last_day = period.days_in_month if period != end_period else end_ts.day
            incomes.append({"user_id": user_id, "month": str(period), "income": float(np.round(income * growth, -2))})

            for category, (_, rate, recurring, _) in _CATEGORY_PROFILE.items():
                if not active[category]:
                    continue
                season = _SEASONALITY.get(category, [1.0] * 12)[month_index]

                if recurring:
                    if category == "Insurance":
                        # quarterly premiums for some users, annual for others
                        due = (period.month - insurance_month) % (3 if fixed_day[category] % 2 else 12) == 0
                        if not due:
                            continue
                    day = fixed_day[category]
                    if day > last_day:
                        continue
                    # rent and EMIs are fixed; rent is revised by ~5 % every 12 months
                    amount = fixed_amount[category] * (1.05 ** (step // 12) if category == "Housing" else 1.0)
                    if category == "Insurance":
                        amount *= 3
                    events = [(day, amount)]
                    if category == "Housing":            # utilities on top of rent
                        n_util = rng.poisson(2)
                        events += [(int(rng.integers(1, last_day + 1)), level[category] * rng.uniform(0.04, 0.12))
                                   for _ in range(n_util)]
                else:
                    n = rng.poisson(rate * season * (last_day / period.days_in_month))
                    if n == 0:
                        continue
                    target = level[category] * season * growth * rng.lognormal(0, 0.18)
                    weights = rng.dirichlet(np.ones(n) * 1.5)
                    # occasional categories (rate < 1) spend more per occurrence so the expected monthly level holds
                    events = [(int(rng.integers(1, last_day + 1)), target * w / min(rate, 1.0)) for w in weights]

                pay_weights = _PAYMENT_WEIGHTS.get(category, _DEFAULT_PAYMENT)
                for day, amount in events:
                    amount = max(10.0, float(amount) * rng.lognormal(0, 0.05))
                    is_anomaly = rng.random() < anomaly_rate
                    if is_anomaly:
                        amount *= rng.uniform(5, 15)
                    rows.append({
                        "user_id": user_id,
                        "date": pd.Timestamp(period.year, period.month, int(day)),
                        "category": category,
                        "amount": round(amount, 2),
                        "description": _describe(category, rng, vague_rate),
                        "payment_method": str(rng.choice(_PAYMENT_METHODS, p=pay_weights)),
                        "is_anomaly": bool(is_anomaly),
                    })

        # Budgets for the last 6 months: roughly the user's typical spend +/- 15 %
        for period in pd.period_range(max(user_start, end_period - 5), end_period, freq="M"):
            budgets.append({
                "user_id": user_id,
                "month": str(period),
                "amount": float(np.round(monthly_spend * rng.uniform(0.85, 1.15) * (1 + drift) ** (period - user_start).n, -2)),
            })

    transactions = pd.DataFrame(rows).sort_values(["user_id", "date"]).reset_index(drop=True)
    assert set(transactions["category"]) <= set(CATEGORIES)
    return SyntheticDataset(
        users=pd.DataFrame(users),
        transactions=transactions,
        incomes=pd.DataFrame(incomes),
        budgets=pd.DataFrame(budgets),
    )


def save(dataset: SyntheticDataset, directory) -> None:
    """Write the dataset as CSV files (transactions.csv, users.csv, incomes.csv, budgets.csv)."""
    from pathlib import Path

    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    tx = dataset.transactions.copy()
    tx["date"] = tx["date"].dt.strftime("%Y-%m-%d")
    tx.to_csv(directory / "transactions.csv", index=False)
    dataset.users.to_csv(directory / "users.csv", index=False)
    dataset.incomes.to_csv(directory / "incomes.csv", index=False)
    dataset.budgets.to_csv(directory / "budgets.csv", index=False)
