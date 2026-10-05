import { StyleSheet, View } from 'react-native';
import Animated, { FadeIn, FadeOut, ZoomIn } from 'react-native-reanimated';

import { Icon } from '@/components/ui/icon';
import { Material } from '@/components/ui/material';
import { AppText } from '@/components/ui/text';
import { PUNCH_META } from '@/features/attendance/punch-meta';
import { springs } from '@/lib/motion';
import { onColor } from '@/theme/color';
import { useTheme } from '@/theme/theme';
import type { PunchType } from '@/types/api';

/**
 * The moment a punch lands: the screen frosts over and a check in the punch's own
 * colour springs into place with the time it was taken, then all of it fades away.
 * The one place in the app where a spring is allowed to overshoot.
 */
export function PunchSuccess({ punch, time, queued }: { punch: PunchType; time: string; queued?: boolean }) {
  const { colors, readable } = useTheme();

  // The punch's colour, pulled to a shade a tick can sit on: a white tick on a raw
  // system green is 2.2:1.
  const disc = readable(PUNCH_META[punch].color, colors.background, 4.5);

  return (
    <Animated.View
      entering={FadeIn.duration(180)}
      exiting={FadeOut.duration(260)}
      pointerEvents="none"
      style={StyleSheet.absoluteFill}
      accessibilityLiveRegion="assertive"
      accessibilityLabel={`${PUNCH_META[punch].label} ${queued ? 'saved' : 'recorded'} at ${time}`}
    >
      <Material style={StyleSheet.absoluteFill} opacity={0.9} />
      <View style={styles.center}>
        <Animated.View
          entering={ZoomIn.springify()
            .damping(springs.pop.damping)
            .stiffness(springs.pop.stiffness)
            .mass(springs.pop.mass)}
          style={[styles.disc, { backgroundColor: disc, boxShadow: `0 12px 32px ${disc}55` }]}
        >
          <Icon name="check" size={52} color={onColor(disc)} weight="bold" />
        </Animated.View>
        <Animated.View entering={FadeIn.delay(120).duration(240)} style={styles.caption}>
          <AppText variant="title2" center>
            {PUNCH_META[punch].label} {queued ? 'saved' : 'recorded'}
          </AppText>
          <AppText variant="subheadline" tone="secondary" center numeric>
            {queued ? `${time} · saved on this phone` : time}
          </AppText>
        </Animated.View>
      </View>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 40, gap: 20 },
  disc: { width: 112, height: 112, borderRadius: 56, alignItems: 'center', justifyContent: 'center' },
  caption: { gap: 4, alignItems: 'center' },
});
