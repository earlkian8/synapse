/**
 * Workspace switching for employees who belong to more than one company.
 *
 * A user is one identity (one login) that can belong to several organisations
 * (ADR 0023). The switcher lists those organisations and swaps the active one in a
 * tap — the server issues a token bound to the chosen company; no re-auth.
 *
 * Visual language: companies render as rounded *squares* to set them apart from
 * people, who are always *circles* (avatars) elsewhere. The active workspace carries
 * the checkmark in a pick-one list, as iOS marks a chosen row.
 */
import { Image } from 'expo-image';
import { useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { Icon } from '@/components/ui/icon';
import { ListRow, ListSection } from '@/components/ui/list';
import { Sheet } from '@/components/ui/sheet';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { useToast } from '@/components/ui/toast';
import { useAuth } from '@/lib/auth';
import { palette } from '@/theme/tokens';
import { useTheme } from '@/theme/theme';
import type { AuthOrganization } from '@/types/api';

/** A company mark — a rounded square with an initials fallback (cf. round avatars for people). */
export function CompanyLogo({
  uri,
  initials,
  size = 40,
}: {
  uri?: string | null;
  initials?: string;
  size?: number;
}) {
  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.26,
        borderCurve: 'continuous',
        // The brand navy, whatever the scheme: a company's monogram is a brand mark.
        backgroundColor: palette.navy,
        alignItems: 'center',
        justifyContent: 'center',
        overflow: 'hidden',
      }}
    >
      {uri ? (
        <Image source={{ uri }} style={{ width: '100%', height: '100%' }} contentFit="cover" transition={200} />
      ) : (
        <AppText
          weight="semibold"
          color={palette.white}
          maxFontSizeMultiplier={1}
          style={{ fontSize: size * 0.36, lineHeight: size * 0.44, letterSpacing: 0.4 }}
        >
          {(initials ?? '??').toUpperCase()}
        </AppText>
      )}
    </View>
  );
}

/** Compact tappable company badge for a page header; hints at switching when more than one organisation exists. */
export function WorkspaceChip({ onPress }: { onPress: () => void }) {
  const { organization, organizations } = useAuth();
  const { colors } = useTheme();

  if (!organization) return null;

  return (
    <Touchable
      onPress={onPress}
      haptic="light"
      scaleTo={0.95}
      hitSlop={6}
      accessibilityRole="button"
      accessibilityLabel={`Workspace: ${organization.name}`}
      accessibilityHint={organizations.length > 1 ? 'Switches to another company' : undefined}
      style={[styles.chip, { backgroundColor: colors.fill }]}
    >
      <CompanyLogo uri={organization.logo} initials={organization.initials} size={22} />
      <AppText variant="footnote" weight="semibold" numberOfLines={1} style={styles.chipName}>
        {organization.name}
      </AppText>
      <Icon name="chevronDown" size={11} color={colors.textSecondary} weight="bold" />
    </Touchable>
  );
}

/** The full workspace list, as a bottom sheet. */
export function WorkspaceSwitcher({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const { organization, organizations, switchTo } = useAuth();
  const toast = useToast();
  const [switching, setSwitching] = useState<number | null>(null);

  const onSwitch = async (target: AuthOrganization) => {
    if (target.id === organization?.id || switching !== null) return;

    setSwitching(target.id);

    try {
      await switchTo(target.id);
      onClose();
      toast.show(`Switched to ${target.name}`, 'success');
    } catch {
      toast.show('Could not switch company. Try again.', 'error');
    } finally {
      setSwitching(null);
    }
  };

  return (
    <Sheet
      visible={visible}
      onClose={onClose}
      dismissible={switching === null}
      title="Your companies"
      message={
        organizations.length > 1
          ? 'Switch the company you are working in. Your sign-in stays the same.'
          : 'This account belongs to one company.'
      }
    >
      <ListSection raised leadingWidth={36}>
        {organizations.map((org) => {
          const active = org.id === organization?.id;

          return (
            <ListRow
              key={org.id}
              title={org.name}
              subtitle={active ? 'Current workspace' : undefined}
              leading={<CompanyLogo uri={org.logo} initials={org.initials} size={36} />}
              onPress={() => onSwitch(org)}
              selected={active}
              accessory={switching === org.id ? <ActivityIndicator /> : undefined}
            />
          );
        })}
      </ListSection>
    </Sheet>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'flex-start',
    gap: 7,
    paddingVertical: 5,
    paddingLeft: 5,
    paddingRight: 10,
    borderRadius: 999,
  },
  chipName: { maxWidth: 170 },
});
