import { Image } from 'expo-image';
import { View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';

type AvatarProps = {
  uri?: string | null;
  initials?: string;
  size?: number;
};

/**
 * A person: always a circle (a company is a rounded square, see `CompanyLogo`). The
 * photo when there is one, otherwise initials on a wash of teal, as Contacts
 * shows a monogram.
 */
export function Avatar({ uri, initials, size = 44 }: AvatarProps) {
  const { colors } = useTheme();

  return (
    <View
      accessibilityRole="image"
      accessibilityLabel={initials ? `Photo, ${initials}` : 'Photo'}
      style={{
        width: size,
        height: size,
        borderRadius: size / 2,
        backgroundColor: colors.tintSoft,
        alignItems: 'center',
        justifyContent: 'center',
        overflow: 'hidden',
      }}
    >
      {uri ? (
        <Image source={{ uri }} style={{ width: '100%', height: '100%' }} contentFit="cover" transition={250} />
      ) : (
        <AppText
          variant="headline"
          weight="semibold"
          color={colors.tintText}
          maxFontSizeMultiplier={1}
          style={{ fontSize: size * 0.38, lineHeight: size * 0.46 }}
        >
          {(initials ?? '?').slice(0, 2).toUpperCase()}
        </AppText>
      )}
    </View>
  );
}
