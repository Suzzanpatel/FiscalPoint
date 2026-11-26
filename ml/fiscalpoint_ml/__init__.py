"""FiscalPoint machine-learning package.

Sub-modules
-----------
data.synthetic   realistic synthetic transaction generator (training + demo data)
data.repository  loading transactions from MySQL or CSV
features         monthly panel construction and leakage-free feature engineering
forecasting      baselines, gradient-boosted global forecaster, rolling-origin backtest
categorizer      text classifier that suggests a category from an expense description
anomaly          unusual-transaction detector (robust statistics + Isolation Forest)
budget           probability of exceeding the monthly budget
registry         saving / loading trained model artifacts with metadata
train            command-line training pipeline (python -m fiscalpoint_ml.train)
api              Flask inference service used by the PHP web application
"""

__version__ = "2.0.0"
