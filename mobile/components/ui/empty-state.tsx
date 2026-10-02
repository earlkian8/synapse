import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Icon, type IconName } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { enter } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

type EmptyStateProps = {
  icon?: IconName;
  title: string;
  message?: string;
  /** An action under the message, such as "File leave". */
  action?: React.ReactNode;
};

/** What a list says when it has nothing to show: a glyph, a line, and what to do next. */
export function EmptyState({ icon = 'sparkles', title, message, action }: EmptyStateProps) {
  const { colors } = useTheme();

  return (
    <Animated.View entering={enter()} style={styles.wrap}>
      <View style={[styles.well, { backgroundColor: colors.fill }]}>
        <Icon name={icon} size={26} color={colors.textSecondary} />
      </View>
      <AppText variant="headline" center>
        {title}
      </AppText>
      {message && (
        <AppText variant="subheadline" tone="secondary" center style={styles.message}>
          {message}
        </AppText>
      )}
      {action && <View style={styles.action}>{action}</View>}
    </Animated.View>
  );
}

/**
 * What a screen says when its data didn't arrive: usually no connection. Says so
 * plainly, and offers the retry instead of leaving a blank page.
 */
export function ErrorState({ message, onRetry }: { message?: string | null; onRetry: () => void }) {
  return (
    <EmptyState
      icon="offline"
      title="Couldn’t load this"
      message={message ?? 'Check your connection and try again.'}
      action={<Button label="Try again" variant="gray" size="sm" fullWidth={false} icon="retry" onPress={onRetry} />}
    />
  );
}

const styles = StyleSheet.create({
  wrap: { alignItems: 'center', paddingVertical: 40, paddingHorizontal: 32, gap: 6 },
  well: {
    width: 60,
    height: 60,
    borderRadius: 30,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 8,
  },
  message: { maxWidth: 280 },
  action: { marginTop: 14 },
});
