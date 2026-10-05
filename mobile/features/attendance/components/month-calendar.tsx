import { StyleSheet, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useAuth } from '@/lib/auth';
import { todayDateKey } from '@/lib/format';
import { attendanceMeta } from '@/lib/status';
import { composite, withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';
import type { AttendanceStatus } from '@/types/api';

const WEEKDAYS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const WEEKDAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

type MonthCalendarProps = {
  month: Date; // any date within the month to render
  byDate: Record<string, AttendanceStatus>;
  onSelectDay: (date: string) => void;
};

function toKey(year: number, month: number, day: number): string {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/**
 * A month grid. A recorded day sits on a soft wash of its status colour, with its
 * number in that colour made legible on the wash, so the month reads at a glance
 * the way Fitness shows a month of rings. Today wears a teal ring. Days without a
 * record are quiet and can't be opened.
 */
export function MonthCalendar({ month, byDate, onSelectDay }: MonthCalendarProps) {
  const { colors, readable, scheme } = useTheme();
  const { organization } = useAuth();

  const year = month.getFullYear();
  const m = month.getMonth();
  const firstWeekday = new Date(year, m, 1).getDay();
  const daysInMonth = new Date(year, m + 1, 0).getDate();
  // Today is the organisation's date, the day attendance is being recorded under.
  const todayKey = todayDateKey(organization?.timezone);
  const alpha = scheme === 'dark' ? 0.26 : 0.16;

  const cells: (number | null)[] = [
    ...Array.from({ length: firstWeekday }, () => null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];

  return (
    <Animated.View key={`${year}-${m}`} entering={FadeIn.duration(220)}>
      <View style={styles.week}>
        {WEEKDAYS.map((d, i) => (
          <View key={i} style={styles.weekday} accessibilityLabel={WEEKDAY_NAMES[i]}>
            <AppText variant="caption2" weight="semibold" tone="secondary">
              {d}
            </AppText>
          </View>
        ))}
      </View>

      <View style={styles.grid}>
        {cells.map((day, index) => {
          if (day === null) {
            return <View key={`empty-${index}`} style={styles.cell} />;
          }

          const key = toKey(year, m, day);
          const status = byDate[key];
          const meta = status ? attendanceMeta(status) : null;
          const isToday = key === todayKey;
          const wash = meta ? composite(meta.color, alpha, colors.card) : null;

          const face = (
            <View
              style={[
                styles.day,
                meta && { backgroundColor: withAlpha(meta.color, alpha) },
                isToday && { borderWidth: 2, borderColor: colors.tint },
              ]}
            >
              <AppText
                variant="subheadline"
                weight={meta || isToday ? 'semibold' : 'regular'}
                color={meta && wash ? readable(meta.color, wash) : isToday ? colors.tintText : colors.textTertiary}
                numeric
                maxFontSizeMultiplier={1.1}
              >
                {day}
              </AppText>
            </View>
          );

          if (!meta) {
            return (
              <View key={key} style={styles.cell} accessibilityLabel={`${day}${isToday ? ', today' : ''}, no record`}>
                {face}
              </View>
            );
          }

          return (
            <Touchable
              key={key}
              onPress={() => onSelectDay(key)}
              haptic="selection"
              scaleTo={0.88}
              accessibilityRole="button"
              accessibilityLabel={`${day}${isToday ? ', today' : ''}, ${meta.label}`}
              style={styles.cell}
            >
              {face}
            </Touchable>
          );
        })}
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  week: { flexDirection: 'row', marginBottom: 4 },
  weekday: { flex: 1, alignItems: 'center', paddingVertical: 6 },
  grid: { flexDirection: 'row', flexWrap: 'wrap' },
  cell: { width: `${100 / 7}%`, height: 46, alignItems: 'center', justifyContent: 'center' },
  day: { width: 38, height: 38, borderRadius: 19, alignItems: 'center', justifyContent: 'center' },
});
