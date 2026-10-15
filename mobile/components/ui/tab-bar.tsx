import type { Tabs } from 'expo-router';
import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring, withTiming } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Icon, type IconName } from '@/components/ui/icon';
import { hasLiquidGlass, Material } from '@/components/ui/material';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useQueuedPunches } from '@/features/attendance/punch-queue';
import { springs } from '@/lib/motion';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';

/** expo-router ships its own copy of React Navigation, so take its props from `Tabs`. */
type TabBarProps = Parameters<NonNullable<ComponentProps<typeof Tabs>['tabBar']>>[0];

/**
 * Five tabs (ADR 0088): the day (Home), its record (Attendance), the punch
 * (Clock, in the middle where the thumb rests), everything else (Services) and
 * oneself (Profile). A new module goes into Services, never into the bar.
 */
const TABS: Record<string, { icon: IconName; label: string; center?: boolean }> = {
  index: { icon: 'home', label: 'Home' },
  attendance: { icon: 'attendance', label: 'Attendance' },
  clock: { icon: 'clock', label: 'Clock', center: true },
  services: { icon: 'services', label: 'Services' },
  profile: { icon: 'profile', label: 'Profile' },
};

const BAR_HEIGHT = 62;
const BAR_PADDING = 5;

/** The bar floats clear of the home indicator, or 12pt off the bottom without one. */
function barBottom(bottomInset: number): number {
  return bottomInset > 0 ? Math.max(bottomInset - 8, 12) : 12;
}

/**
 * Where each tab sits on screen, from the same numbers the bar is laid out
 * with — so the app's tour (ADR 0080) can light one up without measuring it.
 * Tabs are in the bar's order: Home, Attendance, Clock, Services, Profile.
 */
export function tabRects(
  window: { width: number; height: number },
  bottomInset: number,
  gutter: number,
): { name: string; x: number; y: number; width: number; height: number }[] {
  const names = Object.keys(TABS);
  const inner = window.width - gutter * 2 - BAR_PADDING * 2;
  const itemWidth = inner / names.length;
  const top = window.height - barBottom(bottomInset) - BAR_HEIGHT + BAR_PADDING;

  return names.map((name, index) => ({
    name,
    x: gutter + BAR_PADDING + index * itemWidth,
    y: top,
    width: itemWidth,
    height: BAR_HEIGHT - BAR_PADDING * 2,
  }));
}

/** How much room a tab screen leaves under its content so the last row clears the bar. */
export function useTabBarInset(): number {
  const { bottom } = useSafeAreaInsets();
  return barBottom(bottom) + BAR_HEIGHT + 24;
}

/**
 * The tab bar: a floating capsule the content scrolls under, glass on iOS 26 and a
 * blur before it. A soft pill slides to the selected tab on a spring, and the
 * selected icon fills, as SF Symbols do in Apple's own bars.
 *
 * Clock, the one thing done every day, is a teal disc in the middle slot: GCash's
 * QR button, kept inside the capsule rather than raised out of it (ADR 0088). It
 * wears a ring when selected, and the pill steps aside. It carries a badge while
 * punches made offline are waiting to be sent (ADR 0040).
 */
