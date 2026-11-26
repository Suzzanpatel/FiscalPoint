# models/

Trained model artifacts are written here by the training pipeline and are not committed:

```bash
cd ml
python -m fiscalpoint_ml.train                                # synthetic data
python -m fiscalpoint_ml.train --source db --augment-synthetic   # your database + synthetic history
```

Files: `forecaster.joblib`, `categorizer.joblib`, `anomaly.joblib`, `budget_risk.joblib`, `metadata.json`.
See `docs/ML_PIPELINE.md`.
