import { View } from 'react-native';
import Svg, { Circle, G, Line, Path, Rect } from 'react-native-svg';

import { composite } from '@/theme/color';
import { useTheme } from '@/theme/theme';

import { SERVICE_GROUPS } from '../catalog';

/**
 * The app's own drawings for its services (ADR 0088). Each is drawn on a 32-unit
 * grid in three inks cut from its group's hue, so a family of services reads as
 * one family and every drawing stays legible in both schemes:
 *
 * - `fill`: the hue itself, for the main shape;
 * - `deep`: the hue walked to 3:1 on the tile (darker by day, lighter at night),
 *   for detail drawn on the tile or on paper;
 * - `paper`: white by day, a near-black of the hue at night, for detail cut out of
 *   a filled shape.
 *
 * Detail never goes deep-on-fill or paper-on-tile, which is what keeps the
 * drawings crisp after dark. An action (a form to fill in) wears a plus.
 */
export type GlyphName =
  | 'clock'
  | 'attendance'
  | 'schedule'
  | 'events'
  | 'holidays'
  | 'leave'
  | 'kudos'
  | 'rosette'
  | 'gift'
  | 'trophy'
  | 'idCard'
  | 'door'
  | 'person'
  | 'lock'
  | 'all';

type Ink = { fill: string; deep: string; paper: string; wash: string };

/** A star's outline: `points` tips at `outer`, valleys at `inner`, the first tip straight up. */
function star(cx: number, cy: number, outer: number, inner: number, points = 5): string {
  const steps = points * 2;
  const corners = Array.from({ length: steps }, (_, i) => {
    const radius = i % 2 === 0 ? outer : inner;
    const angle = (Math.PI * i) / points - Math.PI / 2;
    return `${(cx + radius * Math.cos(angle)).toFixed(2)} ${(cy + radius * Math.sin(angle)).toFixed(2)}`;
  });
  return `M${corners.join(' L')} Z`;
}

/** A heart on a 24-unit box, 20 wide, centred on (12, 12.3); placed with a transform. */
const HEART =
  'M12 21 C5 16.5 2 13 2 8.8 C2 5.9 4.3 3.6 7.1 3.6 C9 3.6 10.9 4.7 12 6.4 C13.1 4.7 15 3.6 16.9 3.6 C19.7 3.6 22 5.9 22 8.8 C22 13 19 16.5 12 21 Z';

