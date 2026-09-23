import { Image } from 'expo-image';

import { useTheme } from '@/theme/theme';

const MARK = require('@/assets/images/synapse-mark.png');
const MARK_REVERSED = require('@/assets/images/synapse-mark-reversed.png');

/** Which ground the mark is being placed on. `auto` follows the scheme; the entry
 *  screens pass `light`, because their ground is white whatever the phone is set to. */
type Surface = 'auto' | 'light' | 'dark';

type LogoProps = {
  /** Width in points; the height follows the mark's own 4:3 proportion. */
  width?: number;
  surface?: Surface;
};

/**
 * The SYNAPSE mark.
 *
 * Two colourways, because about 60% of the artwork is deep navy and vanishes on a dark
 * ground: the original for light surfaces, and a reversed one — white figure, teal
 * network — for dark mode. Landscape (about 4:3), so it is sized by width and never
 * squeezed into a square.
 */
export function Logo({ width = 160, surface = 'auto' }: LogoProps) {
  const { scheme } = useTheme();
  const onDark = surface === 'dark' || (surface === 'auto' && scheme === 'dark');

  return (
    <Image
      source={onDark ? MARK_REVERSED : MARK}
      style={{ width, height: width * (291 / 384) }}
      contentFit="contain"
      accessibilityLabel="SYNAPSE"
      transition={200}
    />
  );
}
