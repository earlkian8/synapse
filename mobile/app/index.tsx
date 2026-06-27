import { ActivityIndicator, View } from 'react-native';

import { useTheme } from '@/theme/theme';

/** Bridge route shown on cold start while the session is restored; the root
 * navigator redirects away to the auth stack or the app shell. */
export default function Index() {
  const { colors } = useTheme();

  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.brand }}>
      <ActivityIndicator color={colors.accent} size="large" />
    </View>
  );
}
