"""Convenience entrypoint: `python -m api` runs the service with uvicorn."""

from __future__ import annotations

import os

import uvicorn

if __name__ == "__main__":
    uvicorn.run(
        "api.main:app",
        host=os.environ.get("ML_HOST", "127.0.0.1"),
        port=int(os.environ.get("ML_PORT", "8001")),
        reload=bool(os.environ.get("ML_RELOAD")),
    )
