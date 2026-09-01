"""
svgkit - a tiny, dependency-free SVG layout kit for the SYNAPSE Chapter 3 figures.

Design goals:
  * Figma-importable: plain <rect>/<path>/<text>/<circle> with presentation
    attributes only. No <style> blocks, no CSS classes, no <marker>, no
    <foreignObject> - every arrowhead is an explicit filled polygon.
  * Print-safe: black outlines on white, greyscale fills only.
  * Declarative: figures are described as nodes plus edges; orthogonal routing
    and label placement are automatic.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from xml.sax.saxutils import escape

# -- Visual constants ---------------------------------------------------------

FONT = "Arial, Helvetica, sans-serif"
MONO = "Consolas, 'Courier New', monospace"

INK = "#111111"
MUTED = "#5A5A5A"
WHITE = "#FFFFFF"
FILL_LIGHT = "#F4F4F4"
FILL_MID = "#E4E4E4"
FILL_DARK = "#CFCFCF"

SW = 1.4
SW_THIN = 1.0
SW_THICK = 2.0
DASH = "6 4"
DASH_FINE = "3 3"

T_TITLE = 16
T_SUB = 11
T_NODE = 11
T_SMALL = 9.5
T_TINY = 8.5
LINE_H = 13


def esc(s):
    return escape(str(s))


# -- Primitives ---------------------------------------------------------------

def text(x, y, s, size=T_NODE, anchor="middle", weight="normal", fill=INK,
         font=FONT, italic=False, opacity=None):
    style = ' font-style="italic"' if italic else ""
    op = ' opacity="%s"' % opacity if opacity is not None else ""
    return ('<text x="%.1f" y="%.1f" font-family="%s" font-size="%s" '
            'font-weight="%s" fill="%s" text-anchor="%s"%s%s>%s</text>'
            % (x, y, font, size, weight, fill, anchor, style, op, esc(s)))


def text_block(cx, cy, lines, size=T_NODE, line_h=LINE_H, anchor="middle",
               weight="normal", fill=INK, font=FONT, italic=False):
    """Vertically centre a list of lines on cy. A line may be a plain string or
    a (string, size, weight, fill) tuple."""
    out = []
    n = len(lines)
    top = cy - (n - 1) * line_h / 2 + size * 0.35
    for i, ln in enumerate(lines):
        w, sz, f, it = weight, size, fill, italic
        if isinstance(ln, tuple):
            vals = list(ln) + [size, weight, fill]
            ln, sz, w, f = vals[0], vals[1], vals[2], vals[3]
        out.append(text(cx, top + i * line_h, ln, sz, anchor, w, f, font, it))
    return "".join(out)


def rect(x, y, w, h, rx=3, fill=WHITE, stroke=INK, sw=SW, dash=None, opacity=None):
    d = ' stroke-dasharray="%s"' % dash if dash else ""
    op = ' opacity="%s"' % opacity if opacity is not None else ""
    return ('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="%s" '
            'fill="%s" stroke="%s" stroke-width="%s"%s%s/>'
            % (x, y, w, h, rx, fill, stroke, sw, d, op))


def line(x1, y1, x2, y2, stroke=INK, sw=SW, dash=None):
    d = ' stroke-dasharray="%s"' % dash if dash else ""
    return ('<path d="M %.1f %.1f L %.1f %.1f" fill="none" stroke="%s" '
            'stroke-width="%s"%s/>' % (x1, y1, x2, y2, stroke, sw, d))


def polygon(points, fill=INK, stroke="none", sw=SW, dash=None):
    pts = " ".join("%.1f,%.1f" % (px, py) for px, py in points)
    d = ' stroke-dasharray="%s"' % dash if dash else ""
    return ('<polygon points="%s" fill="%s" stroke="%s" stroke-width="%s"%s/>'
            % (pts, fill, stroke, sw, d))


def circle(cx, cy, r, fill=WHITE, stroke=INK, sw=SW, dash=None):
    d = ' stroke-dasharray="%s"' % dash if dash else ""
    return ('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s" stroke="%s" '
            'stroke-width="%s"%s/>' % (cx, cy, r, fill, stroke, sw, d))


def path(d, fill="none", stroke=INK, sw=SW, dash=None):
    ds = ' stroke-dasharray="%s"' % dash if dash else ""
    return ('<path d="%s" fill="%s" stroke="%s" stroke-width="%s"%s/>'
            % (d, fill, stroke, sw, ds))


def arrowhead(x, y, dx, dy, size=9, fill=INK):
    """Filled triangle at (x,y) pointing along the vector (dx,dy)."""
    mag = (dx * dx + dy * dy) ** 0.5 or 1.0
    ux, uy = dx / mag, dy / mag
    px, py = -uy, ux
    b = size * 0.6
    return polygon([
        (x, y),
        (x - ux * size + px * b, y - uy * size + py * b),
        (x - ux * size - px * b, y - uy * size - py * b),
    ], fill=fill)


def halo_text(x, y, s, size=T_SMALL, anchor="middle", fill=INK, pad=3):
    """A label with a white plate behind it so it stays legible over a line."""
    w = len(str(s)) * size * 0.53 + pad * 2
    h = size + pad * 1.4
    if anchor == "middle":
        rx = x - w / 2
    elif anchor == "start":
        rx = x - pad
    else:
        rx = x - w + pad
    return (rect(rx, y - h * 0.72, w, h, 2, WHITE, "none", 0)
            + text(x, y, s, size, anchor, fill=fill))


# -- Node model ---------------------------------------------------------------

@dataclass
class Node:
    key: str
    x: float
    y: float
    w: float
    h: float
    kind: str = "box"
    lines: list = field(default_factory=list)
    fill: str = WHITE
    dash: object = None
    tag: object = None
    size: float = T_NODE
    sw: float = SW

    @property
    def cx(self):
        return self.x + self.w / 2

    @property
    def cy(self):
        return self.y + self.h / 2

    def port(self, side):
        return {
            "n": (self.cx, self.y),
            "s": (self.cx, self.y + self.h),
            "w": (self.x, self.cy),
            "e": (self.x + self.w, self.cy),
        }[side]


class Fig:
    """A figure canvas: nodes plus auto-routed orthogonal edges."""

    def __init__(self, w, h, title=None, subtitle=None):
        self.w, self.h = w, h
        self.title = title
        self.subtitle = subtitle
        self.body_back = []
        self.body = []
        self.body_front = []
        self.nodes = {}

    def add(self, s):
        self.body.append(s)
        return self

    def back(self, s):
        self.body_back.append(s)
        return self

    def front(self, s):
        self.body_front.append(s)
        return self

    def node(self, key, x, y, w, h, kind="box", lines=None, fill=WHITE,
             dash=None, tag=None, size=T_NODE, sw=SW):
        n = Node(key, x, y, w, h, kind, list(lines or []), fill, dash, tag, size, sw)
        self.nodes[key] = n
        return n

    def n(self, key):
        return self.nodes[key]

    def render_nodes(self):
        return "".join(self._draw(n) for n in self.nodes.values())

    def _draw(self, n):
        s = []
        k = n.kind
        if k == "box":
            s.append(rect(n.x, n.y, n.w, n.h, 3, n.fill, INK, n.sw, n.dash))
            s.append(text_block(n.cx, n.cy, n.lines, n.size))
        elif k == "round":
            s.append(rect(n.x, n.y, n.w, n.h, min(12, n.h / 2), n.fill, INK, n.sw, n.dash))
            s.append(text_block(n.cx, n.cy, n.lines, n.size))
        elif k == "stadium":
            s.append(rect(n.x, n.y, n.w, n.h, n.h / 2, n.fill, INK, n.sw, n.dash))
            s.append(text_block(n.cx, n.cy, n.lines, n.size))
        elif k == "proc":
            s.append(rect(n.x, n.y, n.w, n.h, 8, n.fill, INK, n.sw, n.dash))
            s.append(line(n.x, n.y + 18, n.x + n.w, n.y + 18, INK, SW_THIN))
            s.append(text(n.x + n.w / 2, n.y + 13.5, n.tag or "", T_SMALL, "middle", "bold"))
            s.append(text_block(n.cx, n.y + 18 + (n.h - 18) / 2, n.lines, n.size))
        elif k == "store":
            s.append(rect(n.x, n.y, n.w, n.h, 0, n.fill, INK, n.sw, n.dash))
            s.append(line(n.x + 26, n.y, n.x + 26, n.y + n.h, INK, SW_THIN))
            s.append(text(n.x + 13, n.cy + 3.5, n.tag or "D", T_SMALL, "middle", "bold"))
            s.append(text_block(n.x + 26 + (n.w - 26) / 2, n.cy, n.lines, n.size))
        elif k == "entity":
            s.append(rect(n.x, n.y, n.w, n.h, 0, n.fill, INK, SW_THICK, n.dash))
            s.append(text_block(n.cx, n.cy, n.lines, n.size))
        elif k == "diamond":
            cx, cy = n.cx, n.cy
            s.append(polygon([(cx, n.y), (n.x + n.w, cy), (cx, n.y + n.h), (n.x, cy)],
                             n.fill, INK, n.sw))
            s.append(text_block(cx, cy, n.lines, n.size))
        elif k == "doc":
            r = 12
            s.append(path("M %.1f %.1f H %.1f V %.1f Q %.1f %.1f %.1f %.1f "
                          "Q %.1f %.1f %.1f %.1f Z"
                          % (n.x, n.y, n.x + n.w, n.y + n.h - r,
                             n.x + n.w * 0.75, n.y + n.h - r * 2.2, n.cx, n.y + n.h - r,
                             n.x + n.w * 0.25, n.y + n.h, n.x, n.y + n.h - r),
                          n.fill, INK, n.sw, n.dash))
            s.append(text_block(n.cx, n.cy - 4, n.lines, n.size))
        elif k == "cyl":
            ry = 9
            s.append(path("M %.1f %.1f V %.1f A %.1f %.1f 0 0 0 %.1f %.1f V %.1f "
                          "A %.1f %.1f 0 0 0 %.1f %.1f Z"
                          % (n.x, n.y + ry, n.y + n.h - ry, n.w / 2, ry,
                             n.x + n.w, n.y + n.h - ry, n.y + ry, n.w / 2, ry,
                             n.x, n.y + ry), n.fill, INK, n.sw))
            s.append(path("M %.1f %.1f A %.1f %.1f 0 0 0 %.1f %.1f"
                          % (n.x, n.y + ry, n.w / 2, ry, n.x + n.w, n.y + ry),
                          "none", INK, n.sw))
            s.append(text_block(n.cx, n.cy + ry / 2, n.lines, n.size))
        elif k == "circle":
            s.append(circle(n.cx, n.cy, min(n.w, n.h) / 2, n.fill, INK, n.sw, n.dash))
            s.append(text_block(n.cx, n.cy, n.lines, n.size))
        elif k == "note":
            f = 12
            s.append(path("M %.1f %.1f H %.1f L %.1f %.1f V %.1f H %.1f Z"
                          % (n.x, n.y, n.x + n.w - f, n.x + n.w, n.y + f,
                             n.y + n.h, n.x), n.fill, INK, SW_THIN, n.dash))
            s.append(path("M %.1f %.1f V %.1f H %.1f"
                          % (n.x + n.w - f, n.y, n.y + f, n.x + n.w),
                          "none", INK, SW_THIN))
            s.append(text_block(n.cx, n.cy, n.lines, n.size))
        elif k == "band":
            s.append(rect(n.x, n.y, n.w, n.h, 4, n.fill, INK, n.sw, n.dash))
            if n.lines:
                s.append(text(n.x + 10, n.y + 16, n.lines[0], T_SMALL, "start",
                              "bold", MUTED))
        return "".join(s)

    def edge(self, a, b, label=None, dashed=False, both=False, route="auto",
             sw=SW, sides=None, offset=0, label_at=0.5, label_dx=0, label_dy=-6,
             stroke=INK, head=True, via=None):
        na = self.nodes[a] if isinstance(a, str) else a
        nb = self.nodes[b] if isinstance(b, str) else b
        pts = self._route(na, nb, route, sides, offset, via)
        d = "M " + " L ".join("%.1f %.1f" % (px, py) for px, py in pts)
        self.body.append(path(d, "none", stroke, sw, DASH if dashed else None))
        if head:
            x2, y2 = pts[-1]
            x1, y1 = pts[-2]
            self.body.append(arrowhead(x2, y2, x2 - x1, y2 - y1, fill=stroke))
        if both:
            x1, y1 = pts[0]
            x2, y2 = pts[1]
            self.body.append(arrowhead(x1, y1, x1 - x2, y1 - y2, fill=stroke))
        if label:
            lx, ly = self._label_point(pts, label_at)
            labels = label if isinstance(label, (list, tuple)) else [label]
            for i, lb in enumerate(labels):
                self.body_front.append(
                    halo_text(lx + label_dx, ly + label_dy + i * 11, lb, T_SMALL))
        return self

    def _route(self, a, b, route, sides, offset, via=None):
        if sides:
            sa, sb = sides
        else:
            dx, dy = b.cx - a.cx, b.cy - a.cy
            if abs(dx) > abs(dy) * 1.6:
                sa, sb = ("e", "w") if dx > 0 else ("w", "e")
            elif abs(dy) > abs(dx) * 1.6:
                sa, sb = ("s", "n") if dy > 0 else ("n", "s")
            elif route == "vh":
                sa = "s" if dy > 0 else "n"
                sb = "w" if dx > 0 else "e"
            else:
                sa = "e" if dx > 0 else "w"
                sb = "n" if dy > 0 else "s"
        p1 = list(a.port(sa))
        p2 = list(b.port(sb))
        if offset:
            if sa in "ns":
                p1[0] += offset
            else:
                p1[1] += offset
            if sb in "ns":
                p2[0] += offset
            else:
                p2[1] += offset
        p1, p2 = tuple(p1), tuple(p2)
        if via is not None:
            if sa in "ns":
                return [p1, (p1[0], via), (p2[0], via), p2] if sb in "ns" \
                    else [p1, (p1[0], via), (p2[0], via), p2]
            return [p1, (via, p1[1]), (via, p2[1]), p2]
        if sa in "ns" and sb in "ns":
            if abs(p1[0] - p2[0]) < 0.5:
                return [p1, p2]
            my = (p1[1] + p2[1]) / 2
            return [p1, (p1[0], my), (p2[0], my), p2]
        if sa in "ew" and sb in "ew":
            if abs(p1[1] - p2[1]) < 0.5:
                return [p1, p2]
            mx = (p1[0] + p2[0]) / 2
            return [p1, (mx, p1[1]), (mx, p2[1]), p2]
        if sa in "ew":
            return [p1, (p2[0], p1[1]), p2]
        return [p1, (p1[0], p2[1]), p2]

    @staticmethod
    def _label_point(pts, t):
        segs, total = [], 0.0
        for i in range(len(pts) - 1):
            (x1, y1), (x2, y2) = pts[i], pts[i + 1]
            L = ((x2 - x1) ** 2 + (y2 - y1) ** 2) ** 0.5
            segs.append((L, x1, y1, x2, y2))
            total += L
        target, run = total * t, 0.0
        for L, x1, y1, x2, y2 in segs:
            if run + L >= target:
                f = (target - run) / L if L else 0
                return x1 + (x2 - x1) * f, y1 + (y2 - y1) * f
            run += L
        return pts[-1]

    def _autofit(self, content, pad=28):
        """Grow the canvas so nothing drawn falls outside the viewBox."""
        import re
        mx, my = 0.0, 0.0
        for m in re.finditer(r'<rect x="(-?[\d.]+)" y="(-?[\d.]+)" width="([\d.]+)" '
                             r'height="([\d.]+)"', content):
            x, y, w, h = (float(v) for v in m.groups())
            mx, my = max(mx, x + w), max(my, y + h)
        for m in re.finditer(r'<text x="(-?[\d.]+)" y="(-?[\d.]+)"', content):
            x, y = (float(v) for v in m.groups())
            mx, my = max(mx, x + 4), max(my, y + 6)
        for m in re.finditer(r'<circle cx="(-?[\d.]+)" cy="(-?[\d.]+)" r="([\d.]+)"',
                             content):
            x, y, r = (float(v) for v in m.groups())
            mx, my = max(mx, x + r), max(my, y + r)
        for m in re.finditer(r'[\d.\-]+ [\d.\-]+', content):
            pass
        for m in re.finditer(r'(?:M|L) (-?[\d.]+) (-?[\d.]+)', content):
            x, y = (float(v) for v in m.groups())
            mx, my = max(mx, x), max(my, y)
        for m in re.finditer(r'points="([^"]+)"', content):
            for p in m.group(1).split():
                x, y = (float(v) for v in p.split(","))
                mx, my = max(mx, x), max(my, y)
        self.w = int(max(self.w, mx + pad))
        self.h = int(max(self.h, my + pad))

    def svg(self):
        content = ("".join(self.body_back) + self.render_nodes()
                   + "".join(self.body) + "".join(self.body_front))
        self._autofit(content)
        head = ['<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" '
                'viewBox="0 0 %d %d">' % (self.w, self.h, self.w, self.h),
                rect(0, 0, self.w, self.h, 0, WHITE, "none", 0)]
        if self.title:
            head.append(text(self.w / 2, 28, self.title, T_TITLE, "middle", "bold"))
            if self.subtitle:
                head.append(text(self.w / 2, 46, self.subtitle, T_SUB, "middle",
                                 fill=MUTED))
        return "".join(head) + content + "</svg>"


def legend(fig, x, y, items, gap=17, box_w=22, title=None):
    """items: list of (kind, label). kind in
    box|round|store|entity|diamond|dashed|arrow|dashed-arrow|cyl"""
    out = []
    if title:
        out.append(text(x, y - 12, title, T_SMALL, "start", "bold"))
    for i, (kind, label) in enumerate(items):
        yy = y + i * gap
        if kind == "arrow":
            out.append(line(x, yy, x + box_w, yy, INK, SW))
            out.append(arrowhead(x + box_w, yy, 1, 0))
        elif kind == "dashed-arrow":
            out.append(line(x, yy, x + box_w, yy, INK, SW, DASH))
            out.append(arrowhead(x + box_w, yy, 1, 0))
        elif kind == "store":
            out.append(rect(x, yy - 6, box_w, 12, 0, WHITE, INK, SW_THIN))
            out.append(line(x + 7, yy - 6, x + 7, yy + 6, INK, SW_THIN))
        elif kind == "entity":
            out.append(rect(x, yy - 6, box_w, 12, 0, WHITE, INK, SW_THICK))
        elif kind == "round":
            out.append(rect(x, yy - 6, box_w, 12, 6, WHITE, INK, SW_THIN))
        elif kind == "diamond":
            out.append(polygon([(x + box_w / 2, yy - 7), (x + box_w, yy),
                                (x + box_w / 2, yy + 7), (x, yy)], WHITE, INK, SW_THIN))
        elif kind == "dashed":
            out.append(rect(x, yy - 6, box_w, 12, 2, WHITE, INK, SW_THIN, DASH_FINE))
        elif kind == "cyl":
            out.append(rect(x, yy - 6, box_w, 12, 5, FILL_LIGHT, INK, SW_THIN))
        else:
            out.append(rect(x, yy - 6, box_w, 12, 2, WHITE, INK, SW_THIN))
        out.append(text(x + box_w + 8, yy + 3.5, label, T_SMALL, "start", fill=MUTED))
    fig.front("".join(out))
    return fig


def flowchain(fig, cx, y0, steps, w=250, gap=26, dh=64):
    """Stack a vertical chain of flowchart nodes centred on cx.
    steps: list of (key, kind, lines[, width]). Returns the y after the chain."""
    y = y0
    prev = None
    for step in steps:
        key, kind, lines = step[0], step[1], step[2]
        ww = step[3] if len(step) > 3 else w
        h = dh if kind == "diamond" else max(34, 18 + LINE_H * len(lines))
        if kind == "stadium":
            h = 34
        n = fig.node(key, cx - ww / 2, y, ww, h, kind, lines)
        if prev:
            fig.edge(prev.key, key, sides=("s", "n"))
        prev = n
        y += h + gap
    return y - gap
