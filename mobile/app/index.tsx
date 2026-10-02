import { StyleSheet, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { BrandLockup, EntryScreen } from '@/components/ui/entry-screen';

/** Bridge route shown on cold start while the session is restored; the root
 * navigator redirects away to the auth stack or the app shell. A branded splash
 * (no spinner) on the white entry ground, continuing the native splash before it. */
export default function Index() {
  return (
    <EntryScreen scroll={false}>
      <View style={styles.center}>
        <Animated.View entering={FadeIn.duration(400)}>
          <BrandLockup markWidth={148} />
        </Animated.View>
      </View>
    </EntryScreen>
  );
}

const styles = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
});
