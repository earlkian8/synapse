import { BlurView } from 'expo-blur';
import { GlassView, isLiquidGlassAvailable } from 'expo-glass-effect';
import { Platform, View, type StyleProp, type ViewStyle } from 'react-native';

import { withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';

/** Liquid Glass ships with iOS 26. Asked once: the answer can't change while the app runs. */
const LIQUID_GLASS = Platform.OS === 'ios' && isLiquidGlassAvailable();

type MaterialProps = {
  children?: React.ReactNode;
  style?: StyleProp<ViewStyle>;
  /**
   * Floating chrome (the tab bar, a bar button) is Liquid Glass where the phone has it.
   * Edge-to-edge chrome (a navigation bar) stays a blur on every iOS, as Apple's do.
   */
  glass?: boolean;
  /** Override the fallback fill's opacity on Android and the web. */
  opacity?: number;
};

/**
 * A translucent surface that the content scrolls under: what iOS calls a material.
 *
 * - iOS 26: Liquid Glass, for floating chrome.
 * - Earlier iOS: the system's chrome blur, the same material its own bars use.
 * - Android and the web: the surface colour at 94%. Android's blur needs a capture
 *   target wrapped around every screen and costs a frame on mid-range phones, so a
 *   near-opaque fill is the honest equivalent there.
 */
export function Material({ children, style, glass, opacity = 0.94 }: MaterialProps) {
  const { scheme, colors } = useTheme();

  if (glass && LIQUID_GLASS) {
    return (
      <GlassView glassEffectStyle="regular" colorScheme={scheme} style={style}>
        {children}
      </GlassView>
    );
  }

  if (Platform.OS === 'ios') {
    return (
      <BlurView
        tint={scheme === 'dark' ? 'systemChromeMaterialDark' : 'systemChromeMaterialLight'}
        intensity={100}
        style={[{ overflow: 'hidden' }, style]}
      >
        {children}
      </BlurView>
    );
  }

  return (
    <View style={[{ backgroundColor: withAlpha(scheme === 'dark' ? colors.elevated : colors.card, opacity) }, style]}>
      {children}
    </View>
  );
}

/** Whether floating chrome is real glass, so it can drop the edge and shadow glass draws itself. */
export const hasLiquidGlass = LIQUID_GLASS;
