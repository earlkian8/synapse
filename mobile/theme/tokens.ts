/**
 * SYNAPSE design tokens: the brand's colours, laid out the way iOS lays out an app.
 *
 * The brand is the ERP's: navy `#0F2044` and teal `#0ABFBF`. The *structure* is Apple's
 * Human Interface Guidelines. Content sits on a grouped background (`#F2F2F7` by day,
 * true black at night), in white cards with continuous corners and no edge. Lists are
 * inset and grouped, hairlines are the platform's own, and type follows the HIG scale,
 * set in Inter (see `typography` below). A web app's habits make a phone app feel like
 * a web page: white-on-white cards held apart by grey borders, heavy 800 headings,
 * uppercase labels over everything. Those habits are gone.
 *
 * Colour is spent where it carries meaning:
 * - **Navy** is the brand's weight. It fills the one hero surface on a screen (today's
 *   card on Home, the clock face) and the main action on the entry screens.
 * - **Teal** is the tint, the iOS idea of one interactive colour. Links, selection,
 *   switches and progress are teal, and the punch button is filled with it.
 * - **Status tones** are Apple's system colours, so a late day is the same orange the
 *   phone uses everywhere else.
 *
 * Every *reading* colour clears WCAG AA (4.5:1) on both the page and the card it sits
 * on. `textTertiary` is the exception by design: it is for glyphs, placeholders and
 * disabled labels, the job iOS gives tertiaryLabel, and it clears the 3:1 that
 * non-text contrast asks for. Colours that arrive from the server (leave types,
 * award types) can't be checked ahead of time, so they go through `readable()` from
 * {@link useTheme} instead. See ./color.ts.
 */

export const palette = {
  /** The brand navy (16:1 against white). */
  navy: '#0F2044',
  /** The navy hero's lighter corner, for its gradient. */
  navyLift: '#1B3770',
  /** Brand teal. A fill and a marker: too light to carry text on white. */
  teal: '#0ABFBF',
  /** Teal darkened until a shape filled with it shows on the grouped page (3:1). */
  tealDeep: '#009C9D',
  /** Teal darkened until it clears 4.5:1 as type on the page and on a card. */
  tealInk: '#007C7D',
  white: '#FFFFFF',
} as const;

/**
 * Status tones: Apple's system colours. These are fills and dots, and nothing sets one
 * as text directly. `Pill` and the screens run them through `readable()`, so a label
 * darkens (light scheme) or lifts (dark scheme) until it is legible on its surface.
 */
export const status = {
  present: '#34C759',
  late: '#FF9500',
  absent: '#FF3B30',
  leave: '#5856D6',
  rest: '#8E8E93',
  holiday: '#32ADE6',
  incomplete: '#FF9500',
  undertime: '#FF9500',
  /** A day the company's attendance policy judged a half day (ADR 0038). */
  halfDay: '#AF52DE',
  overtime: '#0ABFBF',
} as const;

export type StatusKey = keyof typeof status;

export type ColorScheme = {
  /** The grouped page: iOS's systemGroupedBackground. */
  background: string;
  /** A card or list on that page: secondarySystemGroupedBackground. */
  card: string;
  /** A surface raised above a card: sheets, the tab bar's fallback, popovers. */
  elevated: string;
  /** A translucent fill: segmented-control tracks, text fields, icon wells, skeletons. */
  fill: string;
  /** A stronger fill, for a pressed row or a selected well. */
  fillStrong: string;
  /** The platform hairline between rows and above bars. */
  separator: string;

  text: string;
  /** Secondary reading text: subtitles, values, captions (4.5:1+). */
  textSecondary: string;
  /** Glyphs, placeholders, chevrons and disabled labels only (3:1+), never reading text. */
  textTertiary: string;

  /** The interactive colour: links, selection, switches, focus (teal). */
  tint: string;
  /** The tint as type (4.5:1 on page and card). */
  tintText: string;
  /** A wash of the tint to sit an icon or initials on. */
  tintSoft: string;
  /** What goes on top of a `tint` fill. */
  onTint: string;

  /** The main action: navy by day, teal at night, where navy on black disappears. */
  primary: string;
  onPrimary: string;

  /** The brand navy as type: the wordmark and the entry screens' links. */
  brandText: string;

  /** The hero surface's gradient, its type, and its secondary type. */
  hero: readonly [string, string];
  onHero: string;
  onHeroSecondary: string;

  danger: string;
  onDanger: string;

  overlay: string;
  shadow: string;
};

