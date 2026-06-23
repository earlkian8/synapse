"""
synapse_ml — shared plumbing for the Synapse HR-ERP modelling notebooks.

The three notebooks (attrition / performance / promotion) all import this module so
they share one consistent way to:

* **log everything** — every run opens a timestamped log file under ``logs/`` *and*
  appends to a per-model rolling log, plus mirrors to the notebook output. Nothing
  you do in a notebook is lost when the kernel restarts.
* **persist artifacts** — trained models, metric JSONs, evaluation plots and data
  checkpoints are written under ``artifacts/<model>/`` so results survive too.
* **resolve paths** — notebooks live in ``notebooks/`` but the CSVs sit in the
  ``model/`` root; this module finds the root no matter where the kernel started.

Usage from a notebook::

    import synapse_ml as sm
    run = sm.start_run("attrition")        # opens logging + artifact dir
    df  = run.load_csv("employee_attrition_dataset_10000.csv")
    ...
    run.save_metrics({"roc_auc": 0.87})
    run.save_model(pipeline, "attrition_model")
    run.save_fig(fig, "confusion_matrix")
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

# --------------------------------------------------------------------------------------
# Path resolution
# --------------------------------------------------------------------------------------

# This file lives in the model/ root, so its parent IS the model root regardless of
# whether a notebook was launched from notebooks/ or from model/.
MODEL_DIR: Path = Path(__file__).resolve().parent
LOGS_DIR: Path = MODEL_DIR / "logs"
ARTIFACTS_DIR: Path = MODEL_DIR / "artifacts"

_TIMESTAMP_FMT = "%Y%m%d_%H%M%S"


def _now_stamp() -> str:
    return datetime.now().strftime(_TIMESTAMP_FMT)


# --------------------------------------------------------------------------------------
# Run context
# --------------------------------------------------------------------------------------


@dataclass
class Run:
    """A single modelling session: its logger, its artifact directory, and helpers
    to load data and checkpoint results."""

    name: str
    started_at: datetime = field(default_factory=datetime.now)
    log: logging.Logger = field(init=False)
    artifact_dir: Path = field(init=False)
    _log_path: Path = field(init=False)

    def __post_init__(self) -> None:
        LOGS_DIR.mkdir(parents=True, exist_ok=True)
        self.artifact_dir = ARTIFACTS_DIR / self.name
        self.artifact_dir.mkdir(parents=True, exist_ok=True)

        stamp = self.started_at.strftime(_TIMESTAMP_FMT)
        self._log_path = LOGS_DIR / f"{self.name}_{stamp}.log"
        rolling_path = LOGS_DIR / f"{self.name}.log"

        logger = logging.getLogger(f"synapse_ml.{self.name}")
        logger.setLevel(logging.DEBUG)
        # Reset handlers so re-running the setup cell in a notebook doesn't duplicate
        # every log line.
        for handler in list(logger.handlers):
            logger.removeHandler(handler)
            handler.close()

        fmt = logging.Formatter(
            "%(asctime)s | %(levelname)-7s | %(message)s", datefmt="%Y-%m-%d %H:%M:%S"
        )

        # 1) timestamped file — one immutable record per run
        run_file = logging.FileHandler(self._log_path, encoding="utf-8")
        run_file.setLevel(logging.DEBUG)
        run_file.setFormatter(fmt)
        logger.addHandler(run_file)

        # 2) rolling file — the full history of every run for this model, appended
        rolling = logging.FileHandler(rolling_path, encoding="utf-8")
        rolling.setLevel(logging.INFO)
        rolling.setFormatter(fmt)
        logger.addHandler(rolling)

        # 3) console — mirror INFO+ to the notebook output
        console = logging.StreamHandler(stream=sys.stdout)
        console.setLevel(logging.INFO)
        console.setFormatter(logging.Formatter("%(levelname)-7s | %(message)s"))
        logger.addHandler(console)

        logger.propagate = False
        self.log = logger

        self.log.info("=" * 78)
        self.log.info("RUN START · model=%s · %s", self.name, self.started_at.isoformat(timespec="seconds"))
        self.log.info("python=%s · platform=%s", platform.python_version(), platform.platform())
        self.log.info("log file=%s", self._log_path)
        self.log.info("artifacts=%s", self.artifact_dir)
        self._log_library_versions()

    # ---- environment ----------------------------------------------------------------

    def _log_library_versions(self) -> None:
        versions: dict[str, str] = {}
        for mod in ("numpy", "pandas", "scipy", "sklearn", "matplotlib", "seaborn"):
            try:
                versions[mod] = __import__(mod).__version__
            except Exception:  # pragma: no cover - best effort
                versions[mod] = "n/a"
        self.log.info("libs · %s", ", ".join(f"{k}={v}" for k, v in versions.items()))

    # ---- data loading -----------------------------------------------------------------

    def load_csv(self, filename: str, **read_csv_kwargs: Any):
        """Load a CSV from the model/ root and log its shape/dtypes so the EDA state
        is captured even if the cell output is later cleared."""
        import pandas as pd

        path = MODEL_DIR / filename
        self.log.info("loading data · %s", path)
        df = pd.read_csv(path, **read_csv_kwargs)
        self.log.info("loaded shape=%s · columns=%d", df.shape, df.shape[1])
        self.log.debug("dtypes:\n%s", df.dtypes.to_string())
        self.log.debug("null counts:\n%s", df.isna().sum().to_string())
        return df

    def checkpoint_df(self, df, name: str):
        """Persist a (possibly engineered) dataframe to the artifact dir as parquet,
        falling back to CSV if no parquet engine is installed."""
        target = self.artifact_dir / f"{name}.parquet"
        try:
            df.to_parquet(target)
        except Exception as exc:  # pragma: no cover - parquet engine optional
            target = self.artifact_dir / f"{name}.csv"
            df.to_csv(target, index=False)
            self.log.warning("parquet unavailable (%s); wrote CSV instead", exc)
        self.log.info("checkpoint dataframe · %s · shape=%s", target.name, df.shape)
        return target

    # ---- artifact persistence ---------------------------------------------------------

    def save_metrics(self, metrics: dict[str, Any], name: str = "metrics") -> Path:
        """Write a metrics dict to JSON and echo it to the log."""
        target = self.artifact_dir / f"{name}.json"
        payload = {"model": self.name, "saved_at": datetime.now().isoformat(timespec="seconds"), **metrics}
        target.write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
        self.log.info("saved metrics · %s", target.name)
        for k, v in metrics.items():
            if isinstance(v, (int, float)):
                self.log.info("  %-28s = %.4f", k, v)
            else:
                self.log.info("  %-28s = %s", k, v)
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
        elapsed = (datetime.now() - self.started_at).total_seconds()
        if summary:
            self.log.info("summary · %s", summary)
        self.log.info("RUN END · model=%s · elapsed=%.1fs", self.name, elapsed)
        self.log.info("=" * 78)


def start_run(name: str) -> Run:
    """Open a logged modelling run for the given model name."""
    return Run(name=name)


# --------------------------------------------------------------------------------------
# Small shared modelling helpers
# --------------------------------------------------------------------------------------


def split_feature_types(df, target: str, drop: list[str] | None = None):
    """Return (numeric_cols, categorical_cols) for everything except the target and any
    explicitly dropped identifier columns."""
    drop = set(drop or []) | {target}
    features = [c for c in df.columns if c not in drop]
    numeric, categorical = [], []
    for col in features:
        if str(df[col].dtype) in ("object", "category", "bool"):
            categorical.append(col)
        else:
            numeric.append(col)
    return numeric, categorical
