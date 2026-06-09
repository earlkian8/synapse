# 2026-06-10 — Custom sidebar scrollbar

The sidebar navigation grew long enough to overflow and show a scrollbar. The
browser default looked out of place, so it was restyled to match the ERP design.

## Change

`resources/css/app.css` — custom scrollbar scoped to `[data-slot='sidebar-content']`
(the sidebar nav only; tables, sheets and other scroll areas are untouched).

- **Slim:** 8px track with a 2px transparent border + `background-clip: padding-box`,
  giving a ~4px visible thumb.
- **Rounded & clean:** fully pill-shaped thumb over a transparent track.
- **Theme-aware:** thumb colour derives from `--sidebar-foreground` via `color-mix`,
  so it adapts automatically to light and dark mode.
- **Interactive:** brightens on sidebar hover; the thumb turns brand teal `#0abfbf`
  on direct hover.
- **Cross-browser:** `::-webkit-scrollbar` rules (Chrome/Edge/Safari) plus
  `scrollbar-width: thin` + `scrollbar-color` (Firefox).

## Notes

- WebKit renders this as a classic, space-occupying scrollbar; since the sidebar
  already overflows, the reserved width causes no layout shift.
- Verified with `npm run build` (CSS compiles).