const light: ColorScheme = {
  background: '#F2F2F7',
  card: '#FFFFFF',
  elevated: '#FFFFFF',
  fill: 'rgba(118, 118, 128, 0.12)',
  fillStrong: 'rgba(118, 118, 128, 0.2)',
  separator: '#C6C6C8',

  text: '#0B0B0F', //          19.6:1 on the card
  textSecondary: '#6C6C70', // 5.2:1 on the card, 4.7:1 on the page
  textTertiary: '#8A8A8E', //  3.4:1 on the card, 3.1:1 on the page

  tint: palette.tealDeep, //       3.0:1 as a shape on the page, 3.4:1 on a card
  tintText: palette.tealInk, //    5.0:1 on the card, 4.5:1 on the page
  tintSoft: 'rgba(10, 191, 191, 0.14)',
  onTint: palette.navy, //         7.0:1 on the brand teal

  primary: palette.navy, //        16:1 on the card
  onPrimary: palette.white,

  brandText: palette.navy,

  hero: [palette.navyLift, palette.navy],
  onHero: palette.white,
  onHeroSecondary: 'rgba(255, 255, 255, 0.72)', // 7.2:1 even at the gradient's light end

  danger: '#D70015', // 5.4:1 on the card, 4.8:1 on the page; white type on it at 5.4:1
  onDanger: palette.white,

  overlay: 'rgba(0, 0, 0, 0.32)',
  shadow: '#000000',
};

/**
 * The dark scheme is iOS's: a true-black page (which an OLED panel switches off),
 * cards one step up at `#1C1C1E`, and sheets a step above that. Navy can't hold the
 * main action on black, so teal takes it, with the brand's own navy type on top.
 */
const dark: ColorScheme = {
  background: '#000000',
  card: '#1C1C1E',
  elevated: '#2C2C2E',
  fill: 'rgba(118, 118, 128, 0.24)',
  fillStrong: 'rgba(118, 118, 128, 0.36)',
  separator: '#38383A',

  text: '#FFFFFF', //          17.0:1 on the card
  textSecondary: '#98989F', // 5.9:1 on the card
  textTertiary: '#6E6E73', //  3.4:1 on the card

  tint: palette.teal, //       7.5:1 on the card
  tintText: palette.teal,
  tintSoft: 'rgba(10, 191, 191, 0.2)',
  onTint: palette.navy,

  primary: palette.teal,
  onPrimary: palette.navy, //  7.0:1

  brandText: '#A8B9E6', //     8.7:1 on the card

  hero: ['#1E3B78', '#0F2044'],
  onHero: palette.white,
  onHeroSecondary: 'rgba(255, 255, 255, 0.7)',

  danger: '#FF453A', // 5.0:1 on the card
  onDanger: palette.white,

  overlay: 'rgba(0, 0, 0, 0.5)',
  shadow: '#000000',
};

export const schemes = { light, dark };

/** A 4-point grid. `gutter` is the page's side margin, as on every iOS screen. */
export const spacing = {
  xxs: 2,
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 20,
  xxl: 24,
  xxxl: 32,
  gutter: 16,
} as const;

/** Corner radii, drawn with continuous (squircle) curves on iOS. See `squircle`. */
export const radius = {
  xs: 8,
  sm: 10,
  md: 14,
  lg: 20,
  xl: 28,
  pill: 999,
} as const;

/** Apple's continuous corner curve. The difference between a web box and an iOS card. */
export const squircle = { borderCurve: 'continuous' } as const;

/**
 * Inter, one file per weight. The family name *is* the weight: Android cannot select
 * a weight inside a custom family, so `AppText` maps any `fontWeight` to its file.
 */
export const fonts = {
  regular: 'Inter_400Regular',
  medium: 'Inter_500Medium',
  semibold: 'Inter_600SemiBold',
  bold: 'Inter_700Bold',
} as const;

/**
 * Inter's own tracking curve (rsms.me/inter/dynmetrics): tighter as type grows, so a
 * 32pt title sets as snugly as SF Pro Display does and 11pt captions stay open.
 */
const track = (size: number): number =>
  Math.round(size * (-0.0223 + 0.185 * Math.exp(-0.1745 * size)) * 100) / 100;

const type = (fontSize: number, lineHeight: number, fontFamily: string) => ({
  fontSize,
  lineHeight,
  fontFamily,
  letterSpacing: track(fontSize),
});

/**
 * The HIG type scale. Inter has a taller x-height than SF Pro, so each style sits a
 * point under Apple's size to land at the same optical size.
 */
export const typography = {
  largeTitle: type(32, 38, fonts.bold),
  title1: type(26, 32, fonts.bold),
  title2: type(21, 26, fonts.bold),
  title3: type(19, 24, fonts.semibold),
  headline: type(16, 21, fonts.semibold),
  body: type(16, 22, fonts.regular),
  callout: type(15, 20, fonts.regular),
  subheadline: type(14, 19, fonts.regular),
  footnote: type(13, 18, fonts.regular),
  caption: type(12, 16, fonts.regular),
  caption2: type(11, 13, fonts.medium),
} as const;

export type TypographyVariant = keyof typeof typography;
