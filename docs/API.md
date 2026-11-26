# ML service API

Flask service in `ml/fiscalpoint_ml/api.py`. Start it with `python -m fiscalpoint_ml.api` from `ml/`.
Default address: `http://127.0.0.1:5001`.

## Access rules

* Only requests from localhost are accepted unless `ML_API_ALLOW_REMOTE=true` (which requires `ML_API_TOKEN`).
* When `ML_API_TOKEN` is set, every endpoint except `/health` requires the header `X-API-Key: <token>`.
* All bodies are JSON. Errors are `{"error": "..."}` with status 400 (bad input), 401/403 (access),
  503 (models not trained / data source unavailable) or 500. Error responses never contain stack
  traces or database details.

From PHP, use `ml_request($path, $payload)` in `web/includes/ml_client.php`, which adds the key
and handles timeouts.

---

### `GET /health`

Liveness check. Public.

```json
{"status": "online", "version": "2.0.0", "trained_at": "2026-11-26T07:22:53+00:00",
 "models_loaded": {"forecaster": true, "categorizer": true, "anomaly": true, "budget_risk": true}}
```

### `GET /v1/models`

Training metadata and evaluation metrics (data summary, backtest table, categorizer, anomaly and
budget-risk metrics). Used by the admin *ML Models* page.

### `POST /v1/forecast`

| Field | Type | Default | Notes |
|---|---|---|---|
| `user_id` | int | required | |
| `horizon` | int 1–3 | 2 | months, starting with the current month |

```json
{
  "user_id": 2,
  "generated_at": "2026-11-26",
  "model": {"name": "gradient_boosting", "interval": "80% prediction interval", "backtest_mae_improvement": 0.069},
  "months_of_history": 23,
  "warnings": [],
  "history": [{"period": "2026-10", "total": 33811.2, "partial": false},
              {"period": "2026-11", "total": 24647.04, "partial": true}],
  "months": [{
    "period": "2026-11", "label": "November 2026",
    "total": {"low": 16795.66, "expected": 29207.23, "high": 32648.57},
    "categories": [{"category": "Housing", "expected": 10196.77, "low": 2.55, "high": 11382.85,
                    "last_month": 8097.3, "avg_3_months": 7951.74}]
  }]
}
```

Users without a complete month of history get `"months": []` and a `message`.

### `POST /v1/budget-risk`

| Field | Type | Default |
|---|---|---|
| `user_id` | int | required |
| `month` | `YYYY-MM` | current month (the only month supported) |
| `budget` | number | read from the `Budget` table |

```json
{"user_id": 2, "month": "2026-11", "budget": 26900.0, "spent_to_date": 24647.04,
 "days_elapsed": 26, "days_in_month": 30, "typical_share_spent": 0.94,
 "forecast_expected": 29207.23, "projected_total": 26467.23,
 "projected_low": 26084.35, "projected_high": 26850.11,
 "probability_over_budget": 0.07, "risk_level": "low", "remaining_budget": 2252.96}
```

`risk_level` is `low` (< 30 %), `medium` (30–60 %), `high` (≥ 60 %) or `exceeded`.
Without a budget, the response contains `"message": "No budget set for this month."`

### `POST /v1/categorize`

| Field | Type | Default |
|---|---|---|
| `description` | string (≤ 255) | required |
| `amount` | number | optional, improves accuracy |
| `payment_method` | string | optional |
| `top_k` | int 1–5 | 3 |

```json
{"description": "zomato biryani", "suggestions": [{"category": "Food", "probability": 0.9999}]}
```

Suggestions below 20 % probability are omitted, so the list can be empty.

### `POST /v1/anomalies`

| Field | Type | Default |
|---|---|---|
| `user_id` | int | required |
| `months` | int 1–24 | 3 (look-back window, including the current month) |
| `limit` | int 1–50 | 10 |

```json
{"user_id": 2, "since": "2026-09-01", "anomalies": [
  {"date": "2026-10-08", "category": "Food", "description": "Chai And Snacks", "amount": 8230.75,
   "typical_amount": 288.4, "ratio": 28.5, "score": 0.71,
   "reason": "₹8,231 is 28.5x your usual Food spend (typically ₹288)"}]}
```

### `POST /v1/evaluate`

`{"user_id": 2}`. Returns the global backtest metrics and the user's personal rolling MAE for the
four baselines (needs at least 3 complete months).
