import { StatusBar } from 'expo-status-bar';
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { KeyboardAwareScrollView } from 'react-native-keyboard-controller';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Logo } from '@/components/ui/logo';
import { AppText } from '@/components/ui/text';
import { FixedScheme, useTheme } from '@/theme/theme';
import { schemes } from '@/theme/tokens';

/**
 * The colours of the entry screens — always the light scheme, because the ground
 * always is. For the screen that renders {@link EntryScreen} itself, and so sits
 * *outside* its FixedScheme when it reads the theme.
 */
export const entryColors = schemes.light;

type EntryScreenProps = {
  children: React.ReactNode;
  /** A keyboard-aware scroll view (the default), or a still ground for the splash. */
  scroll?: boolean;
  /** The grouped grey ground, for a screen that is a list of cards (the workspace picker). */
  grouped?: boolean;
  contentStyle?: StyleProp<ViewStyle>;
};

/**
 * The ground of the entry screens — the cold-start splash, sign-in, register and the
 * workspace picker. Plain white, like Apple's own sign-in sheets, with the brand navy
 * second: the wordmark and the main action.
 *
 * White whatever the phone's appearance setting says: these screens are the brand's
 * first word. Everything inside resolves against the light scheme, so a field or a
 * label on a phone set to dark is still ink on white, and the status bar keeps dark
 * icons.
 *
 * The forms here scroll with the keyboard: a focused field is lifted clear of it as
 * the keyboard rises, in step with it, and a drag down the page pulls it away.
 */
export function EntryScreen({ children, scroll = true, grouped, contentStyle }: EntryScreenProps) {
  return (
    <FixedScheme scheme="light">
      <Ground scroll={scroll} grouped={grouped} contentStyle={contentStyle}>
        {children}
      </Ground>
    </FixedScheme>
  );
}

function Ground({ children, scroll, grouped, contentStyle }: EntryScreenProps) {
  const { colors } = useTheme();
  const insets = useSafeAreaInsets();

  const padding = {
    paddingTop: insets.top + 24,
    paddingBottom: insets.bottom + 24,
    paddingHorizontal: 24,
  };

  return (
    <View style={[styles.fill, { backgroundColor: grouped ? colors.background : colors.card }]}>
      <StatusBar style="dark" />
      {scroll ? (
        <KeyboardAwareScrollView
          bottomOffset={28}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
          showsVerticalScrollIndicator={false}
          contentContainerStyle={[styles.grow, padding, contentStyle]}
        >
          {children}
        </KeyboardAwareScrollView>
      ) : (
        <View style={[styles.fill, padding, contentStyle]}>{children}</View>
      )}
    </View>
  );
}

type BrandLockupProps = {
  /** Width of the mark in points; the wordmark scales with it. */
  markWidth?: number;
  tagline?: string;
};

/**
 * The mark over the SYNAPSE wordmark, in navy. The mark is its original colourway —
 * navy figure, teal network — the one drawn for a light ground. The wordmark is
 * widely tracked, as a wordmark is, and set a weight lighter than a heading so it
 * reads as a name rather than a shout.
 */
export function BrandLockup({ markWidth = 152, tagline }: BrandLockupProps) {
  const { colors } = useTheme();
  const size = Math.round(markWidth * 0.16);

  return (
    <View style={styles.lockup} accessibilityRole="header" accessibilityLabel="SYNAPSE">
      <Logo width={markWidth} surface="light" />
      <AppText
        weight="semibold"
        color={colors.brandText}
        maxFontSizeMultiplier={1}
        style={{ fontSize: size, lineHeight: size * 1.2, letterSpacing: size * 0.28, marginTop: 12, marginRight: -size * 0.28 }}
      >
        SYNAPSE
      </AppText>
      {tagline && (
        <AppText variant="subheadline" tone="secondary" style={styles.tagline}>
          {tagline}
        </AppText>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  grow: { flexGrow: 1 },
  lockup: { alignItems: 'center' },
  tagline: { marginTop: 4 },
});