function Drawing({ name, ink }: { name: GlyphName; ink: Ink }) {
  const { fill, deep, paper, wash } = ink;
  const line = { stroke: deep, strokeWidth: 2.2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, fill: 'none' };

  switch (name) {
    // A stopwatch: the crown, the case, a white face and two hands.
    case 'clock':
      return (
        <>
          <Rect x={13.5} y={2.5} width={5} height={3.5} rx={1.2} fill={deep} />
          <Circle cx={16} cy={17.5} r={11} fill={fill} />
          <Circle cx={16} cy={17.5} r={7.8} fill={paper} />
          <Path d="M16 12.6 V17.5 L19.4 19.5" {...line} strokeWidth={2} />
          <Circle cx={16} cy={17.5} r={1.4} fill={deep} />
        </>
      );

    // A calendar page with the day ticked off.
    case 'attendance':
      return (
        <>
          <Rect x={5} y={7} width={22} height={21} rx={4} fill={fill} />
          <Rect x={7.5} y={12.5} width={17} height={13} rx={2.5} fill={paper} />
          <Rect x={10} y={4.5} width={2.6} height={5.5} rx={1.3} fill={deep} />
          <Rect x={19.4} y={4.5} width={2.6} height={5.5} rx={1.3} fill={deep} />
          <Path d="M11.6 19.2 L14.6 22.1 L20.4 16.2" {...line} strokeWidth={2.4} />
        </>
      );

    // The week as a roster: shifts as bars, staggered across the days.
    case 'schedule':
      return (
        <>
          <Rect x={4} y={6} width={24} height={21} rx={4} fill={fill} />
          {/* The days, as faint columns behind the shifts. */}
          <Path d="M10 9 V24 M16 9 V24 M22 9 V24" stroke={paper} strokeWidth={1} strokeLinecap="round" opacity={0.35} />
          <Rect x={8} y={10.4} width={11} height={3.4} rx={1.7} fill={paper} />
          <Rect x={12} y={15.2} width={12} height={3.4} rx={1.7} fill={paper} />
          <Rect x={8} y={20} width={8} height={3.4} rx={1.7} fill={paper} />
        </>
      );

    // An admission ticket: notched, perforated, a star on the stub.
    case 'events':
      return (
        <>
          <Rect x={3.5} y={9} width={25} height={15} rx={3} fill={fill} />
          <Circle cx={3.5} cy={16.5} r={2.6} fill={wash} />
          <Circle cx={28.5} cy={16.5} r={2.6} fill={wash} />
          <Line x1={20.5} y1={11.2} x2={20.5} y2={22} stroke={paper} strokeWidth={1.6} strokeDasharray="2 2.2" strokeLinecap="round" />
          <Path d={star(12.2, 16.7, 4.4, 1.9)} fill={paper} />
        </>
      );

    // The sun on the horizon, over the water.
    case 'holidays':
      return (
        <>
          <Path d="M8.5 19.5 A7.5 7.5 0 0 1 23.5 19.5 Z" fill={fill} />
          <Path d="M16 6 V8.6 M7.2 10.3 L9 12.1 M24.8 10.3 L23 12.1" {...line} strokeWidth={2} />
          <Path d="M4.5 19.5 H27.5" {...line} strokeWidth={2} />
          <Path d="M7 24 q2.25 -1.6 4.5 0 t4.5 0 t4.5 0 t4.5 0" stroke={fill} strokeWidth={2} strokeLinecap="round" fill="none" />
          <Path d="M11 27.6 q2.5 -1.6 5 0 t5 0" stroke={fill} strokeWidth={2} strokeLinecap="round" fill="none" opacity={0.6} />
        </>
      );

    // A suitcase, strapped and ready.
    case 'leave':
      return (
        <>
          <Path d="M12.5 10.5 V8 a2 2 0 0 1 2 -2 h3 a2 2 0 0 1 2 2 V10.5" {...line} />
          <Rect x={5} y={10.5} width={22} height={16} rx={3.5} fill={fill} />
          <Rect x={10.4} y={10.5} width={2.4} height={16} fill={paper} />
          <Rect x={19.2} y={10.5} width={2.4} height={16} fill={paper} />
        </>
      );

    // A thank-you: a heart in a speech bubble.
    case 'kudos':
      return (
        <>
          <Path
            d="M8 5.5 h16 a4 4 0 0 1 4 4 v9.5 a4 4 0 0 1 -4 4 h-9 l-5.2 4.5 v-4.5 h-1.8 a4 4 0 0 1 -4 -4 v-9.5 a4 4 0 0 1 4 -4 Z"
            fill={fill}
          />
          <G transform="translate(9.4 7.6) scale(0.55)">
            <Path d={HEART} fill={paper} />
          </G>
        </>
      );

    // A rosette: a scalloped medal on two ribbon tails.
    case 'rosette':
      return (
        <>
          <Path d="M11.6 18.5 L9.2 27.6 L12.6 26.1 L14.4 28.6 L16.4 20.5 Z" fill={deep} />
          <Path d="M20.4 18.5 L22.8 27.6 L19.4 26.1 L17.6 28.6 L15.6 20.5 Z" fill={deep} />
          <Path d={star(16, 12.8, 9.4, 8.2, 12)} fill={fill} />
          <Circle cx={16} cy={12.8} r={5.4} fill={paper} />
          <Path d={star(16, 13, 3.4, 1.45)} fill={deep} />
        </>
      );

    // A gift: a box, its lid, a ribbon and a bow.
    case 'gift':
      return (
        <>
          <Path d="M16 10.6 C13.4 5.4 9.2 5.8 10.3 8.6 C11 10.3 13.8 10.6 16 10.6 Z" fill={fill} />
          <Path d="M16 10.6 C18.6 5.4 22.8 5.8 21.7 8.6 C21 10.3 18.2 10.6 16 10.6 Z" fill={fill} />
          <Rect x={6.5} y={15} width={19} height={12.5} rx={2.2} fill={fill} />
          <Rect x={5} y={10.5} width={22} height={5.6} rx={1.8} fill={deep} />
          <Rect x={14.6} y={10.5} width={2.8} height={17} fill={paper} />
        </>
      );

    // A trophy with a star on the cup.
    case 'trophy':
      return (
        <>
          <Path d="M10 8.5 H7.6 a1.6 1.6 0 0 0 -1.6 1.6 v0.9 a4.6 4.6 0 0 0 4.6 4.6" {...line} strokeWidth={2} />
          <Path d="M22 8.5 H24.4 a1.6 1.6 0 0 1 1.6 1.6 v0.9 a4.6 4.6 0 0 1 -4.6 4.6" {...line} strokeWidth={2} />
          <Path d="M9.5 5.5 H22.5 V12 A6.5 6.5 0 0 1 9.5 12 Z" fill={fill} />
          <Rect x={14.8} y={18} width={2.4} height={4.4} fill={deep} />
          <Rect x={10.5} y={22} width={11} height={4.6} rx={1.6} fill={deep} />
          <Path d={star(16, 11.2, 3.3, 1.4)} fill={paper} />
        </>
      );

    // An ID card on its clip: the employee record.
    case 'idCard':
      return (
        <>
          <Rect x={4} y={8} width={24} height={17.5} rx={3.5} fill={fill} />
          <Rect x={13} y={5} width={6} height={4.6} rx={1.5} fill={deep} />
          <Circle cx={11.2} cy={14.7} r={2.7} fill={paper} />
          <Path d="M6.9 21.9 a4.3 4.3 0 0 1 8.6 0 Z" fill={paper} />
          <Rect x={18} y={13.2} width={7} height={2.1} rx={1.05} fill={paper} />
          <Rect x={18} y={17.6} width={5} height={2.1} rx={1.05} fill={paper} />
        </>
      );

    // A door left open, and the way out.
    case 'door':
      return (
        <>
          <Rect x={6} y={4.5} width={12.5} height={23} rx={1.8} fill={deep} />
          <Path d="M6 4.5 L14.2 6.8 V29 L6 27.5 Z" fill={fill} />
          <Circle cx={11.8} cy={17.6} r={1.1} fill={paper} />
          <Path d="M20.5 16.5 H27 M24 13.5 L27 16.5 L24 19.5" {...line} stroke={fill} />
        </>
      );

    // The person behind the account.
    case 'person':
      return (
        <>
          <Circle cx={16} cy={16} r={11.5} fill={fill} />
          <Circle cx={16} cy={12.8} r={4.1} fill={paper} />
          <Path
            d="M8.4 23.4 C9.9 20 12.7 18.6 16 18.6 C19.3 18.6 22.1 20 23.6 23.4 C21.6 25.9 18.9 27.3 16 27.3 C13.1 27.3 10.4 25.9 8.4 23.4 Z"
            fill={paper}
          />
        </>
      );

    // A padlock.
    case 'lock':
      return (
        <>
          <Path d="M11 14 V10.6 a5 5 0 0 1 10 0 V14" {...line} strokeWidth={2.4} />
          <Rect x={7} y={13.5} width={18} height={14} rx={3.5} fill={fill} />
          <Circle cx={16} cy={19.3} r={2.1} fill={paper} />
          <Rect x={15.1} y={19.5} width={1.8} height={4.2} rx={0.9} fill={paper} />
        </>
      );

    // Everything: one square for each family of services, in its own hue.
    case 'all':
      return (
        <>
          {SERVICE_GROUPS.slice(0, 4).map((group, index) => (
            <Rect
              key={group.key}
              x={index % 2 === 0 ? 5.5 : 17}
              y={index < 2 ? 5.5 : 17}
              width={9.5}
              height={9.5}
              rx={3}
              fill={group.hue}
            />
          ))}
        </>
      );
  }
}

