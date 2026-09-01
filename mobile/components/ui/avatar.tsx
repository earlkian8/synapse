import { Image } from 'expo-image';
import { View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type AvatarProps = {
  uri?: string | null;
  initials?: string;
  size?: number;
  ring?: boolean;
};

/** Photo avatar with an initials fallback. */
export function Avatar({ uri, initials, size = 44, ring }: AvatarProps) {
  const { colors } = useTheme();

  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size / 2,
        backgroundColor: colors.accentSoft,
        alignItems: 'center',
        justifyContent: 'center',
        overflow: 'hidden',
        borderWidth: ring ? 2 : 0,
        borderColor: colors.accent,
      }}
    >
      {uri ? (
        <Image source={{ uri }} style={{ width: '100%', height: '100%' }} contentFit="cover" transition={200} />
      ) : (
        <AppText variant="label" style={{ color: colors.accent, fontSize: size * 0.36 }}>
          {(initials ?? '?').toUpperCase()}
        </AppText>
      )}
    </View>
  );
}
