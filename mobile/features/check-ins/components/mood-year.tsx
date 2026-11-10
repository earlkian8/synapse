import { StyleSheet, View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';
import type { MyCheckIn } from '@/types/api';

import { monthInitial, monthName, MOOD_WORDS } from '../meta';
import { MoodFace } from './mood-face';

/**
 * Your year in moods (ADR 0102): twelve faces, oldest first, all twelve across the
 * card so the latest months are never off the edge; a month without a check-in in
 * outline. Tapping a month opens what was answered then.
 */
export function MoodYear({
  months,
  history,
  selected,
  current,
  onSelect,
}: {
  months: string[];
  history: MyCheckIn[];
  selected: string | null;
  current: string;
  onSelect: (period: string) => void;
}) {
  const { colors } = useTheme();
  const byPeriod = Object.fromEntries(history.map((c) => [c.period, c]));

  return (
    <View style={styles.row}>
      {months.map((period) => {
        const checkIn = byPeriod[period];
        const on = selected === period;
        return (
          <Touchable
            key={period}
            onPress={() => checkIn && onSelect(period)}
            disabled={!checkIn}
            haptic="selection"
            scaleTo={0.9}
            accessibilityRole="button"
            accessibilityState={{ selected: on, disabled: !checkIn }}
            accessibilityLabel={
              checkIn ? `${monthName(period, true)}: ${MOOD_WORDS[checkIn.answers.mood - 1]}` : `${monthName(period, true)}: no check-in`
            }
            style={[styles.month, on && { backgroundColor: colors.fill }]}
          >
            <MoodFace mood={checkIn?.answers.mood ?? null} size={24} label="" />
            <AppText variant="caption2" tone={on ? undefined : 'secondary'}>
              {monthInitial(period)}
            </AppText>
            <View style={[styles.dot, { backgroundColor: period === current && !checkIn ? colors.text : 'transparent' }]} />
          </Touchable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row' },
  month: { flex: 1, alignItems: 'center', gap: 4, paddingVertical: 8, borderRadius: 10 },
  dot: { width: 4, height: 4, borderRadius: 2 },
});
