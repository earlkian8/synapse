import { Text, type TextProps, type TextStyle } from 'react-native';

import { useTheme } from '@/theme/theme';
import type { TypographyVariant } from '@/theme/tokens';

type AppTextProps = TextProps & {
  variant?: TypographyVariant;
  color?: string;
  muted?: boolean;
  faint?: boolean;
  center?: boolean;
};

/** The app's single text primitive — typography variants + theme-aware colour. */
export function AppText({
  variant = 'body',
  color,
  muted,
  faint,
  center,
  style,
  ...rest
}: AppTextProps) {
  const { colors, typography } = useTheme();

  const resolved = color ?? (faint ? colors.textFaint : muted ? colors.textMuted : colors.text);

  return (
    <Text
      style={[
        typography[variant] as TextStyle,
        { color: resolved },
        center && { textAlign: 'center' },
        style,
      ]}
      {...rest}
    />
  );
}
