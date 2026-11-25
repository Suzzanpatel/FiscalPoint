import mysql.connector
import pandas as pd
from flask import Flask, jsonify, request
from flask_cors import CORS
import numpy as np
from sklearn.linear_model import LinearRegression
import matplotlib
from functools import wraps
matplotlib.use('Agg')
import matplotlib.pyplot as plt
import io
import base64
import os
import traceback
import logging
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)
CORS(app, resources={r"/predict_budget": {"origins": "http://127.0.0.1"}}, supports_credentials=False)

logging.basicConfig(level=logging.DEBUG)
logger = logging.getLogger(__name__)


def localhost_only(view):
    @wraps(view)
    def wrapped(*args, **kwargs):
        if request.remote_addr not in ("127.0.0.1", "::1"):
            return jsonify({"error": "Forbidden"}), 403
        return view(*args, **kwargs)

    return wrapped


@app.route('/', methods=['GET'])
def health_check():
    return jsonify({"status": "online", "message": "Prediction server is running"})


@app.route('/test_db', methods=['GET'])
def test_db():
    try:
        conn = get_db_connection()
        if conn:
            cursor = conn.cursor()
            cursor.execute("SELECT 1")
            cursor.close()
            conn.close()
            return jsonify({"status": "success", "message": "Database connection successful"})
        return jsonify({"status": "error", "message": "Could not connect to database"}), 500
    except Exception:
        logger.exception("Database connection check failed")
        return jsonify({"status": "error", "message": "Database connection failed"}), 500


@app.route('/predict_budget', methods=['OPTIONS'])
def options():
    response = jsonify({'status': 'success'})
    response.headers.add('Access-Control-Allow-Origin', '*')
    response.headers.add('Access-Control-Allow-Headers', 'Content-Type,Authorization')
    response.headers.add('Access-Control-Allow-Methods', 'GET,PUT,POST,DELETE,OPTIONS')
    return response


def get_db_connection():
    settings = {
        "host": os.getenv("DB_HOST"),
        "user": os.getenv("DB_USER"),
        "password": os.getenv("DB_PASSWORD"),
        "database": os.getenv("DB_NAME"),
    }
    if (
        not settings["host"]
        or not settings["user"]
        or settings["password"] is None
        or not settings["database"]
    ):
        logger.error("Required database environment variables are missing")
        return None

    try:
        conn = mysql.connector.connect(**settings)
        logger.info("Database connection successful")
        return conn
    except mysql.connector.Error as err:
        logger.error(f"Error connecting to database: {err}")
        return None


def fetch_expense_data(Uid):
    try:
        conn = get_db_connection()
        if not conn:
            raise ConnectionError("Database connection is unavailable")
            
        cursor = conn.cursor(dictionary=True)

        # Query matches your database schema
        query = """
            SELECT date, amount, category 
            FROM Expense 
            WHERE Uid = %s AND date IS NOT NULL 
            ORDER BY date
        """
        logger.info(f"Executing query for user {Uid}")
        cursor.execute(query, (Uid,))
        result = cursor.fetchall()

        logger.info(f"Query returned {len(result) if result else 0} rows")
        if not result:
            return None

        df = pd.DataFrame(result)
        
        # Log the data types and sample data for debugging
        logger.info(f"DataFrame columns: {df.columns}")
        logger.info(f"DataFrame types: {df.dtypes}")
        if not df.empty:
            logger.info(f"Sample data: {df.head(1).to_dict('records')}")
        
        # Convert date to datetime if needed
        if 'date' in df.columns:
            df['date'] = pd.to_datetime(df['date'], errors='coerce')
            # Check for NaT values after conversion
            if df['date'].isna().any():
                logger.warning(f"Some dates could not be converted: {df[df['date'].isna()]}")
                df = df.dropna(subset=['date'])
                
            return df
        else:
            logger.error(f"Column 'date' not found in result. Columns: {df.columns}")
            return None

    except Exception:
        logger.exception("Failed to fetch expense data")
        raise
    finally:
        if 'cursor' in locals() and cursor:
            cursor.close()
        if 'conn' in locals() and conn:
            conn.close()


def monthly_category_totals(data):
    data = data.dropna(subset=['date', 'category', 'amount'])
    if data.empty:
        return pd.DataFrame()

    monthly = (
        data.assign(period=data['date'].dt.to_period('M'))
        .pivot_table(index='period', columns='category', values='amount',
                     aggfunc='sum', fill_value=0)
        .sort_index()
    )
    periods = pd.period_range(monthly.index.min(), monthly.index.max(), freq='M')
    return monthly.reindex(periods, fill_value=0)


