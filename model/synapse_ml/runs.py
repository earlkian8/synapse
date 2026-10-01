"""Logged modelling runs: one log file per run, a rolling log per model, and artifact
persistence under ``artifacts/<model>/``.

Every notebook opens a run first thing, so nothing it does is lost when a kernel dies
or cell outputs are cleared::

    run = start_run("promotion")
    df = run.load_csv("employee_promotion_prediction.csv")
    run.save_model(pipeline, "promotion_model")
    run.save_metrics({"roc_auc": 0.87})
"""

from __future__ import annotations

import json
import logging
import platform
import sys
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Any

from .paths import ARTIFACTS_DIR, LOGS_DIR, RAW_DATA_DIR

_TIMESTAMP_FMT = "%Y%m%d_%H%M%S"
_LOG_FORMAT = logging.Formatter("%(asctime)s | %(levelname)-7s | %(message)s", datefmt="%Y-%m-%d %H:%M:%S")


@dataclass
class Run:
    """A single modelling session: its logger, its artifact directory, and helpers to
    load data and persist results."""

    name: str
    started_at: datetime = field(default_factory=datetime.now)
    log: logging.Logger = field(init=False)
    artifact_dir: Path = field(init=False)

    def __post_init__(self) -> None:
        LOGS_DIR.mkdir(parents=True, exist_ok=True)
        self.artifact_dir = ARTIFACTS_DIR / self.name
        self.artifact_dir.mkdir(parents=True, exist_ok=True)

        log_path = LOGS_DIR / f"{self.name}_{self.started_at.strftime(_TIMESTAMP_FMT)}.log"
        self.log = self._logger(log_path)

        self.log.info("=" * 78)
        self.log.info("RUN START · model=%s · %s", self.name, self.started_at.isoformat(timespec="seconds"))
        self.log.info("python=%s · platform=%s", platform.python_version(), platform.platform())
        self.log.info("log file=%s", log_path)
        self.log.info("artifacts=%s", self.artifact_dir)
        self.log.info("libs · %s", _library_versions())

    def _logger(self, log_path: Path) -> logging.Logger:
        logger = logging.getLogger(f"synapse_ml.{self.name}")
        logger.setLevel(logging.DEBUG)
        logger.propagate = False
        # Re-running the setup cell must not duplicate every line.
        for handler in list(logger.handlers):
            logger.removeHandler(handler)
            handler.close()

        per_run = logging.FileHandler(log_path, encoding="utf-8")  # one immutable record per run
        per_run.setLevel(logging.DEBUG)
        rolling = logging.FileHandler(LOGS_DIR / f"{self.name}.log", encoding="utf-8")  # every run, appended
        rolling.setLevel(logging.INFO)
        for handler in (per_run, rolling):
            handler.setFormatter(_LOG_FORMAT)
            logger.addHandler(handler)

        console = logging.StreamHandler(stream=sys.stdout)  # mirrored into the notebook
        console.setLevel(logging.INFO)
        console.setFormatter(logging.Formatter("%(levelname)-7s | %(message)s"))
        logger.addHandler(console)
        return logger

    # ---- data -------------------------------------------------------------------------

    def load_csv(self, filename: str, **read_csv_kwargs: Any):
        """Load a raw dataset from ``data/raw/`` and log its shape, dtypes and nulls."""
        import pandas as pd

        path = RAW_DATA_DIR / filename
        self.log.info("loading data · %s", path)
        df = pd.read_csv(path, **read_csv_kwargs)
        self.log.info("loaded shape=%s · columns=%d", df.shape, df.shape[1])
        self.log.debug("dtypes:\n%s", df.dtypes.to_string())
        self.log.debug("null counts:\n%s", df.isna().sum().to_string())
        return df

    def checkpoint_df(self, df, name: str) -> Path:
        """Persist a dataframe to the artifact dir (parquet, or CSV without an engine)."""
        target = self.artifact_dir / f"{name}.parquet"
        try:
            df.to_parquet(target)
        except Exception as exc:  # parquet engine is optional
            target = self.artifact_dir / f"{name}.csv"
            df.to_csv(target, index=False)
            self.log.warning("parquet unavailable (%s); wrote CSV instead", exc)
        self.log.info("checkpoint dataframe · %s · shape=%s", target.name, df.shape)
        return target

    # ---- artifacts --------------------------------------------------------------------

    def save_metrics(self, metrics: dict[str, Any], name: str = "metrics") -> Path:
        """Write a metrics dict to JSON (stamped with the model and time) and log it."""
        target = self.artifact_dir / f"{name}.json"
        payload = {"model": self.name, "saved_at": datetime.now().isoformat(timespec="seconds"), **metrics}
        target.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
        self.log.info("saved metrics · %s", target.name)
        for key, value in metrics.items():
            self.log.info("  %-28s = %s", key, f"{value:.4f}" if isinstance(value, (int, float)) else value)
        return target

    def save_json(self, payload: dict[str, Any], name: str) -> Path:
        """Write an arbitrary JSON document (e.g. a feature contract) to the artifact dir."""
        target = self.artifact_dir / f"{name}.json"
        target.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
        self.log.info("saved %s", target.name)
        return target

    def save_model(self, obj: Any, name: str) -> Path:
        """Persist a fitted estimator/pipeline with joblib."""
        import joblib

        target = self.artifact_dir / f"{name}.joblib"
        joblib.dump(obj, target)
        self.log.info("saved model · %s (%.1f KB)", target.name, target.stat().st_size / 1024)
        return target

    def save_fig(self, fig, name: str, dpi: int = 120) -> Path:
        """Save a matplotlib figure to the artifact dir."""
        target = self.artifact_dir / f"{name}.png"
        fig.savefig(target, dpi=dpi, bbox_inches="tight")
        self.log.info("saved figure · %s", target.name)
        return target

    def finish(self, summary: str | None = None) -> None:
        if summary:
            self.log.info("summary · %s", summary)
        elapsed = (datetime.now() - self.started_at).total_seconds()
        self.log.info("RUN END · model=%s · elapsed=%.1fs", self.name, elapsed)
        self.log.info("=" * 78)


def start_run(name: str) -> Run:
    """Open a logged modelling run for the given model name."""
    return Run(name=name)


def _library_versions() -> str:
    versions = {}
    for module in ("numpy", "pandas", "scipy", "sklearn", "matplotlib", "seaborn"):
        try:
            versions[module] = __import__(module).__version__
        except ImportError:
            versions[module] = "n/a"
    return ", ".join(f"{k}={v}" for k, v in versions.items())
