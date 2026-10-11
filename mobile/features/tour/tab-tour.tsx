import { useEffect, useSyncExternalStore } from 'react';
import { Modal, StyleSheet, useWindowDimensions, View } from 'react-native';
import Animated, { FadeIn, FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Icon, type IconName } from '@/components/ui/icon';
import { tabRects } from '@/components/ui/tab-bar';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { useAuth } from '@/lib/auth';
import { useTheme } from '@/theme/theme';

import { tourApi, type TourOutcome, type TourRunMode } from './api';

/** Let Home land before the tour opens over it. */
const OFFER_DELAY = 1200;

type Stop = { id: string; tab?: string; icon: IconName; title: string; body: string };

/**
 * The app's tour (ADR 0080), in order: a welcome, a stop on each tab, a
 * send-off. Its ids are what the per-stop numbers on the web count.
 */
const STOPS: Stop[] = [
  {
    id: 'welcome',
    icon: 'sparkles',
    title: 'Welcome to SYNAPSE',
    body: 'Your time record, your leave and everything else about your work, in your pocket. A quick look at the five tabs — about thirty seconds.',
  },
  {
    id: 'home',
    tab: 'index',
    icon: 'home',
    title: 'Home',
    body: 'Today at a glance: where your day stands, what is next, and shortcuts. The magnifying glass at the top searches your own records.',
  },
  {
    id: 'attendance',
    tab: 'attendance',
    icon: 'attendance',
    title: 'Attendance',
    body: 'Your days, month by month. Tap one to see every punch, where it was made and how the day was judged.',
  },
  {
    id: 'clock',
    tab: 'clock',
    icon: 'clock',
    title: 'Clock',
    body: 'Clock in and out, and take your breaks. No signal? The punch is kept on your phone and sent when you are back online.',
  },
  {
    id: 'requests',
    tab: 'requests',
    icon: 'leave',
    title: 'Leave',
    body: 'Your balances and requests, and filing a new one. Holidays are never charged as leave.',
  },
  {
    id: 'profile',
    tab: 'profile',
    icon: 'profile',
    title: 'Profile',
    body: 'Your details, awards, events and holidays — and your resignation, should you need it. Replay this tour from here any time.',
  },
  {
    id: 'finish',
    icon: 'checkCircle',
    title: 'You’re all set',
    body: 'Start with the Clock tab when your shift begins. Search and this tour are always under Profile.',
  },
];

type TourState = {
  running: boolean;
  index: number;
  mode: TourRunMode;
  runId: number | null;
  /** Offered or answered since the app opened, so it is offered once. */
  offered: boolean;
  answered: boolean;
};

let state: TourState = { running: false, index: 0, mode: 'first', runId: null, offered: false, answered: false };
const listeners = new Set<() => void>();

function update(next: Partial<TourState>) {
  state = { ...state, ...next };
  listeners.forEach((listener) => listener());
}

const store = {
  subscribe(listener: () => void) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
  get: () => state,
};

/**
 * The app's tour: start it (offered on its own once, or replayed from
 * Profile), move through it and end it. The run is kept outside React, so the
 * Profile row and the tab layout share one.
 */
export function useTabTour() {
  const current = useSyncExternalStore(store.subscribe, store.get, store.get);

  const start = (mode: TourRunMode) => {
    if (state.running) return;

    update({ running: true, index: 0, mode, runId: null, offered: true });

    void tourApi
      .start(
        mode,
        STOPS.map((stop) => stop.id),
      )
      .then((result) => {
        update({ runId: result.id });

        if (state.running && state.index > 0) {
          void tourApi.reach(result.id, state.index).catch(() => {});
        }
      })
      .catch(() => {});
  };

  const go = (index: number) => {
    const next = Math.max(0, Math.min(index, STOPS.length - 1));
    update({ index: next });

    if (state.runId !== null) {
      void tourApi.reach(state.runId, next).catch(() => {});
    }
  };

  const end = (outcome: TourOutcome) => {
    const { runId, mode } = state;
    const answers = mode === 'first';

    update({ running: false, index: 0, runId: null, answered: state.answered || answers });

    // Fire and forget: should it fail, the tour is offered again next time.
    void tourApi.finish(outcome, runId, answers).catch(() => {});
  };

  return {
    ...current,
    stop: STOPS[current.index],
    total: STOPS.length,
    start,
    next: () => (current.index >= STOPS.length - 1 ? end('completed') : go(current.index + 1)),
    back: () => go(current.index - 1),
    skip: () => end(current.index >= STOPS.length - 1 ? 'completed' : 'skipped'),
  };
}