export function TabBar({ state, navigation }: TabBarProps) {
  const { colors, scheme, spacing } = useTheme();
  const insets = useSafeAreaInsets();
  const waiting = useQueuedPunches().length;

  const routes = state.routes.filter((route) => TABS[route.name]);
  const [width, setWidth] = useState(0);
  const itemWidth = width > 0 ? (width - BAR_PADDING * 2) / routes.length : 0;

  const selected = Math.max(0, routes.findIndex((route) => route.key === state.routes[state.index]?.key));
  const onCenter = !!TABS[routes[selected]?.name ?? '']?.center;
  const x = useSharedValue(0);
  const shown = useSharedValue(1);
  const placed = useRef(false);

  // The pill lands under the first tab without a slide; after that it springs.
  // On the Clock disc it fades where it is, and slides on from there.
  useEffect(() => {
    if (itemWidth === 0) return;
    shown.set(withTiming(onCenter ? 0 : 1, { duration: 160 }));
    if (onCenter) return;
    const target = selected * itemWidth;
    x.set(placed.current ? withSpring(target, springs.snappy) : target);
    placed.current = true;
  }, [selected, onCenter, itemWidth, x, shown]);

  const indicatorStyle = useAnimatedStyle(() => ({ opacity: shown.get(), transform: [{ translateX: x.get() }] }));

  const edge = scheme === 'dark' ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';

  return (
    <View
      pointerEvents="box-none"
      style={[styles.host, { left: spacing.gutter, right: spacing.gutter, bottom: barBottom(insets.bottom) }]}
    >
      <View
        style={[
          styles.shadow,
          !hasLiquidGlass && {
            boxShadow:
              scheme === 'dark'
                ? '0 8px 24px rgba(0, 0, 0, 0.5)'
                : '0 1px 3px rgba(0, 0, 0, 0.06), 0 10px 30px rgba(0, 0, 0, 0.1)',
          },
        ]}
      >
        <Material
          glass
          style={[
            styles.bar,
            !hasLiquidGlass && { borderWidth: StyleSheet.hairlineWidth, borderColor: edge },
          ]}
        >
          <View style={styles.row} onLayout={(event) => setWidth(event.nativeEvent.layout.width)}>
            {itemWidth > 0 && (
              <Animated.View
                style={[
                  styles.indicator,
                  { width: itemWidth, backgroundColor: colors.fill },
                  indicatorStyle,
                ]}
              />
            )}

            {routes.map((route) => {
              const meta = TABS[route.name];
              const focused = route.key === state.routes[state.index]?.key;
              const ink = focused ? colors.tintText : colors.textSecondary;
              const showBadge = route.name === 'clock' && waiting > 0;

              const onPress = () => {
                const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });

                if (!focused && !event.defaultPrevented) {
                  navigation.navigate(route.name, route.params);
                }
              };

              return (
                <Touchable
                  key={route.key}
                  onPress={onPress}
                  onLongPress={() => navigation.emit({ type: 'tabLongPress', target: route.key })}
                  haptic="selection"
                  scaleTo={0.9}
                  accessibilityRole="tab"
                  accessibilityLabel={
                    showBadge ? `${meta.label}, ${waiting} ${waiting === 1 ? 'punch' : 'punches'} waiting to send` : meta.label
                  }
                  accessibilityState={{ selected: focused }}
                  style={styles.item}
                >
                  {meta.center ? (
                    <View style={[styles.ring, { borderColor: focused ? colors.tint : 'transparent' }]}>
                      <View style={[styles.disc, { backgroundColor: palette.teal }]}>
                        <Icon name={meta.icon} size={24} color={palette.navy} active={focused} weight="semibold" />
                      </View>
                      {showBadge && <Badge count={waiting} style={styles.discBadge} />}
                    </View>
                  ) : (
                    <>
                      <View>
                        <Icon name={meta.icon} size={23} color={ink} active={focused} weight="medium" />
                        {showBadge && <Badge count={waiting} style={styles.badge} />}
                      </View>
                      <AppText
                        variant="caption2"
                        weight={focused ? 'semibold' : 'medium'}
                        color={ink}
                        numberOfLines={1}
                        maxFontSizeMultiplier={1.15}
                        style={styles.label}
                      >
                        {meta.label}
                      </AppText>
                    </>
                  )}
                </Touchable>
              );
            })}
          </View>
        </Material>
      </View>
    </View>
  );
}

/** The count of punches waiting to send. */
function Badge({ count, style }: { count: number; style: StyleProp<ViewStyle> }) {
  const { colors } = useTheme();
  return (
    <View style={[styles.badgeShape, { backgroundColor: colors.danger }, style]}>
      <AppText variant="caption2" weight="bold" color={colors.onDanger} style={styles.badgeText}>
        {count}
      </AppText>
    </View>
  );
}

const DISC = 42;

const styles = StyleSheet.create({
  host: { position: 'absolute' },
  shadow: { borderRadius: BAR_HEIGHT / 2 },
  bar: { height: BAR_HEIGHT, borderRadius: BAR_HEIGHT / 2, overflow: 'hidden' },
  row: { flex: 1, flexDirection: 'row', padding: BAR_PADDING },
  indicator: {
    position: 'absolute',
    top: BAR_PADDING,
    bottom: BAR_PADDING,
    left: BAR_PADDING,
    borderRadius: (BAR_HEIGHT - BAR_PADDING * 2) / 2,
  },
  item: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 3 },
  label: { fontSize: 10.5, lineHeight: 13, letterSpacing: 0.1 },
  ring: { width: DISC + 8, height: DISC + 8, borderRadius: (DISC + 8) / 2, borderWidth: 2, padding: 2 },
  disc: { flex: 1, borderRadius: DISC / 2, alignItems: 'center', justifyContent: 'center' },
  discBadge: { top: -2, right: -4 },
  badge: { top: -4, right: -10 },
  badgeShape: {
    position: 'absolute',
    minWidth: 17,
    height: 17,
    borderRadius: 9,
    paddingHorizontal: 4,
    alignItems: 'center',
    justifyContent: 'center',
  },
  badgeText: { fontSize: 10.5, lineHeight: 13 },
});
