"""Build every Chapter 3 figure as a Figma-importable SVG.

    python build.py            # writes ./*.svg
    python build.py --list     # just list the filenames
"""

import sys
from pathlib import Path

import d_algo
import d_architecture
import d_dfd
import d_flow
import d_struct

HERE = Path(__file__).resolve().parent
MODULES = [d_architecture, d_dfd, d_flow, d_algo, d_struct]


def main():
    listing = "--list" in sys.argv
    made = []
    for mod in MODULES:
        for maker in mod.FIGURES:
            name, fig = maker()
            made.append(name)
            if not listing:
                (HERE / name).write_text(fig.svg(), encoding="utf-8")
    for n in made:
        print(n)
    print("\n%d figures%s" % (len(made), "" if listing else " written to %s" % HERE))


if __name__ == "__main__":
    main()
