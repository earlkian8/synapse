"""The attrition model (synapse_ml.attrition.model): fitted the way it is served, it
must be repeatable and must rank by the answers, never by chance."""

from __future__ import annotations

import numpy as np
import pytest
from sklearn.base import clone

from synapse_ml.attrition import features, survey
from synapse_ml.attrition import model as models


@pytest.fixture(scope="module")
def data():
    tidy, _ = survey.load()
    return tidy[features.FEATURES], tidy[survey.TARGET]


def test_the_chosen_model_is_one_of_the_candidates():
    assert models.CHOSEN in models.candidates()


def test_two_fits_on_the_same_rows_score_identically(data):
    X, y = data
    first = models.fit(models.svm(), X, y).predict_proba(X)
    second = models.fit(models.svm(), X, y).predict_proba(X)

    assert np.array_equal(first, second)


def test_fit_weighs_both_outcomes_equally(data):
    # Unweighted, scores sink toward the stay rate and few would reach the tiers.
    X, y = data
    weighted = models.fit(models.svm(), X, y).predict_proba(X)[:, 1]
    unweighted = models.svm().fit(X, y).predict_proba(X)[:, 1]

    assert weighted.mean() > unweighted.mean()


def test_every_candidate_fits_and_scores_a_probability(data):
    X, y = data
    for name, pipeline in models.candidates().items():
        scores = models.fit(clone(pipeline), X, y).predict_proba(X.head(5))[:, 1]
        assert ((scores >= 0) & (scores <= 1)).all(), name