def backtest(series, min_train=3):
    values = np.asarray(series, dtype=float)
    time_index = np.arange(len(values)).reshape(-1, 1)
    errors = {'naive': [], 'ma3': [], 'linreg': []}

    for k in range(min_train, len(values)):
        train, actual = values[:k], values[k]
        errors['naive'].append(abs(train[-1] - actual))
        errors['ma3'].append(abs(train[-3:].mean() - actual))
        model = LinearRegression().fit(time_index[:k], train)
        prediction = max(0, model.predict(time_index[[k]])[0])
        errors['linreg'].append(abs(prediction - actual))

    return {
        name: float(np.mean(method_errors)) if method_errors else None
        for name, method_errors in errors.items()
    }


def choose_method(errors, series_length):
    if errors['linreg'] is not None and errors['naive'] is not None and errors['ma3'] is not None:
        if errors['linreg'] < errors['naive'] and errors['linreg'] < errors['ma3']:
            return 'linreg'

    available_baselines = {
        name: error for name, error in errors.items()
        if name in ('naive', 'ma3') and error is not None
    }
    if available_baselines:
        return min(available_baselines, key=available_baselines.get)
    return 'ma3' if series_length >= 3 else 'naive'


def forecast_category(series, method):
    values = np.asarray(series, dtype=float)
    if method == 'linreg':
        time_index = np.arange(len(values)).reshape(-1, 1)
        model = LinearRegression().fit(time_index, values)
        prediction = model.predict([[len(values)]])[0]
    elif method == 'ma3':
        prediction = values[-3:].mean()
    else:
        prediction = values[-1]
    return max(0.0, float(prediction))


def predict_next_month(Uid):
    data = fetch_expense_data(Uid)
    if data is None or data.empty:
        return {"message": "No expense data found for this user."}

    monthly = monthly_category_totals(data)
    if monthly.empty:
        return {"message": "No categorized expense data found for this user."}
    next_period = monthly.index[-1] + 1
    current_category_expenses = monthly.iloc[-1].to_dict()
    category_predictions = {}

    for category in monthly.columns:
        series = monthly[category].to_numpy(dtype=float)
        errors = backtest(series)
        method = choose_method(errors, len(series))
        category_predictions[category] = round(forecast_category(series, method), 2)

    return {
        "year": next_period.year,
        "month": next_period.month,
        "predicted_expense": round(sum(category_predictions.values()), 2),
        "category_predictions": category_predictions,
        "current_category_expenses": current_category_expenses,
        "comparison_graph": generate_comparison_graph(
            current_category_expenses, category_predictions
        ),
    }

# Generate base64 bar chart comparing expenses
def generate_comparison_graph(current, predicted):
    categories = sorted(set(current.keys()) | set(predicted.keys()))
    current_values = [current.get(cat, 0) for cat in categories]
    predicted_values = [predicted.get(cat, 0) for cat in categories]

    x = np.arange(len(categories))
    width = 0.35

    # Create figure without using seaborn
    fig, ax = plt.subplots(figsize=(12, 6))
    
    rects1 = ax.bar(x - width/2, current_values, width, label='Latest Month (Observed)', color='#2ecc71')
    rects2 = ax.bar(x + width/2, predicted_values, width, label='Next Month (Predicted)', color='#3498db')

    ax.set_ylabel('Expenses (₹)', fontsize=12)
    ax.set_title('Category-wise Expense Comparison', fontsize=14, pad=20)
    ax.set_xticks(x)
    ax.set_xticklabels(categories, rotation=45, ha='right')
    ax.legend()

    # Add value labels on bars
    def autolabel(rects):
        for rect in rects:
            height = rect.get_height()
            ax.annotate(f'₹{height:,.0f}',
                       xy=(rect.get_x() + rect.get_width() / 2, height),
                       xytext=(0, 3),
                       textcoords="offset points",
                       ha='center', va='bottom', rotation=0)

    autolabel(rects1)
    autolabel(rects2)

    fig.tight_layout()

    buffer = io.BytesIO()
    plt.savefig(buffer, format='png', dpi=300, bbox_inches='tight')
    buffer.seek(0)
    img_base64 = base64.b64encode(buffer.read()).decode('utf-8')
    plt.close(fig)

    return img_base64

