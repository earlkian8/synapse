import { StyleSheet, View } from 'react-native';

import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';

type SectionProps = {
  title: string;
  /** A trailing action, such as "See All". */
  action?: { label: string; onPress: () => void };
  children: React.ReactNode;
  gap?: number;
};

/**
 * A titled block on a dashboard, the way Health and Fitness title theirs: a bold
 * sentence-case heading with an optional "See All" on the trailing side.
 */
export function Section({ title, action, children, gap = 10 }: SectionProps) {
  return (
    <View style={{ gap }}>
      <View style={styles.header}>
        <AppText variant="title3" accessibilityRole="header">
          {title}
        </AppText>
        {action && (
          <Touchable feedback="opacity" onPress={action.onPress} hitSlop={10} accessibilityRole="button">
            <AppText variant="subheadline" tone="tint" weight="medium">
              {action.label}
            </AppText>
          </Touchable>
        )}
      </View>
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', paddingHorizontal: 2 },
});
