import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, View } from 'react-native';

import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Pill } from '@/components/ui/pill';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { attendanceApi } from '@/features/attendance/api';
import { MonthCalendar } from '@/features/attendance/components/month-calendar';
import { formatMinutes, formatShortDate, formatTime } from '@/lib/format';
import { attendanceMeta } from '@/lib/status';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { AttendanceRecord, AttendanceStatus, AttendanceSummary } from '@/types/api';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

function monthRange(month: Date) {
  const y = month.getFullYear();
  const m = month.getMonth();
  const pad = (n: number) => String(n).padStart(2, '0');
  const last = new Date(y, m + 1, 0).getDate();
  return { from: `${y}-${pad(m + 1)}-01`, to: `${y}-${pad(m + 1)}-${pad(last)}` };
}

type AttData = { records: AttendanceRecord[]; summary: AttendanceSummary };

export default function AttendanceScreen() {
  const { colors, spacing, status } = useTheme();
  const router = useRouter();

  const [month, setMonth] = useState(() => {
    const t = new Date();
    return new Date(t.getFullYear(), t.getMonth(), 1);
  });
  const [view, setView] = useState<'calendar' | 'list'>('calendar');

  const { from, to } = monthRange(month);
  const monthKey = `${month.getFullYear()}-${month.getMonth()}`;

  const { data, loading, refreshing, refresh } = useQuery<AttData>(async () => {
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

  const shiftMonth = (delta: number) => setMonth((m) => new Date(m.getFullYear(), m.getMonth() + delta, 1));

  const summary = data?.summary;
  const metrics = summary
    ? [
        { label: 'Present', value: summary.status_counts.present + summary.status_counts.late, color: status.present },
        { label: 'Late', value: summary.status_counts.late, color: status.late },
        { label: 'Absent', value: summary.status_counts.absent, color: status.absent },
        { label: 'On Leave', value: summary.status_counts.on_leave, color: status.leave },
      ]
    : [];

  return (
    <Screen>
      <ScreenHeader title="Attendance" subtitle="Your daily time records" />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 120, gap: spacing.lg }}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        {/* Month switcher */}
        <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
          <NavButton icon="chevron-back" onPress={() => shiftMonth(-1)} />
          <AppText variant="heading">
            {MONTHS[month.getMonth()]} {month.getFullYear()}
          </AppText>
          <NavButton icon="chevron-forward" onPress={() => shiftMonth(1)} disabled={isCurrentMonth} />
        </View>

        {/* Metrics summary */}
        {loading ? (
          <Skeleton height={150} radius={18} />
        ) : (
          <Card elevated>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              {metrics.map((metric) => (
                <View key={metric.label} style={{ alignItems: 'center', flex: 1 }}>
                  <AppText variant="title" style={{ color: metric.color }}>
                    {metric.value}
                  </AppText>
                  <AppText variant="caption" muted>
                    {metric.label}
                  </AppText>
                </View>
              ))}
            </View>
            <View style={{ height: 1, backgroundColor: colors.hairline, marginVertical: spacing.md }} />
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <MiniStat label="Hours rendered" value={formatMinutes(summary?.worked_minutes ?? 0)} />
              <MiniStat label="Late" value={formatMinutes(summary?.late_minutes ?? 0)} />
              <MiniStat label="Overtime" value={formatMinutes(summary?.overtime_minutes ?? 0)} />
            </View>
          </Card>
        )}

        <Segmented
          options={[
            { value: 'calendar', label: 'Calendar' },
            { value: 'list', label: 'List' },
          ]}
          value={view}
          onChange={setView}
        />

        {loading ? (
          <Skeleton height={280} radius={18} />
        ) : view === 'calendar' ? (
          <Card>
            <MonthCalendar
              month={month}
              byDate={byDate}
              onSelectDay={(date) => router.push({ pathname: '/attendance/[date]', params: { date } })}
            />
            <View style={{ height: 1, backgroundColor: colors.hairline, marginVertical: spacing.md }} />
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10 }}>
              {(['present', 'late', 'absent', 'on_leave', 'holiday', 'day_off'] as AttendanceStatus[]).map((s) => {
                const meta = attendanceMeta(s);
                return (
                  <View key={s} style={{ flexDirection: 'row', alignItems: 'center', gap: 5 }}>
                    <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: meta.color }} />
                    <AppText variant="caption" faint>
                      {meta.label}
                    </AppText>
                  </View>
                );
              })}
            </View>
          </Card>
        ) : (data?.records.length ?? 0) === 0 ? (
          <EmptyState icon="calendar-outline" title="No records" message="No attendance was recorded this month." />
        ) : (
          <View style={{ gap: spacing.md }}>
            {data?.records.map((record) => {
              const meta = attendanceMeta(record.status);
              return (
                <Card
                  key={record.work_date}
                  onPress={() => router.push({ pathname: '/attendance/[date]', params: { date: record.work_date ?? '' } })}
                  style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}
                >
                  <View style={{ width: 46, alignItems: 'center' }}>
                    <AppText variant="overline" faint>
                      {formatShortDate(record.work_date).split(' ')[0]}
                    </AppText>
                    <AppText variant="title">{formatShortDate(record.work_date).split(' ')[1]}</AppText>
                  </View>
                  <View style={{ flex: 1 }}>
                    <Pill label={meta.label} color={meta.color} dot />
                    <AppText variant="caption" muted style={{ marginTop: 4 }}>
                      {record.first_in_at ? `${formatTime(record.first_in_at)} – ${formatTime(record.last_out_at)}` : 'No punches'}
                    </AppText>
                  </View>
                  <AppText variant="label" muted>
                    {formatMinutes(record.worked_minutes)}
                  </AppText>
                  <Ionicons name="chevron-forward" size={16} color={colors.textFaint} />
                </Card>
              );
            })}
          </View>
        )}
      </ScrollView>
    </Screen>
  );
}

function NavButton({ icon, onPress, disabled }: { icon: keyof typeof Ionicons.glyphMap; onPress: () => void; disabled?: boolean }) {
  const { colors } = useTheme();
  return (
    <Pressable
      onPress={onPress}
      disabled={disabled}
      style={{
        width: 40,
        height: 40,
        borderRadius: 20,
        backgroundColor: colors.card,
        borderWidth: 1,
        borderColor: colors.border,
        alignItems: 'center',
        justifyContent: 'center',
        opacity: disabled ? 0.4 : 1,
      }}
    >
      <Ionicons name={icon} size={20} color={colors.text} />
    </Pressable>
  );
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <View style={{ alignItems: 'center', flex: 1 }}>
      <AppText variant="label">{value}</AppText>
      <AppText variant="caption" faint>
        {label}
      </AppText>
    </View>
  );
}
