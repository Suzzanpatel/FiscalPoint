import logging
import unittest
from unittest.mock import patch

import pandas as pd

import localhost_server
from predictions import app, backtest, choose_method, monthly_category_totals

logging.getLogger("matplotlib").setLevel(logging.WARNING)


class PredictionTests(unittest.TestCase):
    def test_monthly_totals_fill_missing_month_across_year_boundary(self):
        data = pd.DataFrame({
            "date": pd.to_datetime(["2024-12-10", "2024-12-20", "2025-02-01"]),
            "category": ["Food", "Food", "Food"],
            "amount": [12.0, 8.0, 15.0],
        })

        monthly = monthly_category_totals(data)

        self.assertEqual(
            monthly.index.tolist(),
            list(pd.period_range("2024-12", "2025-02", freq="M")),
        )
        self.assertEqual(monthly.loc[pd.Period("2024-12", freq="M"), "Food"], 20.0)
        self.assertEqual(monthly.loc[pd.Period("2025-01", freq="M"), "Food"], 0.0)

    def test_rolling_backtest_selects_regression_only_when_it_beats_both_baselines(self):
        errors = backtest([10, 20, 30, 40, 50])

        self.assertAlmostEqual(errors["linreg"], 0.0)
        self.assertLess(errors["linreg"], errors["naive"])
        self.assertLess(errors["linreg"], errors["ma3"])
        self.assertEqual(choose_method(errors, 5), "linreg")

    def test_regression_tie_uses_better_simple_baseline(self):
        errors = {"naive": 2.0, "ma3": 3.0, "linreg": 2.0}

        self.assertEqual(choose_method(errors, 5), "naive")

    def test_prediction_and_evaluation_routes_reject_remote_clients(self):
        client = app.test_client()
        for route in ("/predict_budget", "/evaluate_model"):
            response = client.post(
                route,
                json={},
                environ_base={"REMOTE_ADDR": "192.0.2.1"},
            )
            self.assertEqual(response.status_code, 403)

    @patch("predictions.fetch_expense_data")
    def test_evaluation_reports_rolling_mae_by_category(self, fetch_expense_data):
        fetch_expense_data.return_value = pd.DataFrame({
            "date": pd.to_datetime([
                "2025-01-15",
                "2025-02-15",
                "2025-03-15",
                "2025-04-15",
                "2025-05-15",
            ]),
            "category": ["Food"] * 5,
            "amount": [10.0, 20.0, 30.0, 40.0, 50.0],
        })

        response = app.test_client().post(
            "/evaluate_model",
            json={"user_id": 1},
            environ_base={"REMOTE_ADDR": "127.0.0.1"},
        )
        category_result = response.get_json()["category_wise"]["Food"]

        self.assertEqual(response.status_code, 200)
        self.assertEqual(category_result["sample_size"], 5)
        self.assertEqual(category_result["selected_method"], "linreg")
        self.assertAlmostEqual(category_result["linreg_mae"], 0.0)

    @patch("predictions.get_db_connection", side_effect=RuntimeError("private detail"))
    def test_database_probe_does_not_return_tracebacks(self, _get_db_connection):
        with self.assertLogs("predictions", level="ERROR"):
            response = app.test_client().get("/test_db")

        self.assertEqual(response.status_code, 500)
        self.assertNotIn("detail", response.get_json())
        self.assertNotIn("private detail", response.get_data(as_text=True))

    @patch(
        "localhost_server.get_db_connection",
        side_effect=RuntimeError("private detail"),
    )
    def test_alternate_database_probe_does_not_return_tracebacks(
        self, _get_db_connection
    ):
        with self.assertLogs("localhost_server", level="ERROR"):
            response = localhost_server.app.test_client().get("/test_db")

        self.assertEqual(response.status_code, 500)
        self.assertNotIn("detail", response.get_json())
        self.assertNotIn("private detail", response.get_data(as_text=True))


if __name__ == "__main__":
    unittest.main()
