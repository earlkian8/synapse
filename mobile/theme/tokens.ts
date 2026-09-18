/**
 * SYNAPSE design tokens, in lock-step with the web ERP's `resources/css/app.css`.
 *
 * The ERP is a white product. Its signed-in shell is `--background: oklch(1 0 0)` with
 * a neutral (zero-chroma) grey ramp for ink and hairlines, a near-black `--primary` for
 * anything you press, and the brand teal held back for one job: showing which thing is
 * active. Navy is the *pre-app* field — the sign-in and workspace-picker backdrop — and
 * appears nowhere behind the app itself.
 *
 * This app used to invert that: navy slabs on Home and Awards, teal as the fill for
 * every primary control, and 500-level status colours set as text on white (~2.5:1).
 * It now follows the ERP. White is the surface; ink is the type and the primary action;
 * teal marks selection; colour appears only where it carries meaning.
 *
 * Every value below is checked against WCAG AA (4.5:1 for text, 3:1 for a shape that
 * carries meaning on its own) on the surface it is used on. Colours that arrive from
 * the server — leave types, award types — can't be checked ahead of time, so they go
 * through `readable()` from {@link useTheme} instead. See ./color.ts.
 */

/**
 * The ERP's neutral ramp, converted from the `oklch()` values in `app.css`. These are
 * the same greys the web app paints with, to the byte.
 */
const neutral = {
  0: '#FFFFFF', //   oklch(1     0 0)  page and card
  50: '#FAFAFA', //  oklch(0.985 0 0)  inverted ink
  100: '#F5F5F5', // oklch(0.97  0 0)  recessed surface
  150: '#EEEEEE', // oklch(0.95  0 0)  hairline inside a card
  200: '#E5E5E5', // oklch(0.922 0 0)  card edge
  400: '#A1A1A1', // oklch(0.708 0 0)  muted ink, dark scheme
  500: '#737373', // oklch(0.556 0 0)  faint ink, light scheme
  600: '#525252', // oklch(0.439 0 0)  muted ink, light scheme
  800: '#262626', // oklch(0.269 0 0)  card edge, dark scheme
  900: '#171717', // oklch(0.205 0 0)  primary; card, dark scheme
  950: '#0A0A0A', // oklch(0.145 0 0)  ink; page, dark scheme
} as const;

export const palette = {
  /** The pre-app field: sign-in, register, splash, workspace picker. Not used in-app. */
  navy: '#0F2044',
  navyDeep: '#0B1530',
  /** Brand teal. A fill and a marker — it is too light to carry text on white. */
  teal: '#0ABFBF',
  /** Teal deepened until a shape filled with it is visible on white (3:1) — the light
   *  scheme's fill, since the brand teal on white is a 2.3:1 edge nobody can find. */
  tealDeep: '#00A5A6',
  /** Teal darkened until it clears 4.5:1 on a white card *and* on its own 12% tint. */
  tealInk: '#007C7D',
  white: '#FFFFFF',
  neutral,
} as const;

/**
 * Status tones. These are fills and dots; nothing sets one as text directly — `Pill`
 * and the screens run them through `readable()` so the label darkens (light scheme) or
 * lifts (dark scheme) to stay legible on whatever it sits on.
 */
export const status = {
  present: '#10B981',
  late: '#F59E0B',
  absent: '#F43F5E',
  leave: '#6366F1',
  rest: '#94A3B8',
  holiday: '#0EA5E9',
  incomplete: '#F59E0B',
  undertime: '#F59E0B',
  /** A day the company's attendance policy judged a half day (ADR 0038). */
  halfDay: '#D946EF',
  overtime: '#0ABFBF',
} as const;

export type StatusKey = keyof typeof status;

export type ColorScheme = {
  /** Surfaces, lightest first. `background` and `card` match in the light scheme — the
   *  ERP separates a card from the page with its edge, not with a shade. */
  background: string;
  card: string;
  /** The recessed surface: segmented-control tracks, skeletons. Always *away* from `card`. */
  cardAlt: string;
  border: string;
  hairline: string;

  text: string;
  textMuted: string;
  textFaint: string;

  /** The thing you press. Near-black on white, inverting in the dark scheme — `--primary`. */
  primary: string;
  onPrimary: string;

  /** Teal, split by job. */
  accent: string; //     fills, edges and rings — always visible on the surface (3:1)
  accentSoft: string; // a tint to sit an icon or initials on
  accentText: string; // teal as type (4.5:1)
  onAccent: string; //   what goes on top of an `accent` fill

  danger: string;
  onDanger: string;

  overlay: string;
  shadow: string;
};

const light: ColorScheme = {
  background: neutral[0],
  card: neutral[0],
  cardAlt: neutral[100],
  border: neutral[200],
  hairline: neutral[150],

  text: neutral[950], //  19.8:1
  textMuted: neutral[600], // 7.8:1
  textFaint: neutral[500], //  4.7:1

  primary: neutral[900], // 17.9:1 on white
  onPrimary: neutral[50], // 17.2:1 on primary

  accent: palette.tealDeep, // 3.0:1 on white
  accentSoft: 'rgba(10, 191, 191, 0.12)',
  accentText: palette.tealInk, // 4.5:1 on white and on the 12% tint
  onAccent: palette.navy, // 5.3:1 on the fill — the ERP's own teal/navy pairing

  danger: '#E12950', // 4.5:1 on white, and carries white type at 4.5:1
  onDanger: neutral[0],

  overlay: 'rgba(10, 10, 10, 0.45)',
  shadow: neutral[950],
};

/**
 * The dark scheme is neutral too — the ERP's `.dark` block has zero chroma, so this is
 * not a navy app after dark either. `card` sits one rung above the page because a phone
 * has no hover state and RN shadows don't read on black; the edge alone isn't enough.
 */
const dark: ColorScheme = {
  background: neutral[950],
  card: neutral[900],
  cardAlt: neutral[950],
  border: neutral[800],
  hairline: '#212121',

  text: neutral[50], //   17.2:1
  textMuted: neutral[400], // 6.9:1
  textFaint: '#8C8C8C', //  5.3:1

  primary: neutral[50],
  onPrimary: neutral[900],

  accent: palette.teal, // 7.9:1 on the dark card — the brand teal needs no help here
  accentSoft: 'rgba(10, 191, 191, 0.2)',
  accentText: palette.teal, // 7.9:1
  onAccent: palette.navy, // 7.0:1

  danger: '#E12950',
  onDanger: neutral[0],

  overlay: 'rgba(0, 0, 0, 0.6)',
  shadow: '#000000',
};

export const schemes = { light, dark };

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const radius = {
  sm: 10,
  md: 14,
  lg: 18,
  xl: 24,
  pill: 999,
} as const;

export const typography = {
  display: { fontSize: 34, fontWeight: '800', letterSpacing: -0.5 },
  title: { fontSize: 24, fontWeight: '800', letterSpacing: -0.3 },
  heading: { fontSize: 18, fontWeight: '700' },
  body: { fontSize: 15, fontWeight: '500' },
  label: { fontSize: 13, fontWeight: '600' },
  caption: { fontSize: 12, fontWeight: '500' },
  overline: { fontSize: 11, fontWeight: '700', letterSpacing: 1, textTransform: 'uppercase' },
} as const;

export type TypographyVariant = keyof typeof typography;
