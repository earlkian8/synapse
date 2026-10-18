import { useEffect, useState } from 'react';
import { Platform, StyleSheet, View } from 'react-native';
import Animated, { Extrapolation, interpolate, useAnimatedStyle, useSharedValue, withDelay, withSpring } from 'react-native-reanimated';
import Svg, { Circle, G, Path, Rect } from 'react-native-svg';

import { AppText } from '@/components/ui/text';
import { groupOf } from '@/features/services/catalog';
import { formatDate } from '@/lib/format';
import { springs } from '@/lib/motion';
import { composite, withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';
import { status } from '@/theme/tokens';
import type { CertificateStanding } from '@/types/api';

import { STANDING } from '../meta';

/**
 * A training certificate, drawn as the document it stands for (ADR 0094): an
 * engraved frame in Learning's jade, the holder's name set in a book face, and
 * a gold seal on its ribbons. It is the one place in the app the type is not
 * Inter, because a certificate is the one thing in it meant to look printed.
 *
 * A lapsed or renewed certificate wears a stamp across it, so its standing is
 * read before anything else on it. Everything is drawn to the width it is given
 * and scales with it; the text does not follow the phone's text size, because
 * the sheet's layout is the point. Screen readers get the whole of it as one label.
 */

/** A4 landscape. */
const RATIO = 1.414;
/** The width everything below is drawn for; other widths scale from it. */
const BASE = 360;

/** The seal's gold: a body, its shade, and its shine. Gold on either paper. */
const GOLD = { body: '#C9972F', shade: '#8D6516', shine: '#ECC870' };

/** The book face: the system's own serif, so nothing is downloaded for one sheet. */
const SERIF = Platform.select({ ios: 'Georgia', default: 'serif' });

type CertificateArtProps = {
  holder: string;
  name: string;
  issuer: string | null;
  organization: string | null;
  issued: string | null;
  expiry: string | null;
  number: string | null;
  standing: CertificateStanding;
  /** Bring the seal down onto the sheet once, as it first appears. */
  stampIn?: boolean;
};

function useCertificateInk() {
  const { colors, scheme, readable } = useTheme();
  const hue = groupOf('learning').hue;
  const dark = scheme === 'dark';
  const paper = dark ? composite(hue, 0.12, '#0D0F0E') : '#FFFFFF';

  return {
    paper,
    ink: dark ? '#F2F5F3' : colors.text,
    quiet: dark ? 'rgba(242, 245, 243, 0.66)' : colors.textSecondary,
    frame: readable(hue, paper, 3),
    accent: readable(hue, paper, 4.5),
    dark,
  };
}

/** A sine wave along a straight run, for the engraved band of the frame. */
function wave(x0: number, y0: number, x1: number, y1: number, amplitude: number, length: number, phase: number): string {
  const dx = x1 - x0;
  const dy = y1 - y0;
  const run = Math.hypot(dx, dy);
  const [ux, uy] = [dx / run, dy / run];
  // The normal, turned a quarter towards the inside of a clockwise frame.
  const [nx, ny] = [-uy, ux];
  const steps = Math.max(8, Math.round(run / 2));
  const points: string[] = [];

  for (let i = 0; i <= steps; i++) {
    const t = (run * i) / steps;
    const offset = amplitude * Math.sin((2 * Math.PI * t) / length + phase);
    points.push(`${(x0 + ux * t + nx * offset).toFixed(2)} ${(y0 + uy * t + ny * offset).toFixed(2)}`);
  }

  return `M${points.join(' L')}`;
}

/** A star's outline, the same construction as the service drawings. */
function star(cx: number, cy: number, outer: number, inner: number, points: number): string {
  const corners = Array.from({ length: points * 2 }, (_, i) => {
    const radius = i % 2 === 0 ? outer : inner;
    const angle = (Math.PI * i) / points - Math.PI / 2;
    return `${(cx + radius * Math.cos(angle)).toFixed(2)} ${(cy + radius * Math.sin(angle)).toFixed(2)}`;
  });
  return `M${corners.join(' L')} Z`;
}

/** The frame: a fine rule, an engraved band of two crossing waves, and an inner rule. */
function Frame({ width, height, s, color, paper }: { width: number; height: number; s: number; color: string; paper: string }) {
  const band = 19 * s;
  const corners: [number, number][] = [
    [band, band],
    [width - band, band],
    [width - band, height - band],
    [band, height - band],
  ];
  const edges = corners.map((from, i) => [from, corners[(i + 1) % 4]] as const);
  const amplitude = 3.2 * s;
  const length = 11 * s;

  return (
    <>
      <Rect x={9 * s} y={9 * s} width={width - 18 * s} height={height - 18 * s} rx={6 * s} stroke={color} strokeWidth={1.2} fill="none" />
      {edges.map(([[x0, y0], [x1, y1]], i) => (
        <G key={i}>
          <Path d={wave(x0, y0, x1, y1, amplitude, length, 0)} stroke={withAlpha(color, 0.55)} strokeWidth={0.8} fill="none" />
          <Path d={wave(x0, y0, x1, y1, amplitude, length, Math.PI)} stroke={withAlpha(color, 0.55)} strokeWidth={0.8} fill="none" />
        </G>
      ))}
      <Rect x={29 * s} y={29 * s} width={width - 58 * s} height={height - 58 * s} rx={3 * s} stroke={withAlpha(color, 0.5)} strokeWidth={0.8} fill="none" />
      {/* A rosette at each corner, where the waves turn. */}
      {corners.map(([x, y], i) => (
        <G key={i}>
          <Circle cx={x} cy={y} r={6.5 * s} fill={paper} stroke={color} strokeWidth={1} />
          <Path d={star(x, y, 4.2 * s, 2 * s, 8)} fill={withAlpha(color, 0.8)} />
        </G>
      ))}
    </>
  );
}

/** The gold seal on its two ribbon tails, drawn about its own centre. */
function Seal({ size, paper }: { size: number; paper: string }) {
  const c = size / 2;
  const r = size * 0.36;

  return (
    <Svg width={size} height={size * 1.3}>
      <Path d={`M${c - r * 0.55} ${c + r * 0.5} L${c - r * 0.95} ${size * 1.26} L${c - r * 0.32} ${size * 1.12} L${c + r * 0.05} ${size * 1.29} L${c + r * 0.1} ${c + r * 0.7} Z`} fill={GOLD.shade} />
      <Path d={`M${c + r * 0.55} ${c + r * 0.5} L${c + r * 0.95} ${size * 1.26} L${c + r * 0.32} ${size * 1.12} L${c - r * 0.05} ${size * 1.29} L${c - r * 0.1} ${c + r * 0.7} Z`} fill={GOLD.shade} />
      <Path d={star(c, c, r * 1.32, r * 1.16, 24)} fill={GOLD.body} />
      <Circle cx={c} cy={c} r={r * 0.98} fill="none" stroke={GOLD.shine} strokeWidth={1} />
      <Circle cx={c} cy={c} r={r * 0.82} fill={GOLD.shade} />
      <Path d={star(c, c, r * 0.5, r * 0.21, 5)} fill={GOLD.shine} />
      <Circle cx={c} cy={c} r={r * 0.68} fill="none" stroke={withAlpha(paper, 0.35)} strokeWidth={0.8} strokeDasharray="1.5 2" />
    </Svg>
  );
}

/** The stamp across a certificate that no longer stands: expired, or renewed since. */
function Stamp({ standing, s, paper }: { standing: CertificateStanding; s: number; paper: string }) {
  const { readable } = useTheme();
  const tone = readable(standing === 'expired' ? status.absent : status.rest, paper, 4.5);

  return (
    <View pointerEvents="none" style={[styles.stamp, { top: 44 * s, right: 34 * s, transform: [{ rotate: '-11deg' }] }]}>
      <View style={[styles.stampOuter, { borderColor: tone, borderRadius: 7 * s, padding: 2 * s, backgroundColor: withAlpha(paper, 0.92) }]}>
        <View style={[styles.stampInner, { borderColor: tone, borderRadius: 5 * s, paddingHorizontal: 10 * s, paddingVertical: 3 * s }]}>
          <AppText allowFontScaling={false} weight="bold" color={tone} style={{ fontSize: 15 * s, lineHeight: 19 * s, letterSpacing: 1.6 * s }}>
            {standing === 'expired' ? 'Expired' : 'Renewed'}
          </AppText>
        </View>
      </View>
    </View>
  );
}

export function CertificateArt({ holder, name, issuer, organization, issued, expiry, number, standing, stampIn }: CertificateArtProps) {
  const ink = useCertificateInk();
  const [width, setWidth] = useState(0);
  const height = width / RATIO;
  const s = width / BASE;
  const sealSize = 52 * s;

  // The one moment on the screen: the seal comes down onto the sheet and settles.
  const seal = useSharedValue(stampIn ? 0 : 1);
  useEffect(() => {
    if (!stampIn) return;
    seal.set(withDelay(260, withSpring(1, springs.pop)));
  }, [stampIn, seal]);
  const sealStyle = useAnimatedStyle(() => ({
    opacity: interpolate(seal.get(), [0, 0.2], [0, 1], Extrapolation.CLAMP),
    transform: [{ scale: 1.6 - 0.6 * seal.get() }, { rotate: `${(1 - seal.get()) * -18}deg` }],
  }));

  const text = (size: number, line: number) => ({ fontSize: size * s, lineHeight: line * s });
  const lapsed = standing === 'expired' || standing === 'renewed';

  return (
    <View
      onLayout={(event) => setWidth(event.nativeEvent.layout.width)}
      accessible
      accessibilityRole="image"
      accessibilityLabel={[
        `Certificate of completion, ${name}`,
        `awarded to ${holder}`,
        issuer ? `by ${issuer}` : null,
        issued ? `issued ${formatDate(issued)}` : null,
        expiry ? `valid until ${formatDate(expiry)}` : 'never expires',
        number ? `number ${number}` : null,
        lapsed ? (standing === 'expired' ? 'expired' : 'renewed since') : null,
      ]
        .filter(Boolean)
        .join(', ')}
      style={[
        styles.sheet,
        {
          height: height || undefined,
          aspectRatio: width ? undefined : RATIO,
          backgroundColor: ink.paper,
          borderRadius: 14 * s || 14,
          boxShadow: ink.dark ? undefined : '0 1px 2px rgba(0, 0, 0, 0.05), 0 10px 28px rgba(16, 60, 34, 0.10)',
        },
      ]}
    >
      {width > 0 && (
        <>
          <Svg width={width} height={height} style={StyleSheet.absoluteFill}>
            <Frame width={width} height={height} s={s} color={ink.frame} paper={ink.paper} />
          </Svg>

          <View style={[styles.body, { paddingHorizontal: 44 * s, paddingTop: 36 * s }]}>
            <AppText allowFontScaling={false} weight="semibold" color={ink.accent} center numberOfLines={1} style={[text(9.5, 12), { letterSpacing: 0.6 * s }]}>
              {organization ?? issuer ?? ' '}
            </AppText>
            <AppText allowFontScaling={false} center color={ink.ink} style={[text(19, 24), { fontFamily: SERIF, fontStyle: 'italic', marginTop: 3 * s }]}>
              Certificate of completion
            </AppText>
            <AppText allowFontScaling={false} center color={ink.quiet} style={[text(9, 12), { marginTop: 5 * s }]}>
              awarded to
            </AppText>
            <AppText allowFontScaling={false} center color={ink.ink} numberOfLines={1} adjustsFontSizeToFit style={[text(23, 29), { fontFamily: SERIF, marginTop: 1 * s }]}>
              {holder}
            </AppText>
            <View style={[styles.rule, { backgroundColor: withAlpha(ink.frame, 0.45), width: 150 * s, marginVertical: 4 * s }]} />
            <AppText allowFontScaling={false} center color={ink.quiet} style={text(9, 12)}>
              for completing
            </AppText>
            <AppText allowFontScaling={false} center weight="semibold" color={ink.ink} numberOfLines={2} style={[text(12.5, 16), { marginTop: 2 * s }]}>
              {name}
            </AppText>
          </View>

          {/* The foot: when it was earned, until when it stands, and its number. */}
          <View style={[styles.foot, { left: 44 * s, bottom: 36 * s, gap: 14 * s }]}>
            <FootItem label="Issued" value={issued ? formatDate(issued) : '—'} s={s} ink={ink} />
            <FootItem label={expiry ? 'Valid until' : 'Validity'} value={expiry ? formatDate(expiry) : 'Never expires'} s={s} ink={ink} />
            {number && <FootItem label="No." value={number} s={s} ink={ink} />}
          </View>

          {/* The seal, applied over the frame's corner. */}
          <Animated.View pointerEvents="none" style={[styles.seal, { left: width - 76 * s, top: height - 78 * s }, sealStyle]}>
            <Seal size={sealSize} paper={ink.paper} />
          </Animated.View>

          {lapsed && <Stamp standing={standing} s={s} paper={ink.paper} />}
        </>
      )}
    </View>
  );
}

type Ink = ReturnType<typeof useCertificateInk>;

function FootItem({ label, value, s, ink }: { label: string; value: string; s: number; ink: Ink }) {
  return (
    <View style={styles.footItem}>
      <AppText allowFontScaling={false} color={ink.quiet} style={{ fontSize: 8 * s, lineHeight: 10 * s }}>
        {label}
      </AppText>
      <AppText allowFontScaling={false} weight="medium" color={ink.ink} numeric numberOfLines={1} style={{ fontSize: 9.5 * s, lineHeight: 13 * s }}>
        {value}
      </AppText>
    </View>
  );
}

/**
 * A certificate small enough to lead a row: the sheet, its frame and its seal,
 * and nothing to read. The row beside it says what it is.
 */
export function CertificateThumb({ standing, width = 52, badge }: { standing: CertificateStanding; width?: number; badge?: boolean }) {
  const ink = useCertificateInk();
  const { colors, readable } = useTheme();
  const height = Math.round(width / RATIO);
  const lapsed = standing === 'expired' || standing === 'renewed';
  const tone = readable(STANDING[standing].color, colors.card, 3);

  return (
    <View style={{ width, height }}>
      <View style={{ opacity: lapsed ? 0.55 : 1 }}>
      <Svg width={width} height={height}>
        <Rect x={0.5} y={0.5} width={width - 1} height={height - 1} rx={5} fill={ink.paper} stroke={withAlpha(ink.frame, ink.dark ? 0.9 : 0.6)} strokeWidth={1} />
        <Rect x={3.5} y={3.5} width={width - 7} height={height - 7} rx={2.5} fill="none" stroke={withAlpha(ink.frame, 0.45)} strokeWidth={0.8} strokeDasharray="1.6 1.4" />
        <Rect x={width * 0.25} y={height * 0.24} width={width * 0.5} height={2} rx={1} fill={withAlpha(ink.ink, 0.55)} />
        <Rect x={width * 0.32} y={height * 0.24 + 5} width={width * 0.36} height={1.6} rx={0.8} fill={withAlpha(ink.ink, 0.3)} />
        <Path d={star(width / 2, height * 0.68, 6, 5.1, 16)} fill={GOLD.body} />
        <Circle cx={width / 2} cy={height * 0.68} r={3.4} fill={GOLD.shade} />
      </Svg>
      </View>
      {/* Its standing at a glance, in the tone the pill on the certificate uses. */}
      {badge && <View style={[styles.badge, { backgroundColor: tone, borderColor: colors.card }]} />}
    </View>
  );
}

const styles = StyleSheet.create({
  sheet: { width: '100%', overflow: 'hidden', borderCurve: 'continuous' },
  body: { alignItems: 'center' },
  rule: { height: StyleSheet.hairlineWidth * 2, alignSelf: 'center' },
  foot: { position: 'absolute', flexDirection: 'row', alignItems: 'flex-end' },
  footItem: { gap: 1 },
  seal: { position: 'absolute' },
  stamp: { position: 'absolute' },
  badge: { position: 'absolute', right: -4, top: -4, width: 12, height: 12, borderRadius: 6, borderWidth: 2 },
  stampOuter: { borderWidth: 1.5 },
  stampInner: { borderWidth: 1.5 },
});
