import { StyleSheet, View } from 'react-native';

import { ProgressBar } from '@/components/ui/progress-bar';
import { AppText } from '@/components/ui/text';
import { groupOf } from '@/features/services/catalog';
import { useGlyphInk } from '@/features/services/components/service-glyph';
import { parseDateOnly } from '@/lib/format';
import { useTheme } from '@/theme/theme';

import { seatsLabel } from '../meta';

/** Up to this many seats are drawn one by one; past it, a bar reads better. */
const SEAT_DOTS = 30;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Learning's inks (ADR 0094), the same the service tiles are drawn in. */
export function useLearningInk() {
  return useGlyphInk(groupOf('learning').hue);
}

/**
 * The seats on a program: one capsule a seat for a small cohort, taken ones in
 * Learning's jade, so "3 of 12 left" is seen before it is read. A large one is a
 * bar; an uncapped one says so.
 */
export function SeatMeter({ capacity, left }: { capacity: number | null; left: number | null }) {
  const { colors, readable } = useTheme();
  const ink = useLearningInk();
  const label = seatsLabel(capacity, left);

  if (capacity === null || left === null) {
    return (
      <AppText variant="footnote" tone="secondary">
        {label}
      </AppText>
    );
  }

  const taken = capacity - left;
  const tone = left === 0 ? colors.textTertiary : readable(ink.fill, colors.card, 3);

  return (
    <View style={styles.seats} accessible accessibilityLabel={label}>
      {capacity <= SEAT_DOTS && capacity > 0 ? (
        <View style={styles.dots}>
          {Array.from({ length: capacity }, (_, i) => (
            <View key={i} style={[styles.dot, { backgroundColor: i < taken ? tone : colors.fill }]} />
          ))}
        </View>
      ) : (
        <ProgressBar value={capacity > 0 ? taken / capacity : 1} color={tone} height={6} />
      )}
      <AppText variant="footnote" tone={left === 0 ? 'secondary' : 'primary'} weight={left === 0 ? 'regular' : 'medium'} numeric>
        {label}
      </AppText>
    </View>
  );
}

/**
 * A program's first day as a leaf torn off a desk calendar, washed in
 * Learning's jade: the month over the day. With no date yet, a dash.
 */
export function DateLeaf({ date, size = 48 }: { date: string | null; size?: number }) {
  const { colors, scheme, readable } = useTheme();
  const ink = useLearningInk();
  const d = date ? parseDateOnly(date) : null;
  // The month is type on the leaf's head: by day the jade walks down until white clears 4.5:1.
  const head = scheme === 'dark' ? ink.fill : readable(ink.fill, '#FFFFFF', 4.5);
  const onHead = scheme === 'dark' ? ink.paper : '#FFFFFF';

  return (
    <View style={[styles.leaf, { width: size, borderRadius: size * 0.26, backgroundColor: ink.wash }]}>
      <View style={[styles.leafHead, { backgroundColor: head }]}>
        <AppText variant="caption2" weight="semibold" color={onHead} maxFontSizeMultiplier={1.1}>
          {d ? MONTHS[d.getMonth()] : 'Date'}
        </AppText>
      </View>
      <AppText variant="title3" numeric color={d ? colors.text : colors.textSecondary} maxFontSizeMultiplier={1.1} style={styles.leafDay}>
        {d ? d.getDate() : '–'}
      </AppText>
    </View>
  );
}

const styles = StyleSheet.create({
  seats: { gap: 7 },
  dots: { flexDirection: 'row', flexWrap: 'wrap', gap: 3 },
  dot: { width: 9, height: 9, borderRadius: 3, borderCurve: 'continuous' },
  leaf: { overflow: 'hidden', alignItems: 'center', borderCurve: 'continuous' },
  leafHead: { alignSelf: 'stretch', alignItems: 'center', paddingVertical: 2 },
  leafDay: { paddingVertical: 3 },
});
