import { StyleSheet, Text, type TextProps, type TextStyle } from 'react-native';

import { useTheme } from '@/theme/theme';
import { fonts, type TypographyVariant } from '@/theme/tokens';

type Tone = 'primary' | 'secondary' | 'tertiary' | 'tint' | 'danger';
type Weight = keyof typeof fonts;

type AppTextProps = TextProps & {
  variant?: TypographyVariant;
  /** Which ink, from the theme. `color` wins over it when both are given. */
  tone?: Tone;
  color?: string;
  /** Overrides the variant's weight, e.g. a semibold footnote. */
  weight?: Weight;
  center?: boolean;
  /** Tabular figures, so a ticking clock or a column of numbers doesn't jitter. */
  numeric?: boolean;
};

/**
 * Android can't pick a weight out of a custom family, so a weight is a file. Any
 * `fontWeight` that reaches here (from a variant or a caller's style) becomes the
 * matching Inter face, and the `fontWeight` itself is dropped so iOS doesn't try to
 * synthesise a bolder version of an already-bold file.
 */
const FACE_FOR_WEIGHT: Record<string, string> = {
  '100': fonts.regular,
  '200': fonts.regular,
  '300': fonts.regular,
  '400': fonts.regular,
  normal: fonts.regular,
  '500': fonts.medium,
  '600': fonts.semibold,
  '700': fonts.bold,
  bold: fonts.bold,
  '800': fonts.bold,
  '900': fonts.bold,
};

/** The app's single text primitive: HIG type styles in Inter, with theme-aware ink. */
export function AppText({
  variant = 'body',
  tone = 'primary',
  color,
  weight,
  center,
  numeric,
  style,
  maxFontSizeMultiplier = 1.4,
  ...rest
}: AppTextProps) {
  const { colors, typography } = useTheme();

  const ink: Record<Tone, string> = {
    primary: colors.text,
    secondary: colors.textSecondary,
    tertiary: colors.textTertiary,
    tint: colors.tintText,
    danger: colors.danger,
  };

  const { fontWeight, ...flat } = (StyleSheet.flatten(style) ?? {}) as TextStyle;
  const face = weight ? fonts[weight] : fontWeight ? FACE_FOR_WEIGHT[String(fontWeight)] : undefined;

  return (
    <Text
      maxFontSizeMultiplier={maxFontSizeMultiplier}
      style={[
        typography[variant],
        { color: color ?? ink[tone] },
        center && styles.center,
        numeric && styles.numeric,
        flat,
        face ? { fontFamily: face } : null,
      ]}
      {...rest}
    />
  );
}

const styles = StyleSheet.create({
  center: { textAlign: 'center' },
  numeric: { fontVariant: ['tabular-nums'] },
});