/**
 * The tour's stage, mounted once in the tab layout: it offers itself once to
 * somebody who has never answered it, then lights up each tab in turn with a
 * card above the bar. The page underneath stays as it is.
 */
export function TabTour() {
  const tour = useTabTour();
  const { user } = useAuth();
  const { colors, spacing, radius } = useTheme();
  const window = useWindowDimensions();
  const insets = useSafeAreaInsets();

  const owed = (user?.tour?.owed ?? false) && !tour.answered;
  const { offered, running, start } = tour;

  useEffect(() => {
    if (!owed || offered || running) return;

    const timer = setTimeout(() => start('first'), OFFER_DELAY);

    return () => clearTimeout(timer);
  }, [owed, offered, running, start]);

  if (!tour.running) return null;

  const stop = tour.stop;
  const rect = stop.tab ? tabRects(window, insets.bottom, spacing.gutter).find((tab) => tab.name === stop.tab) : null;
  const pad = 4;
  const lit = rect
    ? { x: rect.x - pad, y: rect.y - pad, width: rect.width + pad * 2, height: rect.height + pad * 2 }
    : null;
  const dim = 'rgba(0, 0, 0, 0.55)';
  const first = tour.index === 0;
  const last = tour.index === tour.total - 1;

  return (
    <Modal transparent visible animationType="fade" onRequestClose={tour.skip} statusBarTranslucent>
      <View style={StyleSheet.absoluteFill} accessibilityViewIsModal>
        {lit ? (
          <>
            {/* The dim, in four pieces round the lit tab. */}
            <View style={[styles.shade, { top: 0, left: 0, right: 0, height: lit.y, backgroundColor: dim }]} />
            <View style={[styles.shade, { top: lit.y + lit.height, left: 0, right: 0, bottom: 0, backgroundColor: dim }]} />
            <View style={[styles.shade, { top: lit.y, left: 0, width: lit.x, height: lit.height, backgroundColor: dim }]} />
            <View
              style={[
                styles.shade,
                { top: lit.y, left: lit.x + lit.width, right: 0, height: lit.height, backgroundColor: dim },
              ]}
            />
            <Animated.View
              key={stop.id}
              entering={FadeIn.duration(200)}
              pointerEvents="none"
              style={[
                styles.ring,
                { left: lit.x, top: lit.y, width: lit.width, height: lit.height, borderColor: colors.tint },
              ]}
            />
          </>
        ) : (
          <View style={[StyleSheet.absoluteFill, { backgroundColor: dim }]} />
        )}

        <Animated.View
          key={`card-${stop.id}`}
          entering={FadeInDown.duration(240)}
          style={[
            styles.card,
            { backgroundColor: colors.card, borderRadius: radius.xl, left: spacing.gutter, right: spacing.gutter },
            lit
              ? { bottom: window.height - lit.y + 14 }
              : { top: window.height * 0.28 },
          ]}
          accessibilityLiveRegion="polite"
        >
          <View style={styles.head}>
            <ToneWell icon={stop.icon} color={colors.tint} size={40} />
            <View style={styles.flex}>
              <AppText variant="caption" tone="secondary" numeric>
                {first || last ? 'Tour' : `${tour.index} of ${tour.total - 2}`}
              </AppText>
              <AppText variant="title3" accessibilityRole="header">
                {stop.title}
              </AppText>
            </View>
          </View>
          <AppText variant="callout" tone="secondary">
            {stop.body}
          </AppText>
          <View style={styles.buttons}>
            {last ? (
              <View style={styles.flex} />
            ) : (
              <Button label={first ? 'Not now' : 'Skip'} variant="plain" size="sm" onPress={tour.skip} />
            )}
            {!first && !last && <Button label="Back" variant="gray" size="sm" onPress={tour.back} />}
            <Button
              label={first ? 'Show me' : last ? 'Done' : 'Next'}
              variant="primary"
              size="sm"
              onPress={tour.next}
            />
          </View>
          {lit && (
            <View pointerEvents="none" style={[styles.pointer, { left: lit.x + lit.width / 2 - spacing.gutter - 8 }]}>
              <Icon name="chevronDown" size={16} color={colors.card} />
            </View>
          )}
        </Animated.View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  shade: { position: 'absolute' },
  ring: { position: 'absolute', borderWidth: 2, borderRadius: 30 },
  card: { position: 'absolute', padding: 18, gap: 12 },
  head: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  flex: { flex: 1 },
  buttons: { flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', gap: 8 },
  pointer: { position: 'absolute', bottom: -12 },
});