# Route to predict and return insights
@app.route('/predict_budget', methods=['POST'])
@localhost_only
def predict_budget():
    try:
        logger.info("Received predict_budget request")
        data = request.get_json()
        
        if not data:
            logger.error("No JSON data received")
            return jsonify({"error": "No data provided"}), 400
            
        if 'user_id' not in data:
            logger.error("No user_id in request data")
            return jsonify({"error": "user_id is required"}), 400

        user_id = data['user_id']
        logger.info(f"Processing request for user_id: {user_id}")
        prediction = predict_next_month(user_id)
        if "message" in prediction:
            return jsonify(prediction)

        month_name = pd.Period(
            year=prediction["year"], month=prediction["month"], freq="M"
        ).strftime("%B")
        summary = (
            f"📅 Predicted Insights for Month {prediction['month']}, "
            f"{prediction['year']}\n\n"
            "🔮 Prediction based on monthly spending patterns:\n\n"
        )
        for category, amount in prediction["category_predictions"].items():
            summary += f"- {category}: ₹{amount:.2f}\n"

        return jsonify({
            "summary": summary,
            "graph_base64": prediction["comparison_graph"],
            "predicted_expense": prediction["predicted_expense"],
            "month_name": month_name,
        })
    except Exception:
        logger.exception("Error in predict_budget")
        return jsonify({"error": "An error occurred while generating the prediction"}), 500

def evaluate_model_accuracy(Uid):
    try:
        data = fetch_expense_data(Uid)
        if data is None or data.empty:
            return {"error": "No expense data found for this user."}

        monthly = monthly_category_totals(data)
        if monthly.empty:
            return {"error": "No categorized expense data found for this user."}
        category_wise = {}
        for category in monthly.columns:
            errors = backtest(monthly[category].to_numpy(dtype=float))
            category_wise[category] = {
                "naive_mae": errors["naive"],
                "ma3_mae": errors["ma3"],
                "linreg_mae": errors["linreg"],
                "selected_method": choose_method(errors, len(monthly)),
                "sample_size": len(monthly),
            }

        overall = {}
        for method in ("naive", "ma3", "linreg"):
            method_errors = [
                values[f"{method}_mae"] for values in category_wise.values()
                if values[f"{method}_mae"] is not None
            ]
            overall[f"{method}_mae"] = (
                float(np.mean(method_errors)) if method_errors else None
            )

        fig, ax = plt.subplots(figsize=(12, 6))
        categories = list(category_wise)
        methods = ("naive", "ma3", "linreg")
        width = 0.8 / len(methods)
        for index, method in enumerate(methods):
            errors = [category_wise[cat][f"{method}_mae"] for cat in categories]
            positions = np.arange(len(categories)) + (index - 1) * width
            ax.bar(positions, [error or 0 for error in errors], width, label=method)
        ax.set_title("Rolling-origin mean absolute error by category")
        ax.set_xlabel("Category")
        ax.set_ylabel("Mean absolute error")
        ax.set_xticks(np.arange(len(categories)))
        ax.set_xticklabels(categories, rotation=45, ha="right")
        ax.legend()
        fig.tight_layout()

        buffer = io.BytesIO()
        plt.savefig(buffer, format="png", dpi=150, bbox_inches="tight")
        plt.close(fig)

        return {
            "overall": overall,
            "category_wise": category_wise,
            "visualization": base64.b64encode(buffer.getvalue()).decode("utf-8"),
        }
    except Exception:
        logger.exception("Error in evaluate_model_accuracy")
        return {"error": "Model evaluation failed"}

# Add new route for model evaluation
@app.route('/evaluate_model', methods=['POST'])
@localhost_only
def evaluate_model():
    try:
        data = request.get_json(silent=True)
        if not data or 'user_id' not in data:
            return jsonify({"error": "user_id is required"}), 400

        user_id = data['user_id']
        results = evaluate_model_accuracy(user_id)
        if "error" in results:
            return jsonify(results), 400
        return jsonify(results)
    except Exception:
        logger.exception("Error in evaluate_model route")
        return jsonify({"error": "Model evaluation failed"}), 500

# Run the app
if __name__ == '__main__':
    try:
        print("Starting prediction server on http://127.0.0.1:5001")
        print("To test if server is running, open a browser and go to: http://127.0.0.1:5001")
        print("To test database connection, open: http://127.0.0.1:5001/test_db")
        print("Press CTRL+C to stop the server")
        app.run(host='127.0.0.1', port=5001, debug=False)
    except Exception as e:
        print(f"Failed to start the server: {e}")
        print(traceback.format_exc())