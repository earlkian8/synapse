import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet } from 'react-native';
import Animated, { FadeIn, FadeOut } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/icon';
import { BarTextButton, Page } from '@/components/ui/page';
import { Section } from '@/components/ui/section';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { SERVICE_GROUPS, servicesFor, type Service } from '@/features/services/catalog';
import { ServiceGrid, type TileEdit } from '@/features/services/components/service-grid';
import { MAX_SHORTCUTS, useShortcuts } from '@/features/services/shortcuts';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';
import { useTheme } from '@/theme/theme';

/**
 * Services (ADR 0088): everything the app does, sorted by what it is for, the
 * way GCash's "View all" lays out its services. The top card is what sits on
 * Home; Edit pins and unpins from any family below it.
 */
export default function ServicesScreen() {
  const { colors, radius, squircle } = useTheme();
  const { user } = useAuth();
  const router = useRouter();
  // Home opens this in edit mode when one of its tiles is held down.
  const params = useLocalSearchParams<{ edit?: string }>();
  const [editingHere, setEditingHere] = useState(false);
  const editing = editingHere || params.edit === '1';

  const { shortcuts, isPinned, full, toggle, reset, customized } = useShortcuts();
  const services = servicesFor(user);

  const finish = () => {
    setEditingHere(false);
    router.setParams({ edit: undefined });
  };

  const editFor = editing
    ? (service: Service): TileEdit => (isPinned(service.key) ? 'unpin' : full ? 'full' : 'pin')
    : undefined;

  return (
    <Page
      title="Services"
      right={editing ? <BarTextButton label="Done" emphasized onPress={finish} /> : undefined}
      tabInset
      gap={26}
    >
      {/* Search: the app's own, which finds these and one's records too. */}
      <Touchable
        onPress={() => router.push('/search')}
        feedback="opacity"
        accessibilityRole="search"
        accessibilityLabel="Search services and your records"
        style={[styles.search, squircle, { backgroundColor: colors.fill, borderRadius: radius.sm }]}
      >
        <Icon name="search" size={16} color={colors.textSecondary} weight="semibold" />
        <AppText variant="body" tone="secondary" numberOfLines={1}>
          Services and your records
        </AppText>
      </Touchable>

      <Animated.View entering={enter(0)}>
        <Section
          title="On your Home"
          action={editing ? undefined : { label: 'Edit', onPress: () => setEditingHere(true) }}
        >
          <Card style={styles.card}>
            {shortcuts.length > 0 ? (
              <ServiceGrid services={shortcuts} editFor={editFor} onEdit={(service) => toggle(service.key)} />
            ) : (
              <AppText variant="subheadline" tone="secondary" center style={styles.empty}>
                Nothing on Home yet. Tap a service below to add it.
              </AppText>
            )}
          </Card>
          {editing && (
            <Animated.View entering={FadeIn.duration(180)} exiting={FadeOut.duration(120)} style={styles.editNote}>
              <AppText variant="footnote" tone="secondary" style={styles.flex}>
                {full
                  ? `Home holds ${MAX_SHORTCUTS}. Remove one to add another.`
                  : `Tap a service to add it. ${MAX_SHORTCUTS - shortcuts.length} more ${MAX_SHORTCUTS - shortcuts.length === 1 ? 'fits' : 'fit'}.`}
              </AppText>
              {customized && <Button label="Restore" variant="plain" size="sm" fullWidth={false} onPress={reset} />}
            </Animated.View>
          )}
        </Section>
      </Animated.View>

      {SERVICE_GROUPS.map((group, index) => {
        const members = services.filter((service) => service.group === group.key);
        if (members.length === 0) return null;

        return (
          <Animated.View key={group.key} entering={enter(index + 1)}>
            <Section title={group.title}>
              <Card style={styles.card}>
                <ServiceGrid services={members} editFor={editFor} onEdit={(service) => toggle(service.key)} />
              </Card>
            </Section>
          </Animated.View>
        );
      })}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  search: { flexDirection: 'row', alignItems: 'center', gap: 8, height: 40, paddingHorizontal: 12, marginTop: -6 },
  card: { paddingVertical: 18, paddingHorizontal: 6 },
  empty: { paddingVertical: 10, paddingHorizontal: 12 },
  editNote: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingHorizontal: 4, minHeight: 32 },
});
