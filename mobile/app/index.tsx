import { Ionicons } from '@expo/vector-icons';
import { View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { AppText } from '@/components/ui/text';
import { palette } from '@/theme/tokens';

/** Bridge route shown on cold start while the session is restored; the root
 * navigator redirects away to the auth stack or the app shell. A branded splash
 * (no spinner) keeps the cold-start feeling like the rest of the app. */
export default function Index() {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: palette.navy }}>
      <Animated.View entering={FadeIn.duration(400)} style={{ alignItems: 'center', gap: 16 }}>
        <View
          style={{
            width: 72,
            height: 72,
            borderRadius: 22,
            backgroundColor: palette.teal,
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ionicons name="finger-print" size={38} color={palette.navyDeep} />
        </View>
        <AppText variant="display" style={{ color: palette.white, letterSpacing: 2 }}>
          SYNAPSE
        </AppText>
      </Animated.View>
    </View>
  );
}
