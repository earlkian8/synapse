/**
 * Workspace switching for employees who belong to more than one company.
 *
 * A user is one identity (one login) that can belong to several organisations
 * (ADR 0023). The switcher lists those organisations and swaps the active one in a
 * tap — the server issues a token bound to the chosen company; no re-auth.
 *
 * Visual language: companies render as rounded *squares* to set them apart from
 * people, who are always *circles* (avatars) elsewhere. The active workspace is the
 * one ringed and tinted in teal.
 */
import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useState } from 'react';
import { ActivityIndicator, Pressable, View } from 'react-native';

import { Sheet } from '@/components/ui/sheet';
import { useToast } from '@/components/ui/toast';
import { AppText } from '@/components/ui/text';
import { useAuth } from '@/lib/auth';
import { useTheme } from '@/theme/theme';
import type { AuthOrganization } from '@/types/api';

/** A company mark — rounded square with an initials fallback (cf. round avatars for people). */
export function CompanyLogo({
  uri,
  initials,
  size = 44,
  active,
}: {
  uri?: string | null;
  initials?: string;
  size?: number;
  active?: boolean;
}) {
  const { colors, radius } = useTheme();

  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: radius.md,
        backgroundColor: colors.accentSoft,
        alignItems: 'center',
        justifyContent: 'center',
        overflow: 'hidden',
        borderWidth: active ? 2 : 0,
        borderColor: colors.accent,
      }}
    >
      {uri ? (
        <Image source={{ uri }} style={{ width: '100%', height: '100%' }} contentFit="cover" transition={200} />
      ) : (
        <AppText variant="label" style={{ color: colors.accent, fontSize: size * 0.34 }}>
          {(initials ?? '??').toUpperCase()}
        </AppText>
      )}
    </View>
  );
}

/** Compact tappable company badge for screen headers; hints at switching when more than one organisation exists. */
export function WorkspaceChip({ onPress }: { onPress: () => void }) {
  const { organization, organizations } = useAuth();
  const { colors, radius } = useTheme();

  if (!organization) return null;

  return (
    <Pressable
      onPress={onPress}
      hitSlop={6}
      style={({ pressed }) => ({
        flexDirection: 'row',
        alignItems: 'center',
        gap: 8,
        paddingVertical: 5,
        paddingLeft: 5,
        paddingRight: 10,
        borderRadius: radius.pill,
        backgroundColor: colors.card,
        borderWidth: 1,
        borderColor: colors.border,
        opacity: pressed ? 0.9 : 1,
      })}
    >
      <CompanyLogo uri={organization.logo} initials={organization.initials} size={26} />
      <AppText variant="caption" style={{ fontWeight: '700', maxWidth: 130 }} numberOfLines={1}>
        {organization.name}
      </AppText>
      <Ionicons
        name={organizations.length > 1 ? 'swap-horizontal' : 'chevron-down'}
        size={14}
        color={colors.textMuted}
      />
    </Pressable>
  );
}

/** The full workspace list, as a bottom sheet. */
export function WorkspaceSwitcher({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const { organization, organizations, switchTo } = useAuth();
  const { colors, spacing, radius } = useTheme();
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
    <Sheet visible={visible} onClose={onClose} title="Your companies">
      <View style={{ gap: spacing.sm }}>
        {organizations.map((org) => {
          const active = org.id === organization?.id;
          const busy = switching === org.id;

          return (
            <Pressable
              key={org.id}
              onPress={() => onSwitch(org)}
              disabled={active || switching !== null}
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                gap: spacing.md,
                padding: spacing.md,
                borderRadius: radius.lg,
                borderWidth: 1,
                borderColor: active ? colors.accent : colors.border,
                backgroundColor: active ? colors.accentSoft : colors.card,
              }}
            >
              <CompanyLogo uri={org.logo} initials={org.initials} active={active} />
              <AppText variant="label" style={{ flex: 1 }} numberOfLines={1}>
                {org.name}
              </AppText>
              {busy ? (
                <ActivityIndicator color={colors.accent} />
              ) : active ? (
                <Ionicons name="checkmark-circle" size={22} color={colors.accent} />
              ) : (
                <Ionicons name="swap-horizontal" size={20} color={colors.textFaint} />
              )}
            </Pressable>
          );
        })}
      </View>
    </Sheet>
  );
}
