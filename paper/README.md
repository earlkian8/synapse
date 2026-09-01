# Chapter III — revised manuscript and figure set

| Path | What it is |
|---|---|
| `CHAPTER-3-REVISED.md` | The full revised Chapter III. Every section the original chapter had, plus the design sections it was missing, plus the complete 69-table data dictionary. Figure placeholders are marked inline. |
| `diagrams/*.svg` | The 36 Chapter III figures, ready to import into Figma. |
| `diagrams/previews/*.png` | Rasterised previews of every figure, for quick review without opening Figma. |
| `diagrams/print/*.png` | The same 36 figures at 3x scale, which is what the `.docx` embeds. Regenerable; ~12 MB. |
| `rasterize.py` | Renders `diagrams/*.svg` to `diagrams/print/*.png` at print resolution. |
| `build_docx.py` | Builds `../SYNAPSE-Chapter-3.docx` from the Markdown, figures placed and captioned. |
| `finalize.ps1` | Opens the built file in Word, builds the table of contents, and exports a PDF. |
| `diagrams/build.py` | Rebuilds every SVG from source. |
| `diagrams/svgkit.py` | The tiny SVG layout kit (shapes, orthogonal edge routing, label placement, auto-fit canvas). |
| `diagrams/d_architecture.py` | Figures 3.1 – 3.3 |
| `diagrams/d_dfd.py` | Figures 3.4 – 3.18 (context, Level 1, thirteen Level-2 module DFDs) |
| `diagrams/d_flow.py` | Figures 3.19 – 3.24 (system flowchart and five expanded flowcharts) |
| `diagrams/d_algo.py` | Figures 3.25 – 3.30 (decision-support algorithms, ML pipelines) |
| `diagrams/d_struct.py` | Figures 3.31 – 3.36 (use case, state, sequence, ERD, security, navigation) |

## Building the Word manuscript

```bash
cd paper
python rasterize.py        # 36 SVGs -> diagrams/print/*.png at 3x (needs Edge or Chrome)
python build_docx.py       # -> ../SYNAPSE-Chapter-3.docx
powershell -File finalize.ps1   # builds the TOC, exports ../SYNAPSE-Chapter-3.pdf
```

`build_docx.py` needs only `python-docx`. Layout decisions it makes for you:

* **Figures float.** A Level-2 DFD is unreadable at the 6-inch portrait column — its
  labels land near 3 pt — so every figure gets a full page at the orientation that
  renders it largest, and floats to the end of the section that discusses it rather
  than stranding a half-empty page where the Markdown mentions it. Set
  `FLOAT_FIGURES = False` to pin figures to their source position instead.
* **Tables turn landscape only when portrait would squeeze them.** The test is
  whether a prose column would drop below 1.6 inches, not raw width, so a short
  wide table still wraps in portrait instead of eating a page. Two tables qualify:
  the use case model and the traceability matrix.
* **Code listings shrink to fit** so no line wraps and the column alignment holds.
* **Page setup** is Letter, 1.5-inch binding margin, Times New Roman 12 at 1.5 line
  spacing, justified. All of it sits in named constants at the top of the file.

Re-run `finalize.ps1` after any rebuild — the TOC is a Word field and is empty until
Word updates it.

## Rebuilding the figures

```bash
cd paper/diagrams
python build.py           # writes all 36 .svg files
python build.py --list    # list filenames without writing
```

No third-party packages are required — only the Python standard library.

## Regenerating the previews (optional, Windows)

```powershell
$d = "<repo>\paper\diagrams"; $out = Join-Path $d "previews"
Get-ChildItem $d -Filter "fig-3-*.svg" | ForEach-Object {
  $t = Get-Content $_.FullName -TotalCount 1
  $w = [regex]::Match($t,'width="(\d+)"').Groups[1].Value
  $h = [regex]::Match($t,'height="(\d+)"').Groups[1].Value
  & "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe" --headless=new --disable-gpu `
    "--screenshot=$out\$($_.BaseName).png" "--window-size=$w,$h" `
    "file:///$($_.FullName -replace '\\','/' -replace ' ','%20')" | Out-Null
}
```

## Importing into Figma

1. In Figma, **File → Import…** (or drag the `.svg` files onto the canvas). Multi-select all 36 to
   bring them in as separate frames in one go.
2. Each figure arrives as an editable group: rectangles, paths, and live text. There are no
   embedded raster images, no `<style>` blocks, no CSS classes, and no SVG `<marker>` elements —
   every arrowhead is an explicit filled triangle — so nothing is flattened or dropped on import.
3. Fonts are declared as `Arial, Helvetica, sans-serif` (and `Consolas, 'Courier New', monospace`
   for schema and code text). Figma substitutes whatever is installed; if you want a different
   family, select all text in a frame and change it once.
4. The palette is deliberately greyscale (black `#111111` strokes on white, with `#F4F4F4` and
   `#E4E4E4` fills and `#5A5A5A` secondary text) so the figures print cleanly in a thesis and
   remain legible in photocopy. Recolour in Figma if the manuscript template calls for it.
5. To place a figure back into the manuscript, export from Figma as **SVG** (vector, best for
   Word/LaTeX) or as **PNG at 2×** if the template requires raster.

## Editing conventions if you extend the set

* Figure numbers are baked into both the title text and the filename; keep the two in sync with the
  *List of Figures* table at the end of `CHAPTER-3-REVISED.md`.
* Module-level DFDs are declarative: add a `dict(entities=…, processes=…, stores=…, externals=…,
  flows=…)` in `d_dfd.py` and register it in the `MODULES` list. Layout, routing, legend, and the
  canvas size are handled for you.
* The canvas auto-fits, so adding a row to a diagram will not push content off the edge — but do
  re-render the preview and look at it before shipping.
