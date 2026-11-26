"""Unusual-transaction detection.

Each transaction is described relative to the *same user's* normal behaviour:

=================== ==================================================================
feature             meaning
=================== ==================================================================
log_amount          log(amount)
z_category          robust z-score of log(amount) within the user's category history
                    (median / MAD, so the outlier itself barely moves the baseline)
z_user              robust z-score of log(amount) across all of the user's spending
log_ratio           log(amount / median amount for this user and category)
category_frequency  share of the user's transactions that fall in this category
=================== ==================================================================

An Isolation Forest is fitted on these features for all users. The anomaly score is the
forest's score; a transaction is flagged when the score is above the threshold learned at
the configured contamination rate *and* it is at least ``min_ratio`` times the user's usual
amount for that category. The second rule keeps alerts explainable: every flag comes with a
plain-language reason such as "6.2x your usual Food spend".

The synthetic generator labels its injected anomalies, which gives precision / recall /
ROC-AUC for evaluation. On that data the forest + ratio rule beats two simpler rules
("ratio >= 5" and "robust z >= 2.5"): see ``evaluate``. Day-of-week was tried as a feature
and removed because it lowered precision.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np
import pandas as pd
from sklearn.ensemble import IsolationForest
from sklearn.metrics import average_precision_score, f1_score, precision_score, recall_score, roc_auc_score

FEATURES = ["log_amount", "z_category", "z_user", "log_ratio", "category_frequency"]
_MAD_SCALE = 1.4826          # makes the MAD consistent with the standard deviation for normal data
_MIN_MAD = 0.25              # floor (log units) so near-constant series don't explode z-scores


def _robust_z(values: pd.Series) -> pd.Series:
    median = values.median()
    mad = (values - median).abs().median() * _MAD_SCALE
    return (values - median) / max(mad, _MIN_MAD)


def transaction_features(transactions: pd.DataFrame) -> pd.DataFrame:
    """Per-transaction features relative to the user's own history."""
    df = transactions.copy()
    df["log_amount"] = np.log(df["amount"].clip(lower=1.0))
    by_user_cat = df.groupby(["user_id", "category"])["log_amount"]
    df["z_category"] = by_user_cat.transform(_robust_z)
    df["z_user"] = df.groupby("user_id")["log_amount"].transform(_robust_z)
    df["category_median"] = df.groupby(["user_id", "category"])["amount"].transform("median")
    df["ratio_category"] = df["amount"] / df["category_median"]
    df["log_ratio"] = np.log(df["ratio_category"])
    counts = df.groupby(["user_id", "category"])["amount"].transform("size")
    df["category_count"] = counts
    df["category_frequency"] = counts / df.groupby("user_id")["amount"].transform("size")
    return df


@dataclass
class AnomalyDetector:
    contamination: float = 0.02
    min_ratio: float = 3.0
    min_category_history: int = 4
    n_estimators: int = 300
    random_state: int = 0
    forest_: IsolationForest | None = field(default=None, repr=False)
    threshold_: float | None = None

    def fit(self, transactions: pd.DataFrame) -> "AnomalyDetector":
        feats = transaction_features(transactions)
        self.forest_ = IsolationForest(n_estimators=self.n_estimators, contamination=self.contamination,
                                       random_state=self.random_state)
        self.forest_.fit(feats[FEATURES].to_numpy(dtype=float))
        scores = -self.forest_.score_samples(feats[FEATURES].to_numpy(dtype=float))
        self.threshold_ = float(np.quantile(scores, 1 - self.contamination))
        return self

    def score(self, transactions: pd.DataFrame) -> pd.DataFrame:
        """Return the transactions with ``anomaly_score``, ``is_flagged`` and a ``reason``."""
        if self.forest_ is None:
            raise RuntimeError("anomaly detector is not fitted")
        feats = transaction_features(transactions)
        if feats.empty:
            return feats.assign(anomaly_score=[], is_flagged=[], reason=[])
        feats["anomaly_score"] = -self.forest_.score_samples(feats[FEATURES].to_numpy(dtype=float))
        enough_history = feats["category_count"] >= self.min_category_history
        feats["is_flagged"] = (
            (feats["anomaly_score"] >= self.threshold_)
            & (feats["ratio_category"] >= self.min_ratio)
            & enough_history
        )
        feats["reason"] = [
            f"₹{amount:,.0f} is {ratio:.1f}x your usual {category} spend (typically ₹{median:,.0f})"
            for amount, ratio, category, median in zip(
                feats["amount"], feats["ratio_category"], feats["category"], feats["category_median"])
        ]
        return feats

    def user_anomalies(self, transactions: pd.DataFrame, since: pd.Timestamp | None = None,
                       limit: int = 10) -> pd.DataFrame:
        """Flagged transactions for one user (scored against their full history), newest first."""
        scored = self.score(transactions)
        flagged = scored[scored["is_flagged"]]
        if since is not None:
            flagged = flagged[flagged["date"] >= since]
        return flagged.sort_values(["anomaly_score"], ascending=False).head(limit).sort_values("date", ascending=False)


def evaluate(transactions: pd.DataFrame, detector: AnomalyDetector | None = None) -> dict:
    """Precision / recall against the ``is_anomaly`` labels of synthetic data."""
    if "is_anomaly" not in transactions.columns:
        raise ValueError("evaluation needs labelled data (column is_anomaly)")
    detector = detector or AnomalyDetector().fit(transactions)
    scored = detector.score(transactions)
    y_true = scored["is_anomaly"].astype(bool).to_numpy()
    enough = scored["category_count"] >= detector.min_category_history

    def flags(mask) -> dict:
        mask = np.asarray(mask, dtype=bool)
        return {
            "precision": float(precision_score(y_true, mask, zero_division=0)),
            "recall": float(recall_score(y_true, mask, zero_division=0)),
            "f1": float(f1_score(y_true, mask, zero_division=0)),
            "flag_rate": float(mask.mean()),
        }

    return {
        **flags(scored["is_flagged"]),
        "roc_auc": float(roc_auc_score(y_true, scored["anomaly_score"])),
        "average_precision": float(average_precision_score(y_true, scored["anomaly_score"])),
        "label_rate": float(y_true.mean()),
        "n": int(len(scored)),
        "baselines": {
            "ratio_rule_5x": flags((scored["ratio_category"] >= 5) & enough),
            "robust_z_rule_2.5": flags((scored["z_category"] >= 2.5) & enough),
        },
    }
