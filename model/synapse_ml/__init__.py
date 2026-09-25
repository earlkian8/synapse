"""Shared code for the Synapse HR-ERP models: run logging and persistence, data paths,
and the attrition model's data pipeline. Imported by the notebooks, the tests and the
inference service."""

from .runs import Run, start_run
from .tabular import split_feature_types

__all__ = ["Run", "start_run", "split_feature_types"]
