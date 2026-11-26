import pytest

from fiscalpoint_ml.api import create_app
from fiscalpoint_ml.config import Settings
from fiscalpoint_ml.data.repository import FrameRepository
from fiscalpoint_ml.registry import ModelBundle

from conftest import TODAY

LOCAL = {"REMOTE_ADDR": "127.0.0.1"}


@pytest.fixture(scope="module")
def client(transactions, dataset, bundle):
    app = create_app(Settings(), FrameRepository(transactions, dataset.budgets), bundle, today=TODAY)
    return app.test_client()


def post(client, path, payload, **kwargs):
    return client.post(path, json=payload, environ_base=kwargs.pop("environ_base", LOCAL), **kwargs)


def test_health_is_public(client):
    data = client.get("/health").get_json()
    assert data["status"] == "online"
    assert all(data["models_loaded"].values())


@pytest.mark.parametrize("route", ["/v1/forecast", "/v1/categorize", "/v1/anomalies", "/v1/budget-risk", "/v1/evaluate"])
def test_remote_clients_are_rejected(client, route):
    response = post(client, route, {"user_id": 1}, environ_base={"REMOTE_ADDR": "192.0.2.10"})
    assert response.status_code == 403


def test_token_required_when_configured(transactions, bundle):
    app = create_app(Settings(api_token="s3cret"), FrameRepository(transactions), bundle, today=TODAY)
    client = app.test_client()
    assert post(client, "/v1/categorize", {"description": "uber"}).status_code == 401
    ok = client.post("/v1/categorize", json={"description": "uber"}, environ_base=LOCAL, headers={"X-API-Key": "s3cret"})
    assert ok.status_code == 200


def test_forecast(client):
    data = post(client, "/v1/forecast", {"user_id": 1, "horizon": 2}).get_json()
    assert [m["period"] for m in data["months"]] == ["2026-05", "2026-06"]
    month = data["months"][0]
    assert month["total"]["low"] <= month["total"]["expected"] <= month["total"]["high"]
    assert month["categories"] and {"category", "expected", "low", "high"} <= set(month["categories"][0])
    assert data["history"][-1] == {"period": "2026-05", "total": data["history"][-1]["total"], "partial": True}


def test_forecast_for_user_without_history(client):
    data = post(client, "/v1/forecast", {"user_id": 9999}).get_json()
    assert data["months"] == [] and "Not enough history" in data["message"]


def test_forecast_validates_input(client):
    assert post(client, "/v1/forecast", {"user_id": "abc"}).status_code == 400
    assert post(client, "/v1/forecast", {"user_id": 1, "horizon": 9}).status_code == 400
    assert client.post("/v1/forecast", data="not json", environ_base=LOCAL).status_code == 400


def test_categorize(client):
    data = post(client, "/v1/categorize", {"description": "Zomato order", "amount": 450}).get_json()
    assert data["suggestions"][0]["category"] == "Food"
    assert post(client, "/v1/categorize", {"description": ""}).status_code == 400


def test_anomalies(client):
    data = post(client, "/v1/anomalies", {"user_id": 1, "months": 24}).get_json()
    assert isinstance(data["anomalies"], list)
    for item in data["anomalies"]:
        assert item["ratio"] >= 3 and item["reason"]


def test_budget_risk_uses_stored_budget(client):
    data = post(client, "/v1/budget-risk", {"user_id": 1}).get_json()
    assert data["month"] == "2026-05"
    assert 0.0 <= data["probability_over_budget"] <= 1.0
    assert data["risk_level"] in {"low", "medium", "high", "exceeded"}


def test_budget_risk_without_budget(client):
    data = post(client, "/v1/budget-risk", {"user_id": 9999}).get_json()
    assert data["message"] == "No budget set for this month."


def test_evaluate(client):
    data = post(client, "/v1/evaluate", {"user_id": 1}).get_json()
    assert set(data["user_baselines"]) == {"naive", "moving_avg_3", "seasonal_naive", "linear_trend"}


def test_missing_models_return_503(transactions):
    app = create_app(Settings(), FrameRepository(transactions), ModelBundle(), today=TODAY)
    response = post(app.test_client(), "/v1/categorize", {"description": "uber"})
    assert response.status_code == 503
    assert "not trained" in response.get_json()["error"]


def test_errors_do_not_leak_internals(transactions, bundle):
    class BrokenRepo:
        def transactions(self, user_id=None):
            raise RuntimeError("password=hunter2 host=db.internal")

        def budget(self, user_id, month):
            return None

    app = create_app(Settings(), BrokenRepo(), bundle, today=TODAY)
    response = post(app.test_client(), "/v1/forecast", {"user_id": 1})
    assert response.status_code == 503
    assert "hunter2" not in response.get_data(as_text=True)