/** The three inks and the tile's wash for a hue, in the current scheme. */
export function useGlyphInk(hue: string): Ink {
  const { colors, scheme, readable } = useTheme();
  const dark = scheme === 'dark';
  const wash = composite(hue, dark ? 0.22 : 0.13, colors.card);

  // At night a deep hue (indigo, slate) sinks into its own wash, so the shape is
  // lifted to 3:1 and its detail further still. Bright hues already clear it.
  return {
    fill: dark ? readable(hue, wash, 3) : hue,
    deep: readable(hue, wash, dark ? 4.5 : 3),
    paper: scheme === 'dark' ? composite(hue, 0.2, '#0E0E10') : '#FFFFFF',
    wash,
  };
}

type ServiceIconProps = {
  glyph: GlyphName;
  hue: string;
  /** The tile's side, in points. The drawing takes 62% of it. */
  size?: number;
  /** A form to fill in, rather than a place to go: a plus at the corner. */
  action?: boolean;
  /** A neutral tile, for the "all services" tile that belongs to no one family. */
  neutral?: boolean;
};

/**
 * A service's tile: its drawing on a squircle washed in its group's hue. Drawn,
 * not an icon font, so it scales cleanly from a 30pt list row to a 56pt grid.
 */
export function ServiceIcon({ glyph, hue, size = 54, action, neutral }: ServiceIconProps) {
  const { colors } = useTheme();
  const ink = useGlyphInk(hue);
  const wash = neutral ? colors.fill : ink.wash;
  const art = size * 0.62;

  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.3,
        borderCurve: 'continuous',
        backgroundColor: wash,
        alignItems: 'center',
        justifyContent: 'center',
      }}
    >
      <Svg width={art} height={art} viewBox="0 0 32 32">
        <Drawing name={glyph} ink={{ ...ink, wash }} />
        {action && (
          <>
            <Circle cx={25.4} cy={25.4} r={5.6} fill={ink.deep} stroke={wash} strokeWidth={2} />
            <Path d="M25.4 22.7 V28.1 M22.7 25.4 H28.1" stroke={ink.paper} strokeWidth={1.9} strokeLinecap="round" />
          </>
        )}
      </Svg>
    </View>
  );
}
