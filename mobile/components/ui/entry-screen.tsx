import { StatusBar } from 'expo-status-bar';
import { View, type ViewStyle } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

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

/**
 * The ground of the entry screens — the cold-start splash, sign-in, register and the
 * workspace picker. White, like the ERP's own sign-in on a phone, with the brand navy
 * second: the wordmark and the main action.
 *
 * White whatever the phone's appearance setting says. These screens are the brand's
 * first word, and they used to be a navy field for the same reason; everything inside
 * resolves against the light scheme, so a field or a label on a phone set to dark is
 * still ink on white. The status bar follows: dark icons, because the ground is light
 * even when the rest of the app is not.
 */
export function EntryScreen({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  return (
    <FixedScheme scheme="light">
      <Ground style={style}>{children}</Ground>
    </FixedScheme>
  );
}

function Ground({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  const { colors } = useTheme();

  return (
    <View style={{ flex: 1, backgroundColor: colors.background }}>
      <StatusBar style="dark" />
      <SafeAreaView style={[{ flex: 1 }, style]}>{children}</SafeAreaView>
    </View>
  );
}

type BrandLockupProps = {
  /** Width of the mark in points; the wordmark keeps its own size. */
  markWidth?: number;
  tagline?: string;
};

/**
 * The mark over the SYNAPSE wordmark, in navy. The mark is its original colourway —
 * navy figure, teal network — the one drawn for a light ground.
 */
export function BrandLockup({ markWidth = 152, tagline }: BrandLockupProps) {
  const { colors } = useTheme();

  return (
    <View style={{ alignItems: 'center' }}>
      <Logo width={markWidth} surface="light" />
      <AppText
        variant="display"
        color={colors.secondaryText}
        style={{ letterSpacing: 2, marginTop: 14 }}
      >
        SYNAPSE
      </AppText>
      {tagline && (
        <AppText variant="body" muted style={{ marginTop: 4 }}>
          {tagline}
        </AppText>
      )}
    </View>
  );
}
