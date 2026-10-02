import { SymbolView, type AndroidSymbol, type SFSymbol, type SymbolWeight } from 'expo-symbols';
import { Platform, type StyleProp, type ViewStyle } from 'react-native';

import { useTheme } from '@/theme/theme';

/**
 * Every glyph the app draws, by what it means. Each one is an SF Symbol on iOS (the
 * system's own icons, which align with San Francisco-style type and scale with it)
 * and the matching Material Symbol on Android and the web, so each platform gets the
 * icons its users already read. Screens ask for `"clockIn"`, not for a file.
 */
const GLYPHS = {
  // Tabs
  home: { ios: 'house', active: 'house.fill', android: 'home' },
  attendance: { ios: 'calendar', active: 'calendar', android: 'calendar_month' },
  clock: { ios: 'clock', active: 'clock.fill', android: 'schedule' },
  leave: { ios: 'doc.text', active: 'doc.text.fill', android: 'description' },
  profile: { ios: 'person.crop.circle', active: 'person.crop.circle.fill', android: 'account_circle' },

  // Navigation and controls
  back: { ios: 'chevron.left', android: 'arrow_back' },
  chevronRight: { ios: 'chevron.right', android: 'chevron_right' },
  chevronLeft: { ios: 'chevron.left', android: 'chevron_left' },
  chevronDown: { ios: 'chevron.down', android: 'expand_more' },
  close: { ios: 'xmark', android: 'close' },
  plus: { ios: 'plus', android: 'add' },
  check: { ios: 'checkmark', android: 'check' },
  checkCircle: { ios: 'checkmark.circle.fill', android: 'check_circle' },
  swap: { ios: 'arrow.left.arrow.right', android: 'swap_horiz' },
  eye: { ios: 'eye', android: 'visibility' },
  eyeSlash: { ios: 'eye.slash', android: 'visibility_off' },
  send: { ios: 'paperplane.fill', android: 'send' },
  retry: { ios: 'arrow.clockwise', android: 'refresh' },

  // Punches
  clockIn: { ios: 'arrow.right.circle.fill', android: 'login' },
  clockOut: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout' },
  breakStart: { ios: 'cup.and.saucer.fill', android: 'coffee' },
  breakEnd: { ios: 'play.fill', android: 'play_arrow' },
  time: { ios: 'clock', android: 'schedule' },
  timer: { ios: 'timer', android: 'timer' },
  location: { ios: 'location.fill', android: 'location_on' },
  locationSlash: { ios: 'location.slash', android: 'location_off' },
  camera: { ios: 'camera.fill', android: 'photo_camera' },
  offline: { ios: 'icloud.slash', android: 'cloud_off' },

  // Records and people
  calendar: { ios: 'calendar', android: 'calendar_month' },
  calendarPlus: { ios: 'calendar.badge.plus', android: 'edit_calendar' },
  hourglass: { ios: 'hourglass', android: 'hourglass_empty' },
  trophy: { ios: 'trophy.fill', android: 'trophy' },
  rosette: { ios: 'rosette', android: 'military_tech' },
  company: { ios: 'building.2.fill', android: 'apartment' },
  grid: { ios: 'square.grid.2x2.fill', android: 'grid_view' },
  person: { ios: 'person.fill', android: 'person' },
  people: { ios: 'person.2.fill', android: 'group' },
  briefcase: { ios: 'briefcase.fill', android: 'work' },
  idCard: { ios: 'person.text.rectangle.fill', android: 'badge' },
  mail: { ios: 'envelope.fill', android: 'mail' },
  phone: { ios: 'phone.fill', android: 'call' },
  pin: { ios: 'mappin.and.ellipse', android: 'location_on' },
  gift: { ios: 'gift.fill', android: 'cake' },
  heart: { ios: 'heart.fill', android: 'favorite' },
  ticket: { ios: 'ticket.fill', android: 'confirmation_number' },
  note: { ios: 'text.bubble.fill', android: 'chat' },
  paid: { ios: 'banknote.fill', android: 'payments' },
  sparkles: { ios: 'sparkles', android: 'auto_awesome' },
  doc: { ios: 'doc.text', android: 'description' },
  lock: { ios: 'lock.fill', android: 'lock' },

  // Status and appearance
  info: { ios: 'info.circle.fill', android: 'info' },
  warning: { ios: 'exclamationmark.triangle.fill', android: 'warning' },
  error: { ios: 'exclamationmark.circle.fill', android: 'error' },
  sun: { ios: 'sun.max.fill', android: 'light_mode' },
  moon: { ios: 'moon.fill', android: 'dark_mode' },
  phoneDevice: { ios: 'iphone', android: 'smartphone' },
  signOut: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout' },
} satisfies Record<string, { ios: SFSymbol; active?: SFSymbol; android: AndroidSymbol }>;

export type IconName = keyof typeof GLYPHS;

type IconProps = {
  name: IconName;
  size?: number;
  color?: string;
  /** The filled variant, where iOS has one (a selected tab). */
  active?: boolean;
  /** iOS only: Material Symbols ships one weight here, to keep the bundle small. */
  weight?: SymbolWeight;
  style?: StyleProp<ViewStyle>;
};

export function Icon({ name, size = 20, color, active, weight = 'medium', style }: IconProps) {
  const { colors } = useTheme();
  const glyph: { ios: SFSymbol; active?: SFSymbol; android: AndroidSymbol } = GLYPHS[name];

  return (
    <SymbolView
      name={{
        ios: active && glyph.active ? glyph.active : glyph.ios,
        android: glyph.android,
        web: glyph.android,
      }}
      size={size}
      tintColor={color ?? colors.text}
      weight={Platform.OS === 'ios' ? weight : undefined}
      resizeMode="scaleAspectFit"
      style={style}
      importantForAccessibility="no"
      accessibilityElementsHidden
    />
  );
}
