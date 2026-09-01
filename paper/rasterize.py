"""Rasterise every Chapter III SVG to a print-resolution PNG for the .docx build.

Headless Edge (or Chrome) renders each SVG at its intrinsic size with a device
scale factor, so the output is a true N x oversample of the vector artwork —
crisp at 300 dpi once python-docx scales it back down to the text column.

    python rasterize.py            # 3x into diagrams/print/
    python rasterize.py --scale 2  # lighter files
"""
from __future__ import annotations

import argparse
import re
import shutil
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

HERE = Path(__file__).resolve().parent
SVG_DIR = HERE / "diagrams"
OUT_DIR = SVG_DIR / "print"

BROWSERS = [
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Google\Chrome\Application\chrome.exe",
    r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
]

SIZE_RE = re.compile(r'width="(\d+(?:\.\d+)?)"\s+height="(\d+(?:\.\d+)?)"')


def find_browser() -> str:
    for candidate in BROWSERS:
        if Path(candidate).exists():
            return candidate
    found = shutil.which("msedge") or shutil.which("chrome")
    if found:
        return found
    sys.exit("No Edge/Chrome binary found — cannot rasterise.")


def intrinsic_size(svg: Path) -> tuple[int, int]:
    head = svg.read_text(encoding="utf-8")[:1200]
    match = SIZE_RE.search(head)
    if not match:
        sys.exit(f"{svg.name}: no width/height on the root <svg>")
    return round(float(match.group(1))), round(float(match.group(2)))


def shoot(browser: str, svg: Path, scale: float) -> tuple[str, tuple[int, int]]:
    width, height = intrinsic_size(svg)
    out = OUT_DIR / f"{svg.stem}.png"
    # A throwaway profile per shot keeps concurrent Edge instances from fighting
    # over one user-data dir, which silently drops screenshots.
    with tempfile.TemporaryDirectory(prefix="svgshot-") as profile:
        subprocess.run(
            [
                browser,
                "--headless=new",
                "--disable-gpu",
                "--hide-scrollbars",
                "--default-background-color=FFFFFFFF",
                f"--user-data-dir={profile}",
                f"--force-device-scale-factor={scale}",
                f"--window-size={width},{height}",
                f"--screenshot={out}",
                svg.resolve().as_uri(),
            ],
            check=True,
            capture_output=True,
        )
    if not out.exists():
        sys.exit(f"{svg.name}: browser produced no screenshot")
    return svg.stem, (round(width * scale), round(height * scale))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--scale", type=float, default=3.0)
    parser.add_argument("--jobs", type=int, default=6)
    args = parser.parse_args()

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    svgs = sorted(
        SVG_DIR.glob("fig-3-*.svg"),
        key=lambda p: int(re.match(r"fig-3-(\d+)", p.stem).group(1)),
    )
    browser = find_browser()
    print(f"{len(svgs)} figures -> {OUT_DIR} at {args.scale}x")

    with ThreadPoolExecutor(max_workers=args.jobs) as pool:
        for stem, size in pool.map(lambda s: shoot(browser, s, args.scale), svgs):
            print(f"  {stem:<44} {size[0]}x{size[1]}")


if __name__ == "__main__":
    main()
