"""Shared code for the Synapse HR-ERP models: run logging and persistence, data paths,
the attrition model's data pipeline, and the appraisal models (performance forecast and
promotion readiness) with the reference workforce they share. Imported by the
notebooks, the tests and the inference service."""

from .runs import Run, start_run

__all__ = ["Run", "start_run"]
