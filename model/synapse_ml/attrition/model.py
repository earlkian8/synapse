"""The attrition model and the candidates it was chosen against.

Every candidate shares :func:`.features.preprocessor`, so the comparison is between
estimators, not preprocessing, and every one is fitted through :func:`fit`, so each
weighs the two outcomes the same way. The pipeline's step names (``prep``, ``clf``) are
part of the serving contract — the inference service reads ``prep`` to align inputs.
"""

from __future__ import annotations

from sklearn.calibration import CalibratedClassifierCV
from sklearn.dummy import DummyClassifier
from sklearn.ensemble import ExtraTreesClassifier, HistGradientBoostingClassifier, RandomForestClassifier
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.svm import SVC
from sklearn.utils.class_weight import compute_sample_weight

from .features import preprocessor

SEED = 42
CHOSEN = "SVM (RBF)"


def pipeline(estimator, scale: bool = False, numeric: list[str] | None = None) -> Pipeline:
    """Shared preprocessing followed by ``estimator``."""
    return Pipeline([("prep", preprocessor(scale=scale, numeric=numeric)), ("clf", estimator)])


def fit(model: Pipeline, X, y) -> Pipeline:
    """Fit with both outcomes weighted equally.

    Leavers are a minority, so unweighted every score sinks toward the stay rate. Equal
    weights keep the score on the scale the tiers are cut on — 50 is "as much like the
    people who left as like those who stayed" — and an isotonic calibrator fitted with
    the same weights stays on that scale.
    """
    return model.fit(X, y, clf__sample_weight=compute_sample_weight("balanced", y))


def svm(C: float = 1.0, gamma: float | str = 0.3, numeric: list[str] | None = None) -> Pipeline:
    """The served model: an RBF support-vector machine on the standardised bands.

    It ranks people by distance to the answer patterns of those who left, which suits a
    small table whose signal is in non-monotone shapes (new hires *and* mid-tenure staff
    leave most). Its scores are calibrated isotonically — monotone by construction, so
    calibrating on a few dozen held-out rows can reshape the scale but never invert the
    ranking, as a sigmoid fitted on that few can. Five calibrated folds are averaged.
    Deterministic: no step draws random numbers.
    """
    return pipeline(
        CalibratedClassifierCV(SVC(C=C, gamma=gamma), method="isotonic", ensemble=True, cv=5),
        scale=True,
        numeric=numeric,
    )


def candidates(seed: int = SEED) -> dict[str, Pipeline]:
    """Every model compared in the notebook, the prior-only baseline first.
    Single-process because many small fits are faster without worker start-up."""
    return {
        "Baseline (prior)": pipeline(DummyClassifier(strategy="prior")),
        "Logistic Regression": pipeline(LogisticRegression(C=0.3, max_iter=2000), scale=True),
        "Random Forest": pipeline(
            RandomForestClassifier(n_estimators=300, min_samples_leaf=3, max_features="sqrt", random_state=seed, n_jobs=1)
        ),
        "Extra Trees": pipeline(
            ExtraTreesClassifier(n_estimators=300, min_samples_leaf=3, random_state=seed, n_jobs=1)
        ),
        "Gradient Boosting": pipeline(
            HistGradientBoostingClassifier(max_depth=3, learning_rate=0.05, max_iter=150, random_state=seed)
        ),
        CHOSEN: svm(),
    }
