/**
 * Colour maths for the theme: sRGB ⇄ OKLCH, WCAG contrast, and the one helper the
 * rest of the app calls — {@link readableOn}, which walks a colour's lightness until
 * it can legibly carry text on a given surface.
 *
 * Why this exists: leave types, award types and organisations carry colours the HR
 * team picked in the ERP, so no fixed token table can cover them. Painted straight
 * onto a white card, a 500-level colour lands near 2.5:1 — legible to the person who
 * chose it on a desktop monitor, not to someone reading a phone outdoors. Everything
 * that renders one of those colours as text or as a meaning-carrying dot goes through
 * readableOn() first, which keeps the hue and gives up only as much lightness as the
 * contrast target demands.
 *
 * OKLCH (not HSL) because lightness there tracks what the eye reports, so darkening
 * amber and darkening indigo by the same amount look like the same move.
 */

/** sRGB transfer function and its inverse (IEC 61966-2-1). */
const encode = (x: number): number =>
  x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055;
const decode = (x: number): number =>
  x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);

type Rgb = [number, number, number];

/** '#RRGGBB' → 0-255 channels. Accepts the '#RGB' shorthand too. */
export function parseHex(hex: string): Rgb {
  const value = hex.trim().replace('#', '');
  const full =
    value.length === 3
      ? value
          .split('')
          .map((c) => c + c)
          .join('')
      : value;

  return [
    parseInt(full.slice(0, 2), 16),
    parseInt(full.slice(2, 4), 16),
    parseInt(full.slice(4, 6), 16),
  ];
}

const clamp01 = (x: number): number => Math.min(1, Math.max(0, x));

const channels = (rgb: Rgb): string =>
  '#' +
  rgb
    .map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase();

/** Linear-light channels → '#RRGGBB', gamma-encoding and clipping on the way. */
const toHex = (rgb: Rgb): string => channels(rgb.map((v) => clamp01(encode(v)) * 255) as Rgb);

/** OKLCH → linear sRGB (Björn Ottosson's matrices). May land outside [0,1]. */
function oklchToLinear(l: number, c: number, hDeg: number): Rgb {
  const h = (hDeg * Math.PI) / 180;
  const a = c * Math.cos(h);
  const b = c * Math.sin(h);

  const lms = [
    (l + 0.3963377774 * a + 0.2158037573 * b) ** 3,
    (l - 0.1055613458 * a - 0.0638541728 * b) ** 3,
    (l - 0.0894841775 * a - 1.291485548 * b) ** 3,
  ];

  return [
    4.0767416621 * lms[0] - 3.3077115913 * lms[1] + 0.2309699292 * lms[2],
    -1.2684380046 * lms[0] + 2.6097574011 * lms[1] - 0.3413193965 * lms[2],
    -0.0041960863 * lms[0] - 0.7034186147 * lms[1] + 1.707614701 * lms[2],
  ];
}

/** sRGB hex → OKLCH. */
function hexToOklch(hex: string): [number, number, number] {
  const [r, g, b] = parseHex(hex).map((v) => decode(v / 255));

  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  const lightness = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
  const a = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
  const b2 = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;

  let hue = (Math.atan2(b2, a) * 180) / Math.PI;
  if (hue < 0) hue += 360;

  return [lightness, Math.hypot(a, b2), hue];
}

const inGamut = (l: number, c: number, h: number): boolean =>
  oklchToLinear(l, c, h).every((v) => v >= -0.0005 && v <= 1.0005);

/** The most chroma this hue can hold at this lightness, never more than asked for. */
function fitChroma(l: number, c: number, h: number): number {
  if (inGamut(l, c, h)) {
    return c;
  }

  let low = 0;
  let high = c;

  for (let i = 0; i < 20; i++) {
    const mid = (low + high) / 2;
    if (inGamut(l, mid, h)) {
      low = mid;
    } else {
      high = mid;
    }
  }

  return low;
}

/** WCAG 2.1 relative luminance. */
function luminance(hex: string): number {
  const [r, g, b] = parseHex(hex).map((v) => decode(v / 255));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio, 1–21. */
export function contrastRatio(a: string, b: string): number {
  const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Flatten a translucent colour against an opaque one — what the eye actually sees, and
 * therefore what a tinted pill's label has to be measured against. Mixed in the encoded
 * space, which is what the renderer does when it composites the real view.
 */
export function composite(color: string, alpha: number, background: string): string {
  const fg = parseHex(color);
  const bg = parseHex(background);

  return channels([0, 1, 2].map((i) => fg[i] * alpha + bg[i] * (1 - alpha)) as Rgb);
}

/** Apply an alpha to a #RRGGBB (or rgb/rgba) colour string. */
export function withAlpha(color: string, alpha: number): string {
  if (color.startsWith('#') && (color.length === 7 || color.length === 4)) {
    const [r, g, b] = parseHex(color);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }

  return color;
}

/** Near-white and near-black, matching the neutral ramp's ends. */
const LIGHT_INK = '#FAFAFA';
const DARK_INK = '#0A0A0A';

/** Whichever end of the neutral ramp reads better on `background` — for the label on a
 *  fill whose colour isn't known until runtime. */
export function onColor(background: string): string {
  return contrastRatio(LIGHT_INK, background) >= contrastRatio(DARK_INK, background)
    ? LIGHT_INK
    : DARK_INK;
}

const cache = new Map<string, string>();

/**
 * The nearest version of `color` that clears `ratio` against `surface`, keeping its
 * hue. Darkens on light surfaces and lightens on dark ones; chroma is trimmed only
 * where sRGB can't hold it. Returns `color` unchanged when it already passes, so the
 * brand teal stays exactly itself on a dark card and only shifts on a white one.
 *
 * @param ratio 4.5 for text (WCAG AA), 3 for a shape that carries meaning on its own.
 */
export function readableOn(color: string, surface: string, ratio = 4.5): string {
  const key = `${color}|${surface}|${ratio}`;
  const hit = cache.get(key);

  if (hit !== undefined) {
    return hit;
  }

  const answer = solve(color, surface, ratio);
  cache.set(key, answer);

  return answer;
}

function solve(color: string, surface: string, ratio: number): string {
  if (!color.startsWith('#')) {
    return color;
  }

  if (contrastRatio(color, surface) >= ratio) {
    return color.toUpperCase();
  }

  const [lightness, chroma, hue] = hexToOklch(color);
  // Which way is there room to move? On a white card that's down; on a black one, up.
  const darken = contrastRatio('#000000', surface) > contrastRatio('#FFFFFF', surface);

  let low = darken ? 0 : lightness;
  let high = darken ? lightness : 1;

  for (let i = 0; i < 20; i++) {
    const mid = (low + high) / 2;
    const candidate = toHex(oklchToLinear(mid, fitChroma(mid, chroma, hue), hue));

    if (contrastRatio(candidate, surface) >= ratio) {
      if (darken) low = mid;
      else high = mid;
    } else {
      if (darken) high = mid;
      else low = mid;
    }
  }

  const settled = darken ? low : high;

  return toHex(oklchToLinear(settled, fitChroma(settled, chroma, hue), hue));
}
