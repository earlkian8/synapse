"""Where each organisation's own models live: ``artifacts/local/<tenant>/<model>/<version>/``.

A model fitted on one organisation's records *is* that organisation's data, so it is
stored under that organisation only and loaded only when that organisation names it.
Tenant keys and versions are checked against strict patterns before they reach the
filesystem, so neither can walk outside the store.
"""

from __future__ import annotations

import json
import re
import secrets
from datetime import datetime
from pathlib import Path
from typing import Any

import joblib

from ..paths import ARTIFACTS_DIR

LOCAL_DIR = ARTIFACTS_DIR / "local"

TENANT = re.compile(r"^[a-z0-9][a-z0-9-]{0,63}$")
VERSION = re.compile(r"^\d{14}-[0-9a-f]{6}$")
MODELS = ("promotion", "performance", "attrition")


class UnknownLocalModel(LookupError):
    """No stored model for that organisation, surface and version."""


def directory(tenant: str, model: str, version: str, root: Path | None = None) -> Path:
    if not TENANT.fullmatch(tenant or ""):
        raise UnknownLocalModel("malformed tenant key")
    if model not in MODELS:
        raise UnknownLocalModel(f"no local models for '{model}'")
    if not VERSION.fullmatch(version or ""):
        raise UnknownLocalModel("malformed version")
    return (root or LOCAL_DIR) / tenant / model / version


def save(tenant: str, model: str, fitted: Any, metrics: dict[str, Any], root: Path | None = None) -> str:
    """Persist a fitted model and its metrics; returns the new version."""
    version = f"{datetime.now():%Y%m%d%H%M%S}-{secrets.token_hex(3)}"
    target = directory(tenant, model, version, root)
    target.mkdir(parents=True, exist_ok=False)
    joblib.dump(fitted, target / "model.joblib")
    payload = {"model": model, "tenant": tenant, "version": version,
               "saved_at": datetime.now().isoformat(timespec="seconds"), **metrics}
    (target / "metrics.json").write_text(json.dumps(payload, indent=2, default=str), encoding="utf-8")
    return version


def load(tenant: str, model: str, version: str, root: Path | None = None) -> tuple[Any, dict[str, Any]]:
    """The fitted model and its metrics, or :class:`UnknownLocalModel`."""
    target = directory(tenant, model, version, root)
    path = target / "model.joblib"
    if not path.exists():
        raise UnknownLocalModel(f"no '{model}' model {version} for {tenant}")
    metrics_path = target / "metrics.json"
    metrics = json.loads(metrics_path.read_text(encoding="utf-8")) if metrics_path.exists() else {}
    return joblib.load(path), metrics
