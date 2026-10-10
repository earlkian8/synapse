import { StyleSheet, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useTheme } from '@/theme/theme';
import type { EventAnswer, EventResponse } from '@/types/api';

import { ANSWERS } from './meta';

type Props = {
  value: EventResponse;
  onAnswer: (answer: EventAnswer) => void;
  disabled?: boolean;
  /** The answer being sent, dimmed while it goes. */
  sending?: EventAnswer | null;
  title: string;
};

/**
 * Going, Maybe, Not going — one tap. The chosen answer fills with its colour,
 * the way Calendar shows an invitation you answered.
 */
export function AnswerButtons({ value, onAnswer, disabled, sending, title }: Props) {
  const { colors, readable, radius } = useTheme();

  return (
    <View style={[styles.row, { backgroundColor: colors.fill, borderRadius: radius.md }]} accessibilityRole="radiogroup" accessibilityLabel={`Your answer to ${title}`}>
      {ANSWERS.map((answer) => {
        const chosen = value === answer.value;
        const tone = readable(answer.color, colors.card, 3);
        // Darkened until white type on it reads (4.5:1) — the system green alone is 2:1.
        const fill = readable(answer.color, '#FFFFFF', 4.5);

        return (
          <Touchable
            key={answer.value}
            onPress={() => onAnswer(answer.value)}
            disabled={disabled || sending != null}
            haptic="selection"
            scaleTo={0.96}
            accessibilityRole="radio"
            accessibilityState={{ checked: chosen, disabled: disabled || sending != null }}
            accessibilityLabel={answer.label}
            style={[
              styles.option,
              { borderRadius: radius.md - 3, opacity: sending === answer.value ? 0.6 : disabled && !chosen ? 0.45 : 1 },
              chosen && { backgroundColor: fill },
            ]}
          >
            <Icon name={answer.icon} size={13} color={chosen ? '#FFFFFF' : tone} weight="bold" />
            <AppText variant="subheadline" weight="semibold" color={chosen ? '#FFFFFF' : colors.text} numberOfLines={1}>
              {answer.label}
            </AppText>
          </Touchable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', padding: 3, gap: 3 },
  option: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 5, paddingVertical: 9 },
});
