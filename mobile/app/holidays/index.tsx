import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { holidaysApi } from '@/features/holidays/api';
import { parseDateOnly } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { status } from '@/theme/tokens';
import type { UpcomingHoliday } from '@/types/api';

const TYPE_LABEL: Record<UpcomingHoliday['type'], string> = {
  regular: 'Regular holiday',
  special_non_working: 'Special non-working day',
  special_working: 'Special working day',
};

const TYPE_COLOR: Record<UpcomingHoliday['type'], string> = {
  regular: status.absent,
  special_non_working: status.late,
  special_working: status.holiday,
};

/** "Thu, Apr 2" from a calendar date. */
function shortDay(iso: string): string {
  return parseDateOnly(iso).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

/**
 * The holidays one keeps over the coming year (ADR 0078), month by month: the
 * ones kept everywhere, Holy Week on this year's dates, and the local holidays
 * of one's own site. A special working day is a holiday one still works.
 */
export default function HolidaysScreen() {
  const { colors, readable } = useTheme();
  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: UpcomingHoliday[] }>(
    () => holidaysApi.upcoming(),
    [],
  );
  const holidays = data?.data ?? [];
  const next = holidays.find((holiday) => holiday.day_off);

  const months: { label: string; items: UpcomingHoliday[] }[] = [];

  for (const holiday of holidays) {
    const label = parseDateOnly(holiday.date).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    const last = months[months.length - 1];

    if (last && last.label === label) {
      last.items.push(holiday);
    } else {
      months.push({ label, items: [holiday] });
    }
  }

  return (
    <Page
      title="Holidays"
      subtitle={next ? `Next day off: ${next.name}, ${shortDay(next.date)}` : undefined}
      back
      refreshing={refreshing}
      onRefresh={refresh}
      gap={24}
    >
      {loading ? (
        <>
          <Skeleton height={140} radius={14} />
          <Skeleton height={180} radius={14} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : holidays.length === 0 ? (
        <EmptyState
          icon="holiday"
          title="No holidays in the next year"
          message="When HR adds the company's holidays, they appear here."
        />
      ) : (
        months.map((month, index) => (
          <Animated.View key={month.label} entering={enter(Math.min(index, 6))}>
            <ListSection header={month.label} leadingWidth={10}>
              {month.items.map((holiday) => (
                <ListRow
                  key={holiday.id}
                  leading={
                    <View
                      style={[styles.dot, { backgroundColor: readable(TYPE_COLOR[holiday.type], colors.card, 3) }]}
                    />
                  }
                  title={holiday.name}
                  subtitle={[
                    TYPE_LABEL[holiday.type],
                    holiday.local ? (holiday.site ? `Only at ${holiday.site}` : 'Local holiday') : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                  value={shortDay(holiday.date)}
                  wrap
                />
              ))}
            </ListSection>
          </Animated.View>
        ))
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  dot: { width: 10, height: 10, borderRadius: 5 },
});
