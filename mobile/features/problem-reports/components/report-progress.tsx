import { StyleSheet, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { useTheme } from '@/theme/theme';
import type { ProblemReport } from '@/types/api';

import { STEPS } from '../meta';

/**
 * Where a report stands, as the three steps it moves through — Received, Being
 * looked into, Resolved — with the ones reached filled. A closed report says
 * Closed in the last place, in grey, rather than pretending to be resolved.
 */
export function ReportProgress({ report }: { report: Pick<ProblemReport, 'status'> }) {
  const { colors, status } = useTheme();
  const closed = report.status === 'closed';
  const reached = closed ? 1 : STEPS.findIndex((step) => step.status === report.status);

  return (
    <View
      style={styles.row}
      accessible
      accessibilityLabel={`Where this report stands: ${closed ? 'Closed' : STEPS[reached]?.label ?? ''}`}
    >
      {STEPS.map((step, index) => {
        const last = index === STEPS.length - 1;
        const done = index <= reached;
        const waiting = index === reached && report.status !== 'resolved' && !closed;
        const fill = last && closed ? status.rest : done ? colors.tint : 'transparent';

        return (
          <View key={step.status} style={styles.step}>
            {index > 0 && (
              <View
                style={[
                  styles.line,
                  { backgroundColor: last && closed ? status.rest : done ? colors.tint : colors.separator },
                ]}
              />
            )}
            <View
              style={[
                styles.dot,
                {
                  backgroundColor: fill,
                  borderColor: done || (last && closed) ? fill : colors.separator,
                },
                waiting && { boxShadow: `0 0 0 4px ${colors.tint}33` },
              ]}
            >
              {(done && !waiting) || (last && closed) ? (
                <Icon name="check" size={12} color="#FFFFFF" weight="bold" />
              ) : (
                <AppText variant="caption" weight="semibold" color={done ? '#FFFFFF' : colors.textTertiary}>
                  {index + 1}
                </AppText>
              )}
            </View>
            <AppText
              variant="caption"
              weight={done || (last && closed) ? 'semibold' : 'regular'}
              tone={done || (last && closed) ? 'primary' : 'secondary'}
              center
              numberOfLines={2}
            >
              {last && closed ? 'Closed' : step.label}
            </AppText>
          </View>
        );
      })}
    </View>
  );
}

const DOT = 24;

const styles = StyleSheet.create({
  row: { flexDirection: 'row' },
  step: { flex: 1, alignItems: 'center', gap: 6 },
  line: { position: 'absolute', top: DOT / 2 - 1, right: '50%', width: '100%', height: 2 },
  dot: {
    width: DOT,
    height: DOT,
    borderRadius: DOT / 2,
    borderWidth: 2,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
