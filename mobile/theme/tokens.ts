/**
 * SYNAPSE design tokens — kept in lock-step with the web app's brand so the two
 * read as one product: deep navy + teal, soft rounded cards, and a shared set of
 * status colours used everywhere a state is shown (attendance, leave, awards).
 */

export const palette = {
  navy: '#0F2044',
  navyDeep: '#0B1530',
  teal: '#0ABFBF',
  tealPressed: '#09AEAE',
  tealSoft: 'rgba(10,191,191,0.12)',
  white: '#FFFFFF',
} as const;

/** Status colours, shared with the web app. */
export const status = {
  present: '#10B981',
  late: '#F59E0B',
  absent: '#F43F5E',
  leave: '#6366F1',
  rest: '#94A3B8',
  holiday: '#0EA5E9',
  incomplete: '#F59E0B',
  undertime: '#F59E0B',
  overtime: '#0ABFBF',
} as const;

export type StatusKey = keyof typeof status;

export type ColorScheme = {
  brand: string;
  brandText: string;
  accent: string;
  accentPressed: string;
  accentSoft: string;
  background: string;
  card: string;
  cardAlt: string;
  border: string;
  hairline: string;
  text: string;
  textMuted: string;
  textFaint: string;
  onAccent: string;
  overlay: string;
  shadow: string;
};

const light: ColorScheme = {
  brand: palette.navy,
  brandText: palette.white,
  accent: palette.teal,
  accentPressed: palette.tealPressed,
  accentSoft: palette.tealSoft,

  background: '#F7F8FA',
  card: '#FFFFFF',
  cardAlt: '#F1F5F9',
  border: '#E2E8F0',
  hairline: '#EEF2F6',

  text: '#0F172A',
  textMuted: '#64748B',
  textFaint: '#94A3B8',
  onAccent: '#04363B',

  overlay: 'rgba(15,32,68,0.45)',
  shadow: '#0F2044',
};

const dark: ColorScheme = {
  brand: '#13284F',
  brandText: '#F8FAFC',
  accent: palette.teal,
  accentPressed: palette.tealPressed,
  accentSoft: 'rgba(10,191,191,0.16)',

  background: '#0B1530',
  card: '#13213F',
  cardAlt: '#1B2C4F',
  border: '#22345C',
  hairline: '#1B2A4A',

  text: '#F1F5F9',
  textMuted: '#94A3B8',
  textFaint: '#64748B',
  onAccent: '#04363B',

  overlay: 'rgba(2,8,20,0.6)',
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
