"""Saving and loading trained model artifacts.

Layout of the model directory (default ``<project>/models``)::

    models/
      forecaster.joblib     GlobalForecaster
      categorizer.joblib    ExpenseCategorizer
      anomaly.joblib        AnomalyDetector
      budget_risk.joblib    BudgetRiskModel
      metadata.json         versions, training data summary and all evaluation metrics

Artifacts are pickles (joblib): only load files you produced yourself.
"""

from __future__ import annotations

import json
import logging
import platform
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import joblib
import sklearn

from . import __version__

logger = logging.getLogger(__name__)

ARTIFACTS = ("forecaster", "categorizer", "anomaly", "budget_risk")


@dataclass
class ModelBundle:
    forecaster: object | None = None
    categorizer: object | None = None
    anomaly: object | None = None
    budget_risk: object | None = None
    metadata: dict | None = None

    @property
    def loaded(self) -> dict:
        return {name: getattr(self, name) is not None for name in ARTIFACTS}


def save(bundle: ModelBundle, directory: Path, metadata: dict) -> Path:
    directory = Path(directory)
    directory.mkdir(parents=True, exist_ok=True)
    for name in ARTIFACTS:
        model = getattr(bundle, name)
        if model is not None:
            joblib.dump(model, directory / f"{name}.joblib", compress=3)
    full = {
        "package_version": __version__,
        "sklearn_version": sklearn.__version__,
        "python_version": platform.python_version(),
        "trained_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        **metadata,
    }
    (directory / "metadata.json").write_text(json.dumps(full, indent=2, default=str))
    return directory


def load(directory: Path) -> ModelBundle:
    """Load whatever artifacts exist; missing ones are ``None`` (the API degrades gracefully)."""
    directory = Path(directory)
    bundle = ModelBundle()
    meta_path = directory / "metadata.json"
    if meta_path.exists():
        bundle.metadata = json.loads(meta_path.read_text())
        trained_with = bundle.metadata.get("sklearn_version")
        if trained_with and trained_with != sklearn.__version__:
            logger.warning("models were trained with scikit-learn %s but %s is installed; retrain if loading fails",
                           trained_with, sklearn.__version__)
    for name in ARTIFACTS:
        path = directory / f"{name}.joblib"
        if path.exists():
            try:
                setattr(bundle, name, joblib.load(path))
            except Exception:
                logger.exception("could not load %s", path)
    return bundle
