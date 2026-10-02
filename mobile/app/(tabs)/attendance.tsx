import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { Directions, Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { attendanceApi } from '@/features/attendance/api';
import { MonthCalendar } from '@/features/attendance/components/month-calendar';
import { useAuth } from '@/lib/auth';
import { formatMinutes, formatMonthYear, formatTime, parseDateOnly } from '@/lib/format';
import { enter } from '@/lib/motion';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { AttendanceRecord, AttendanceStatus, AttendanceSummary } from '@/types/api';

const SHORT_DAYS = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

function monthRange(month: Date) {
  const y = month.getFullYear();
  const m = month.getMonth();
  const pad = (n: number) => String(n).padStart(2, '0');
  const last = new Date(y, m + 1, 0).getDate();
  return { from: `${y}-${pad(m + 1)}-01`, to: `${y}-${pad(m + 1)}-${pad(last)}` };
}

type AttData = { records: AttendanceRecord[]; summary: AttendanceSummary };

export default function AttendanceScreen() {
  const { colors, spacing, status, readable } = useTheme();
  const router = useRouter();
  const { organization } = useAuth();

  const [month, setMonth] = useState(() => {
    const t = new Date();
    return new Date(t.getFullYear(), t.getMonth(), 1);
  });
  const [view, setView] = useState<'calendar' | 'list'>('calendar');

  const { from, to } = monthRange(month);
  const monthKey = `${month.getFullYear()}-${month.getMonth()}`;

  const { data, loading, refreshing, refresh, error, reload } = useQuery<AttData>(async () => {
    const [records, summary] = await Promise.all([attendanceApi.records(from, to), attendanceApi.summary(from, to)]);
    return { records: records.data, summary };
  }, [monthKey]);

  const byDate: Record<string, AttendanceStatus> = {};
  for (const record of data?.records ?? []) {
    if (record.work_date) byDate[record.work_date] = record.status;
  }

  const isCurrentMonth = (() => {
    const t = new Date();
    return month.getFullYear() === t.getFullYear() && month.getMonth() === t.getMonth();
  })();

  const shiftMonth = (delta: number) => {
    if (delta > 0 && isCurrentMonth) return;
    setMonth((m) => new Date(m.getFullYear(), m.getMonth() + delta, 1));
  };

  const thisMonth = () => {
    const t = new Date();
    setMonth(new Date(t.getFullYear(), t.getMonth(), 1));
  };

  // Swipe the calendar sideways to turn the month, as in Calendar.
  const turn = Gesture.Exclusive(
    Gesture.Fling().direction(Directions.LEFT).runOnJS(true).onEnd(() => shiftMonth(1)),
    Gesture.Fling().direction(Directions.RIGHT).runOnJS(true).onEnd(() => shiftMonth(-1)),
  );

  const summary = data?.summary;
  const metrics = summary
    ? [
        { label: 'On time', value: summary.status_counts.present, color: status.present },
        { label: 'Late', value: summary.status_counts.late, color: status.late },
        { label: 'Absent', value: summary.status_counts.absent, color: status.absent },
        { label: 'On leave', value: summary.status_counts.on_leave, color: status.leave },
      ]
    : [];
  const total = metrics.reduce((sum, metric) => sum + metric.value, 0);

  const openDay = (date: string) => router.push({ pathname: '/attendance/[date]', params: { date } });

  return (
    <Page title="Attendance" refreshing={refreshing} onRefresh={refresh} tabInset>
      {/* Month switcher */}
      <View style={styles.switcher}>
        <AppText variant="title3" accessibilityRole="header" accessibilityLiveRegion="polite">
          {formatMonthYear(month)}
        </AppText>
        <View style={styles.switcherActions}>
          {!isCurrentMonth && <Button label="Today" variant="tinted" size="sm" fullWidth={false} onPress={thisMonth} />}
          <StepButton icon="chevronLeft" label="Previous month" onPress={() => shiftMonth(-1)} />
          <StepButton icon="chevronRight" label="Next month" onPress={() => shiftMonth(1)} disabled={isCurrentMonth} />
        </View>
      </View>

      {error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !data ? (
        <>
          <Skeleton height={168} radius={20} />
          <Skeleton height={34} radius={10} />
          <Skeleton height={330} radius={20} />
        </>
      ) : (
        <View style={[styles.stack, { opacity: loading ? 0.55 : 1 }]}>
          {/* Metrics summary */}
          <Animated.View entering={enter(0)}>
            <Card style={{ gap: spacing.lg }}>
              <View
                style={[styles.distribution, { backgroundColor: colors.fill }]}
                accessibilityRole="image"
                accessibilityLabel={metrics.map((metric) => `${metric.value} ${metric.label}`).join(', ')}
              >
                {total > 0 &&
                  metrics
                    .filter((metric) => metric.value > 0)
                    .map((metric) => (
                      <View
                        key={metric.label}
                        style={{ flex: metric.value, backgroundColor: readable(metric.color, colors.card, 3) }}
                      />
                    ))}
              </View>

              <View style={styles.metrics}>
                {metrics.map((metric) => (
                  <View key={metric.label} style={styles.metric}>
                    <AppText variant="title2" numeric>
                      {metric.value}
                    </AppText>
                    <View style={styles.metricLabel}>
                      <View style={[styles.dot, { backgroundColor: readable(metric.color, colors.card, 3) }]} />
                      <AppText variant="caption" tone="secondary" numberOfLines={1}>
                        {metric.label}
                      </AppText>
                    </View>
                  </View>
                ))}
              </View>

              <View style={[styles.hairline, { backgroundColor: colors.separator }]} />

              <View style={styles.metrics}>
                <MiniStat label="Hours rendered" value={formatMinutes(summary?.worked_minutes ?? 0)} />
                <MiniStat label="Late" value={formatMinutes(summary?.late_minutes ?? 0)} />
                <MiniStat label="Overtime" value={formatMinutes(summary?.overtime_minutes ?? 0)} />
              </View>
            </Card>
          </Animated.View>

          <Segmented
            options={[
              { value: 'calendar', label: 'Calendar' },
              { value: 'list', label: 'List' },
            ]}
            value={view}
            onChange={setView}
            accessibilityLabel="View"
          />

          {view === 'calendar' ? (
            <Animated.View key="calendar" entering={FadeIn.duration(220)}>
              <GestureDetector gesture={turn}>
                <Card>
                  <MonthCalendar month={month} byDate={byDate} onSelectDay={openDay} />
                  <View style={[styles.hairline, { backgroundColor: colors.separator, marginVertical: spacing.md }]} />
                  <View style={styles.legend}>
                    {(['present', 'late', 'absent', 'on_leave', 'holiday', 'day_off'] as AttendanceStatus[]).map((s) => {
                      const meta = attendanceMeta(s);
                      return (
                        <View key={s} style={styles.legendItem}>
                          <View style={[styles.dot, { backgroundColor: readable(meta.color, colors.card, 3) }]} />
                          <AppText variant="caption" tone="secondary">
                            {meta.label}
                          </AppText>
                        </View>
                      );
                    })}
                  </View>
                </Card>
              </GestureDetector>
            </Animated.View>
          ) : data.records.length === 0 ? (
            <EmptyState icon="calendar" title="No records" message="No attendance was recorded this month." />
          ) : (
            <Animated.View key="list" entering={FadeIn.duration(220)}>
              <ListSection leadingWidth={38}>
                {data.records.map((record) => {
                  const meta = attendanceMeta(record.status);
                  const date = record.work_date ? parseDateOnly(record.work_date) : null;

                  return (
                    <ListRow
                      key={record.work_date}
                      leading={
                        <View style={styles.dateBlock}>
                          <AppText variant="caption2" weight="semibold" tone="secondary">
                            {date ? SHORT_DAYS[date.getDay()] : '—'}
                          </AppText>
                          <AppText variant="title3" numeric>
                            {date ? date.getDate() : ''}
                          </AppText>
                        </View>
                      }
                      title={
                        record.first_in_at
                          ? `${formatTime(record.first_in_at, organization?.timezone)} – ${formatTime(record.last_out_at, organization?.timezone)}`
                          : 'No punches'
                      }
                      subtitle={`${formatMinutes(record.worked_minutes)} worked`}
                      accessory={<Pill label={meta.label} color={meta.color} />}
                      onPress={() => openDay(record.work_date ?? '')}
                    />
                  );
                })}
              </ListSection>
            </Animated.View>
          )}
        </View>
      )}
    </Page>
  );
}

function StepButton({
  icon,
  label,
  onPress,
  disabled,
}: {
  icon: 'chevronLeft' | 'chevronRight';
  label: string;
  onPress: () => void;
  disabled?: boolean;
}) {
  const { colors } = useTheme();

  return (
    <Touchable
      onPress={onPress}
      disabled={disabled}
      haptic="selection"
      scaleTo={0.88}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={label}
      accessibilityState={{ disabled: !!disabled }}
      style={[styles.step, { backgroundColor: colors.fill }]}
    >
      <Icon name={icon} size={14} color={disabled ? colors.textTertiary : colors.tintText} weight="bold" />
    </Touchable>
  );
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.metric} accessible accessibilityLabel={`${label}: ${value}`}>
      <AppText variant="headline" numeric numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </AppText>
      <AppText variant="caption" tone="secondary" numberOfLines={1}>
        {label}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  stack: { gap: 24 },
  switcher: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: -6 },
  switcherActions: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  step: { width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
  distribution: { height: 10, borderRadius: 5, overflow: 'hidden', flexDirection: 'row', gap: 2 },
  metrics: { flexDirection: 'row' },
  metric: { flex: 1, alignItems: 'center', gap: 2 },
  metricLabel: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  dot: { width: 7, height: 7, borderRadius: 4 },
  hairline: { height: StyleSheet.hairlineWidth },
  legend: { flexDirection: 'row', flexWrap: 'wrap', columnGap: 14, rowGap: 8, justifyContent: 'center' },
  legendItem: { flexDirection: 'row', alignItems: 'center', gap: 5 },
  dateBlock: { width: 38, alignItems: 'center' },
});
