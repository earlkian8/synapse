import { useEffect, useRef, useState } from 'react';
import { useReducedMotion } from 'react-native-reanimated';
import Svg, { Ellipse, G, Path } from 'react-native-svg';

import { useTheme } from '@/theme/theme';

import { MOOD_INK, MOOD_WORDS, moodColor } from '../meta';

/**
 * A face that wears a mood (ADR 0102), the web's drawing on the phone. Every feature
 * is a function of the mood from 1 (rough) to 5 (great): the body sags or lifts, the
 * brows tilt, the eyes soften into a smile, the mouth curves, cheeks warm, and the
 * colour moves from "feeling blue" to "sunny". It eases between answers, and holds
 * still when the phone asks for less motion. With no mood it waits, in outline.
 *
 * It only ever shows the person's mood answer: a face that frowned at "I often think
 * about leaving" would push people toward the answers that please it.
 */
export function MoodFace({ mood, size = 96, label }: { mood: number | null; size?: number; label?: string }) {
  const { scheme, colors } = useTheme();
  const value = useEased(mood ?? 3);
  const waiting = mood === null;
  const v = (value - 3) / 2;

  const top = 16 - v * 3;
  const bottom = 106 + v * 1.5;
  const waist = 12 - v * 3;
  const body = `M60 ${top} C${92 + waist * 0.2} ${top} 108 ${36 - v * 2} 108 62 C108 ${88 + waist * 0.4} 90 ${bottom} 60 ${bottom} C30 ${bottom} 12 ${88 + waist * 0.4} 12 62 C12 ${36 - v * 2} ${28 - waist * 0.2} ${top} 60 ${top} Z`;

  const eyeY = 52 + Math.max(0, -v) * 3;
  const eyeRy = Math.max(2.2, 7.5 - Math.max(0, v) * 3.2 - Math.max(0, -v) * 1.5);
  const brow = Math.max(0, -v);
  const browLift = Math.max(0, v);
  const half = 15 + v * 4;
  const mouthY = 78 - v * 1.5;
  const mouth = `M${60 - half} ${mouthY} Q60 ${mouthY + v * 13} ${60 + half} ${mouthY}`;

  const fill = moodColor(value, scheme);
  const ink = value < 1.7 ? '#FFFFFF' : MOOD_INK[scheme];
  const spoken = label ?? (waiting ? 'No answer yet' : MOOD_WORDS[Math.round(value) - 1]);

  return (
    <Svg width={size} height={size} viewBox="0 0 120 120" accessibilityRole="image" accessibilityLabel={spoken}>
      <Path
        d={body}
        fill={waiting ? 'transparent' : fill}
        stroke={waiting ? colors.textTertiary : 'none'}
        strokeWidth={waiting ? 2.5 : 0}
        strokeDasharray={waiting ? '6 7' : undefined}
      />
      {!waiting && (
        <>
          <G stroke={ink} fill="none" strokeLinecap="round" strokeLinejoin="round">
            {brow > 0.05 && (
              <G strokeWidth={3.5} opacity={Math.min(1, brow * 1.6)}>
                <Path d={`M33 ${40 - brow * 2} L49 ${36 - brow * 6}`} />
                <Path d={`M87 ${40 - brow * 2} L71 ${36 - brow * 6}`} />
              </G>
            )}
            {browLift > 0.4 && (
              <G strokeWidth={3} opacity={(browLift - 0.4) * 1.6}>
                <Path d="M34 37 Q42 31 50 36" />
                <Path d="M70 36 Q78 31 86 37" />
              </G>
            )}
            <Path d={mouth} strokeWidth={5.5} />
          </G>
          <G fill={ink}>
            <Ellipse cx={42} cy={eyeY} rx={6} ry={eyeRy} />
            <Ellipse cx={78} cy={eyeY} rx={6} ry={eyeRy} />
          </G>
          {v > 0.15 && (
            <G fill="#FFFFFF" opacity={Math.min(0.42, (v - 0.15) * 0.6)}>
              <Ellipse cx={28} cy={70} rx={8} ry={5} />
              <Ellipse cx={92} cy={70} rx={8} ry={5} />
            </G>
          )}
        </>
      )}
    </Svg>
  );
}

/** `target`, eased toward over ~300 ms; at once when motion is reduced. */
function useEased(target: number): number {
  const reduced = useReducedMotion();
  const [value, setValue] = useState(target);
  const from = useRef(target);

  useEffect(() => {
    const start = from.current;

    if (start === target || reduced) {
      from.current = target;
      const id = requestAnimationFrame(() => setValue(target));
      return () => cancelAnimationFrame(id);
    }

    const began = Date.now();
    let frame = 0;
    const tick = () => {
      const t = Math.min(1, (Date.now() - began) / 300);
      const eased = 1 - Math.pow(1 - t, 3);
      const next = start + (target - start) * eased;
      from.current = next;
      setValue(next);
      if (t < 1) frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [target, reduced]);

  return value;
}
