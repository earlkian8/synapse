"""The attrition model and the candidates it was chosen against.

Every candidate shares :func:`.features.preprocessor`, so the comparison is between
estimators, not preprocessing. The pipeline's step names (``prep``, ``clf``) are part
of the serving contract — the inference service reads ``prep`` to align inputs.
"""

from __future__ import annotations

from sklearn.dummy import DummyClassifier
from sklearn.ensemble import ExtraTreesClassifier, HistGradientBoostingClassifier, RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline

from .features import preprocessor

SEED = 42
CHOSEN = "Random Forest"


def pipeline(estimator, scale: bool = False) -> Pipeline:
    """Shared preprocessing followed by ``estimator``."""
    return Pipeline([("prep", preprocessor(scale=scale)), ("clf", estimator)])


def random_forest(seed: int = SEED) -> Pipeline:
    """The served model. Shallow-leaved and class-balanced for a small, imbalanced table;
    single-process because many small fits are faster without worker start-up."""
    return pipeline(
        RandomForestClassifier(
            n_estimators=300,
            min_samples_leaf=3,
            max_features="sqrt",
            class_weight="balanced_subsample",
            random_state=seed,
            n_jobs=1,
        )
    )


def candidates(seed: int = SEED) -> dict[str, Pipeline]:
    """Every model compared in the notebook, the prior-only baseline first."""
    return {
        "Baseline (prior)": pipeline(DummyClassifier(strategy="prior")),
        "Logistic Regression": pipeline(LogisticRegression(C=0.3, class_weight="balanced", max_iter=2000), scale=True),
        CHOSEN: random_forest(seed),
        "Extra Trees": pipeline(
            ExtraTreesClassifier(
                n_estimators=300, min_samples_leaf=3, class_weight="balanced_subsample", random_state=seed, n_jobs=1
            )
        ),
        "Gradient Boosting": pipeline(
            HistGradientBoostingClassifier(
                max_depth=3, learning_rate=0.05, max_iter=150, class_weight="balanced", random_state=seed
            )
        ),
    }
