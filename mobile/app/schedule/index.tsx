import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { Directions, Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { StepButton } from '@/components/ui/step-button';
import { AppText } from '@/components/ui/text';
import { scheduleApi } from '@/features/schedule/api';
import { dayBlock, dayFlag, dayLines, hours, shiftDate, weekLabel } from '@/features/schedule/meta';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { ScheduleDay, ScheduleWeek } from '@/types/api';

/**
 * My schedule (ADR 0083): one's own week, Monday to Sunday — the hours of each
 * day, a holiday, a day HR changed, and one's own leave on it. It is the roster
 * board's row for this person, so it says what HR's roster says.
 *
 * Step or swipe a week either way, back for what was planned and ahead for
 * what is coming. A day that has started opens its attendance record, so the
 * plan and what happened are one tap apart.
 */
export default function ScheduleScreen() {
  const router = useRouter();
  const { organization } = useAuth();

  // Any date in the week to show; null is this week, on the server's clock.
  const [week, setWeek] = useState<string | null>(null);

  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: ScheduleWeek }>(
    () => scheduleApi.week(week ?? undefined),
    [week],
  );
  const shown = data?.data ?? null;
  const isThisWeek = !!shown?.days.some((day) => day.is_today);

  const step = (weeks: number) => {
    if (!shown) return;
    setWeek(shiftDate(shown.start, weeks * 7));
  };

  // Swipe the week sideways to turn it, as the calendar on Attendance turns the month.
  const turn = Gesture.Exclusive(
    Gesture.Fling().direction(Directions.LEFT).runOnJS(true).onEnd(() => step(1)),
    Gesture.Fling().direction(Directions.RIGHT).runOnJS(true).onEnd(() => step(-1)),
  );

  const open = (day: ScheduleDay) => router.push({ pathname: '/attendance/[date]', params: { date: day.date } });

  return (
    <Page title="My schedule" back refreshing={refreshing} onRefresh={refresh} gap={20}>
      {/* Week switcher */}
      <View style={styles.switcher}>
        <View style={styles.flex}>
          <AppText variant="title3" accessibilityRole="header" accessibilityLiveRegion="polite" numberOfLines={1}>
            {shown ? weekLabel(shown.start, shown.end) : ' '}
          </AppText>
          {shown && (
            <AppText variant="subheadline" tone="secondary" numeric>
              {shown.totals.shifts === 0
                ? 'No shifts this week'
                : `${shown.totals.shifts} ${shown.totals.shifts === 1 ? 'shift' : 'shifts'} · ${hours(shown.totals.minutes)}`}
            </AppText>
          )}
        </View>
        <View style={styles.switcherActions}>
          {shown && !isThisWeek && (
            <Button label="Today" variant="tinted" size="sm" fullWidth={false} onPress={() => setWeek(null)} />
          )}
          <StepButton icon="chevronLeft" label="Previous week" onPress={() => step(-1)} disabled={!shown} />
          <StepButton icon="chevronRight" label="Next week" onPress={() => step(1)} disabled={!shown} />
        </View>
      </View>

      {error && !shown ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !shown ? (
        <Skeleton height={7 * 64} radius={14} />
      ) : (
        <Animated.View key={shown.start} entering={enter(0)} style={{ opacity: loading ? 0.55 : 1 }}>
          <GestureDetector gesture={turn}>
            <View>
              <ListSection
                leadingWidth={40}
                footer="HR sets your schedule. A day they change for you shows as Changed. Tap a day that has started to see its record."
              >
                {shown.days.map((day) => (
                  <DayRow key={day.date} day={day} onOpen={day.is_past || day.is_today ? () => open(day) : undefined} />
                ))}
              </ListSection>
            </View>
          </GestureDetector>
        </Animated.View>
      )}

      {/* A base site that keeps its own clock (ADR 0079): say whose hours these are. */}
      {shown && organization?.timezone && shown.timezone !== organization.timezone && (
        <AppText variant="footnote" tone="secondary" style={styles.zone}>
          Times are on your site’s clock ({shown.timezone.replace(/_/g, ' ')}).
        </AppText>
      )}
    </Page>
  );
}

/** One day: its date, its hours (or why there are none), and what sets it apart. */
function DayRow({ day, onOpen }: { day: ScheduleDay; onOpen?: () => void }) {
  const { colors } = useTheme();
  const { weekday, day: date } = dayBlock(day.date);
  const { title, subtitle } = dayLines(day);
  const flag = dayFlag(day);
  const quiet = !day.is_due;

  return (
    <ListRow
      leading={
        <View style={styles.dateBlock}>
          <AppText variant="caption2" weight="semibold" tone="secondary">
            {day.is_today ? 'TODAY' : weekday.toUpperCase()}
          </AppText>
          <View style={[styles.dateDisc, day.is_today && { backgroundColor: colors.primary }]}>
            <AppText variant="headline" numeric color={day.is_today ? colors.onPrimary : quiet ? colors.textSecondary : colors.text}>
              {date}
            </AppText>
          </View>
        </View>
      }
      title={title}
      subtitle={subtitle}
      accessory={flag ? <Pill label={flag.label} color={flag.color} /> : undefined}
      onPress={onOpen}
      accessibilityLabel={[day.is_today ? 'Today' : weekday, date, title, subtitle, flag?.label].filter(Boolean).join(', ')}
    />
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  switcher: { flexDirection: 'row', alignItems: 'center', gap: 12, marginTop: -6 },
  switcherActions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  dateBlock: { width: 40, alignItems: 'center', gap: 2 },
  dateDisc: { width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
  zone: { paddingHorizontal: 16, marginTop: -8 },
});
