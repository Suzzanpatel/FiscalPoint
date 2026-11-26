"""Expense categorizer: suggests a category from the description the user types.

Model
-----
A scikit-learn pipeline:

* description -> TF-IDF over character n-grams (2-5, word-boundary aware). Character
  n-grams are robust to typos ("swigy", "electrcity"), casing and Hinglish spellings.
* description -> TF-IDF over word uni/bi-grams (captures phrases like "loan emi").
* payment method -> one-hot.
* amount -> log-amount quantile bins (rent and EMIs are large, snacks are small);
  an unknown amount is encoded as "no information", not as zero.
* -> multinomial logistic regression with balanced class weights, which yields
  calibrated-ish probabilities used to show the top-3 suggestions.

Evaluation uses a stratified hold-out split and reports accuracy, macro-F1, top-3
accuracy and the per-class report. Vague descriptions ("payment", "paid") are part of
the synthetic data on purpose, so the scores are not unrealistically perfect.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix, f1_score, top_k_accuracy_score
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.base import BaseEstimator, TransformerMixin
from sklearn.preprocessing import FunctionTransformer, OneHotEncoder

from .config import CATEGORIES

INPUT_COLUMNS = ["description", "payment_method", "amount"]


class AmountBins(BaseEstimator, TransformerMixin):
    """One-hot quantile bins of log(amount). A missing amount encodes as all zeros.

    Encoding "unknown" as 0 rupees (the naive approach) would put it in the smallest-amount
    bin and bias suggestions towards snacks and auto rides while the user has not typed the
    amount yet.
    """

    def __init__(self, n_bins: int = 8):
        self.n_bins = n_bins

    def fit(self, X, y=None):
        values = self._log(X)
        known = values[~np.isnan(values)]
        quantiles = np.quantile(known, np.linspace(0, 1, self.n_bins + 1)[1:-1]) if known.size else np.array([])
        self.edges_ = np.unique(quantiles)
        return self

    def transform(self, X):
        values = self._log(X)
        out = np.zeros((len(values), len(self.edges_) + 1))
        known = ~np.isnan(values)
        out[np.flatnonzero(known), np.searchsorted(self.edges_, values[known], side="right")] = 1.0
        return out

    @staticmethod
    def _log(X):
        arr = np.array(X, dtype=float).ravel()          # copy: inputs may be read-only views
        arr[~(arr > 0)] = np.nan
        return np.log(arr)


def _text(values):
    return pd.Series(np.asarray(values).ravel()).fillna("").astype(str).str.lower().to_numpy()


def build_pipeline(C: float = 8.0) -> Pipeline:
    features = ColumnTransformer([
        ("chars", Pipeline([("clean", FunctionTransformer(_text)),
                            ("tfidf", TfidfVectorizer(analyzer="char_wb", ngram_range=(2, 5), min_df=2, sublinear_tf=True))]),
         "description"),
        ("words", Pipeline([("clean", FunctionTransformer(_text)),
                            ("tfidf", TfidfVectorizer(analyzer="word", ngram_range=(1, 2), min_df=1, sublinear_tf=True))]),
         "description"),
        ("payment", OneHotEncoder(handle_unknown="ignore"), ["payment_method"]),
        ("amount", AmountBins(n_bins=8), "amount"),
    ])
    classifier = LogisticRegression(C=C, max_iter=3000, class_weight="balanced")
    return Pipeline([("features", features), ("classifier", classifier)])


def _inputs(frame: pd.DataFrame) -> pd.DataFrame:
    data = pd.DataFrame(index=frame.index)
    data["description"] = frame.get("description", "").fillna("").astype(str)
    data["payment_method"] = frame.get("payment_method", "").fillna("").astype(str)
    data["amount"] = pd.to_numeric(frame.get("amount", np.nan), errors="coerce")
    return data


@dataclass
class ExpenseCategorizer:
    C: float = 8.0
    min_confidence: float = 0.20
    pipeline_: Pipeline | None = field(default=None, repr=False)
    classes_: list[str] = field(default_factory=list)

    def fit(self, transactions: pd.DataFrame) -> "ExpenseCategorizer":
        data = transactions[transactions["description"].fillna("").str.strip() != ""]
        data = data[data["category"].isin(CATEGORIES)]          # custom categories are too sparse to learn
        if data["category"].nunique() < 2:
            raise ValueError("need descriptions from at least two categories to train the categorizer")
        self.pipeline_ = build_pipeline(self.C).fit(_inputs(data), data["category"])
        self.classes_ = list(self.pipeline_.classes_)
        return self

    def predict_proba(self, frame: pd.DataFrame) -> np.ndarray:
        if self.pipeline_ is None:
            raise RuntimeError("categorizer is not fitted")
        return self.pipeline_.predict_proba(_inputs(frame))

    def suggest(self, description: str, amount: float | None = None, payment_method: str | None = None,
                top_k: int = 3) -> list[dict]:
        """Top-k category suggestions with probabilities (empty when nothing is confident)."""
        if not description or not description.strip():
            return []
        frame = pd.DataFrame([{"description": description, "amount": amount, "payment_method": payment_method or ""}])
        proba = self.predict_proba(frame)[0]
        order = np.argsort(proba)[::-1][:top_k]
        return [
            {"category": self.classes_[i], "probability": round(float(proba[i]), 4)}
            for i in order if proba[i] >= self.min_confidence
        ]


def evaluate(transactions: pd.DataFrame, test_size: float = 0.2, seed: int = 0, C: float = 8.0) -> tuple[dict, ExpenseCategorizer]:
    """Hold-out evaluation; returns (metrics, model fitted on the training split)."""
    data = transactions[(transactions["description"].fillna("").str.strip() != "") & transactions["category"].isin(CATEGORIES)]
    train, test = train_test_split(data, test_size=test_size, random_state=seed, stratify=data["category"])
    model = ExpenseCategorizer(C=C).fit(train)
    proba = model.predict_proba(test)
    predicted = np.array(model.classes_)[proba.argmax(axis=1)]
    labels = model.classes_
    metrics = {
        "accuracy": float(accuracy_score(test["category"], predicted)),
        "macro_f1": float(f1_score(test["category"], predicted, average="macro")),
        "top3_accuracy": float(top_k_accuracy_score(test["category"], proba, k=3, labels=labels)),
        "n_train": int(len(train)),
        "n_test": int(len(test)),
        "per_class": classification_report(test["category"], predicted, output_dict=True, zero_division=0),
        "labels": labels,
        "confusion_matrix": confusion_matrix(test["category"], predicted, labels=labels).tolist(),
    }
    return metrics, model
