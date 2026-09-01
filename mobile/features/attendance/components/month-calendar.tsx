import { Pressable, View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { attendanceMeta } from '@/lib/status';
import { useTheme } from '@/theme/theme';
import type { AttendanceStatus } from '@/types/api';

const WEEKDAYS = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

type MonthCalendarProps = {
  month: Date; // any date within the month to render
  byDate: Record<string, AttendanceStatus>;
  onSelectDay: (date: string) => void;
};

function toKey(year: number, month: number, day: number): string {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** A month grid with a status dot under each recorded day. */
export function MonthCalendar({ month, byDate, onSelectDay }: MonthCalendarProps) {
  const { colors } = useTheme();

  const year = month.getFullYear();
  const m = month.getMonth();
  const firstWeekday = new Date(year, m, 1).getDay();
  const daysInMonth = new Date(year, m + 1, 0).getDate();
  const todayKey = (() => {
    const t = new Date();
    return toKey(t.getFullYear(), t.getMonth(), t.getDate());
  })();

  const cells: (number | null)[] = [
    ...Array.from({ length: firstWeekday }, () => null),
    ...Array.from({ length: daysInMonth }, (_, i) => i + 1),
  ];

  return (
    <View>
      <View style={{ flexDirection: 'row' }}>
        {WEEKDAYS.map((d, i) => (
          <View key={i} style={{ flex: 1, alignItems: 'center', paddingVertical: 6 }}>
            <AppText variant="caption" faint style={{ fontWeight: '700' }}>
              {d}
            </AppText>
          </View>
        ))}
      </View>

      <View style={{ flexDirection: 'row', flexWrap: 'wrap' }}>
        {cells.map((day, index) => {
          if (day === null) {
            return <View key={`empty-${index}`} style={{ width: `${100 / 7}%`, height: 46 }} />;
          }

          const key = toKey(year, m, day);
          const status = byDate[key];
          const meta = status ? attendanceMeta(status) : null;
          const isToday = key === todayKey;

          return (
            <Pressable
              key={key}
              onPress={() => onSelectDay(key)}
              disabled={!status}
              style={{ width: `${100 / 7}%`, height: 46, alignItems: 'center', justifyContent: 'center' }}
            >
              <View
                style={{
                  width: 32,
                  height: 32,
                  borderRadius: 16,
                  alignItems: 'center',
                  justifyContent: 'center',
                  backgroundColor: isToday ? colors.accent : 'transparent',
                }}
              >
                <AppText
                  variant="label"
                  style={{ color: isToday ? colors.onAccent : status ? colors.text : colors.textFaint }}
                >
                  {day}
                </AppText>
              </View>
              <View
                style={{
                  width: 6,
                  height: 6,
                  borderRadius: 3,
                  marginTop: 2,
                  backgroundColor: meta ? meta.color : 'transparent',
                }}
              />
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}
