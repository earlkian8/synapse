import { useRouter } from 'expo-router';
import { type ReactNode } from 'react';
import { Platform, RefreshControl, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import Animated, {
  Extrapolation,
  interpolate,
  useAnimatedStyle,
  useSharedValue,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon, type IconName } from '@/components/ui/icon';
import { KeyboardScroll } from '@/components/ui/keyboard-scroll';
import { hasLiquidGlass, Material } from '@/components/ui/material';
import { useTabBarInset } from '@/components/ui/tab-bar';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';

/** The navigation bar's own height, under the status bar. */
const BAR = 52;

type PageProps = {
  title: string;
  /** A small line over the large title: today's date on Home. */
  eyebrow?: string;
  /** A line under the large title. */
  subtitle?: string;
  /** Something beside the large title, such as the avatar on Home. */
  titleAccessory?: ReactNode;
  /**
   * The large title that collapses into the bar as you scroll (the default), or a
   * small centred title from the start, for modal forms and detail screens.
   */
  largeTitle?: boolean;
  /** A back button in the bar. */
  back?: boolean;
  /** Presented as an iOS sheet: the sheet already sits below the status bar. */
  modal?: boolean;
  left?: ReactNode;
  right?: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  /** Leave room for the floating tab bar under the last row. */
  tabInset?: boolean;
  /** Space between the page's top-level blocks. */
  gap?: number;
  contentStyle?: StyleProp<ViewStyle>;
  children: ReactNode;
};

/**
 * A screen, the iOS way.
 *
 * The content scrolls under a translucent navigation bar. At rest the bar is clear and
 * the page shows a large title; as the title scrolls up under the bar, the bar frosts
 * over, a hairline appears, and a small centred title fades in. Pulled down, the large
 * title grows a touch, as it does in Mail. All of it runs on the UI thread from one
 * scroll value.
 *
 * The scroll view is keyboard-aware (`KeyboardScroll`): when the keyboard rises, the
 * page makes room for it and lifts the focused field clear of it, on iOS and Android
 * alike. A drag down the page pulls the keyboard away with it.
 */
export function Page({
  title,
  eyebrow,
  subtitle,
  titleAccessory,
  largeTitle = true,
  back,
  modal,
  left,
  right,
  refreshing = false,
  onRefresh,
  tabInset,
  gap,
  contentStyle,
  children,
}: PageProps) {
  const { colors, spacing } = useTheme();
  const insets = useSafeAreaInsets();
  const tabBarInset = useTabBarInset();

  // An iOS page sheet starts below the status bar already; Android's modal is a full screen.
  const top = modal && Platform.OS === 'ios' ? 0 : insets.top;
  const headerHeight = top + BAR;
  const bottom = tabInset ? tabBarInset : insets.bottom + spacing.xxxl;
  const blockGap = gap ?? spacing.xxl;

  const scrollY = useSharedValue(0);
  const titleBottom = useSharedValue(48);

  // The bar frosts over as the large title slides under it (or as soon as anything does).
  const barStyle = useAnimatedStyle(() => {
    const y = scrollY.get();
    const start = largeTitle ? titleBottom.get() - 14 : 0;

    return { opacity: interpolate(y, [start, start + 14], [0, 1], Extrapolation.CLAMP) };
  });

  const compactTitleStyle = useAnimatedStyle(() => {
    if (!largeTitle) return { opacity: 1 };

    const y = scrollY.get();
    const start = titleBottom.get() - 10;

    return {
      opacity: interpolate(y, [start, start + 12], [0, 1], Extrapolation.CLAMP),
      transform: [{ translateY: interpolate(y, [start, start + 12], [5, 0], Extrapolation.CLAMP) }],
    };
  });

  // Pulled past the top, the large title swells slightly from its leading edge.
  const largeTitleStyle = useAnimatedStyle(() => {
    const y = scrollY.get();
    return { transform: [{ scale: y < 0 ? Math.min(1 + -y / 1000, 1.06) : 1 }] };
  });

  return (
    <View style={[styles.fill, { backgroundColor: colors.background }]}>
      <KeyboardScroll
        offset={scrollY}
        revealOffset={spacing.xxl}
        topInset={headerHeight}
        scrollIndicatorInsets={{ top: headerHeight - insets.top, bottom: tabInset ? tabBarInset - 24 : 0 }}
        contentContainerStyle={[
          {
            paddingTop: headerHeight + (largeTitle ? 2 : spacing.sm),
            paddingHorizontal: spacing.gutter,
            paddingBottom: bottom,
            gap: blockGap,
          },
          contentStyle,
        ]}
        refreshControl={
          onRefresh ? (
            <RefreshControl
              refreshing={refreshing}
              onRefresh={onRefresh}
              tintColor={colors.textSecondary}
              colors={[colors.tint]}
              progressBackgroundColor={colors.card}
              progressViewOffset={headerHeight}
            />
          ) : undefined
        }
      >
        {largeTitle && (
          <Animated.View
            onLayout={(event) => titleBottom.set(event.nativeEvent.layout.y + event.nativeEvent.layout.height - headerHeight)}
            // 16pt under the title, whatever the gap between the blocks below it.
            style={[styles.largeTitle, { marginBottom: spacing.lg - blockGap }, largeTitleStyle]}
          >
            <View style={styles.largeTitleText}>
              {eyebrow && (
                <AppText variant="footnote" weight="semibold" tone="secondary" style={styles.eyebrow}>
                  {eyebrow.toUpperCase()}
                </AppText>
              )}
              <AppText variant="largeTitle" accessibilityRole="header" numberOfLines={2}>
                {title}
              </AppText>
              {subtitle && (
                <AppText variant="subheadline" tone="secondary" style={styles.subtitle}>
                  {subtitle}
                </AppText>
              )}
            </View>
            {titleAccessory}
          </Animated.View>
        )}

        {children}
      </KeyboardScroll>

      {/* The navigation bar, over the content. */}
      <View pointerEvents="box-none" style={[styles.bar, { height: headerHeight }]}>
        <Animated.View pointerEvents="none" style={[StyleSheet.absoluteFill, barStyle]}>
          <Material style={StyleSheet.absoluteFill} />
          <View style={[styles.hairline, { backgroundColor: colors.separator }]} />
        </Animated.View>

        <View pointerEvents="box-none" style={[styles.barRow, { marginTop: top }]}>
          <View pointerEvents="box-none" style={styles.barSide}>
            {back ? <BackButton /> : left}
          </View>
          <Animated.View pointerEvents="none" style={[styles.barTitle, compactTitleStyle]}>
            <AppText variant="headline" numberOfLines={1} accessibilityElementsHidden={largeTitle}>
              {title}
            </AppText>
          </Animated.View>
          <View pointerEvents="box-none" style={[styles.barSide, styles.barSideRight]}>
            {right}
          </View>
        </View>
      </View>
    </View>
  );
}

function BackButton() {
  const router = useRouter();
  return <BarButton icon="back" label="Back" onPress={() => router.back()} />;
}

type BarButtonProps = {
  icon: IconName;
  /** Read aloud; the button itself shows only the glyph. */
  label: string;
  onPress: () => void;
  /** Filled with the tint, for the bar's one main action (the + on Leave). */
  prominent?: boolean;
};

/**
 * A round glyph button for the navigation bar: glass on iOS 26, a soft fill before it.
 * 36pt across, with a 44pt touch target.
 */
export function BarButton({ icon, label, onPress, prominent }: BarButtonProps) {
  const { colors } = useTheme();

  const face = (
    <View style={styles.barButtonFace}>
      <Icon name={icon} size={17} color={prominent ? colors.onPrimary : colors.text} weight="semibold" />
    </View>
  );

  return (
    <Touchable
      onPress={onPress}
      haptic="light"
      scaleTo={0.9}
      hitSlop={4}
      accessibilityRole="button"
      accessibilityLabel={label}
    >
      {prominent ? (
        <View style={[styles.barButton, { backgroundColor: colors.primary }]}>{face}</View>
      ) : hasLiquidGlass ? (
        <Material glass style={styles.barButton}>
          {face}
        </Material>
      ) : (
        <View style={[styles.barButton, { backgroundColor: colors.fill }]}>{face}</View>
      )}
    </Touchable>
  );
}

/** A text action for the bar: "Cancel", "Done". */
export function BarTextButton({
  label,
  onPress,
  emphasized,
  disabled,
}: {
  label: string;
  onPress: () => void;
  emphasized?: boolean;
  disabled?: boolean;
}) {
  return (
    <Touchable
      onPress={onPress}
      disabled={disabled}
      feedback="opacity"
      hitSlop={10}
      accessibilityRole="button"
      accessibilityState={{ disabled: !!disabled }}
    >
      <AppText variant="body" weight={emphasized ? 'semibold' : 'regular'} tone={disabled ? 'tertiary' : 'tint'}>
        {label}
      </AppText>
    </Touchable>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  largeTitle: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    gap: 12,
    transformOrigin: 'left center',
  },
  largeTitleText: { flex: 1 },
  eyebrow: { letterSpacing: 0.4, marginBottom: 2 },
  subtitle: { marginTop: 4 },
  bar: { position: 'absolute', top: 0, left: 0, right: 0 },
  hairline: { position: 'absolute', left: 0, right: 0, bottom: 0, height: StyleSheet.hairlineWidth },
  barRow: { height: BAR, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 12 },
  barSide: { minWidth: 72, flexDirection: 'row', alignItems: 'center', gap: 8 },
  barSideRight: { justifyContent: 'flex-end' },
  barTitle: { flex: 1, alignItems: 'center' },
  barButton: { width: 36, height: 36, borderRadius: 18, overflow: 'hidden' },
  barButtonFace: { flex: 1, alignItems: 'center', justifyContent: 'center' },
});
