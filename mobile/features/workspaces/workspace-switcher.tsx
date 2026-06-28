/**
 * Workspace switching UI for employees who belong to more than one company.
 *
 * Each company a person works for is a separate SYNAPSE account (one account ⇒
 * one organisation, ADR 0005). The switcher lists every signed-in account and
 * swaps the active one in a tap — no signing out, no re-typing credentials.
 *
 * Visual language: companies render as rounded *squares* to set them apart from
 * people, who are always *circles* (avatars) elsewhere in the app. The active
 * workspace is the one ringed and tinted in teal.
 */
import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { useRouter, type Href } from 'expo-router';
import { Pressable, View } from 'react-native';

import { Sheet } from '@/components/ui/sheet';
import { AppText } from '@/components/ui/text';
import { useAuth } from '@/lib/auth';
import { useTheme } from '@/theme/theme';

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

/** Compact tappable company badge for screen headers; hints at switching when more than one workspace exists. */
export function WorkspaceChip({ onPress }: { onPress: () => void }) {
  const { organization, sessions } = useAuth();
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
        name={sessions.length > 1 ? 'swap-horizontal' : 'chevron-down'}
        size={14}
        color={colors.textMuted}
      />
    </Pressable>
  );
}

/** The full workspace list, as a bottom sheet. */
export function WorkspaceSwitcher({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const { sessions, activeId, switchTo, signOut } = useAuth();
  const { colors, spacing, radius } = useTheme();
  const router = useRouter();

  const onSwitch = (id: string) => {
    onClose();
    void switchTo(id);
  };

  const onAdd = () => {
    onClose();
    // Cast: expo-router regenerates typed routes for new files when Metro runs.
    router.push('/add-account' as Href);
  };

  return (
    <Sheet visible={visible} onClose={onClose} title="Your workspaces">
      <View style={{ gap: spacing.sm }}>
        {sessions.map((session) => {
          const active = session.id === activeId;
          const org = session.user.organization;

          return (
            <View
              key={session.id}
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
              <Pressable
                onPress={() => onSwitch(session.id)}
                disabled={active}
                style={{ flex: 1, flexDirection: 'row', alignItems: 'center', gap: spacing.md }}
              >
                <CompanyLogo uri={org?.logo} initials={org?.initials} active={active} />
                <View style={{ flex: 1 }}>
                  <AppText variant="label" numberOfLines={1}>
                    {org?.name ?? 'Company'}
                  </AppText>
                  <AppText variant="caption" muted numberOfLines={1}>
                    {session.user.name} · {session.user.email}
                  </AppText>
                </View>
                {active && <Ionicons name="checkmark-circle" size={22} color={colors.accent} />}
              </Pressable>

              <Pressable onPress={() => void signOut(session.id)} hitSlop={10} style={{ padding: 4 }}>
                <Ionicons name="log-out-outline" size={20} color={colors.textFaint} />
              </Pressable>
            </View>
          );
        })}

        <Pressable
          onPress={onAdd}
          style={({ pressed }) => ({
            flexDirection: 'row',
            alignItems: 'center',
            gap: spacing.md,
            padding: spacing.md,
            borderRadius: radius.lg,
            borderWidth: 1,
            borderStyle: 'dashed',
            borderColor: colors.border,
            opacity: pressed ? 0.85 : 1,
          })}
        >
          <View
            style={{
              width: 44,
              height: 44,
              borderRadius: radius.md,
              backgroundColor: colors.cardAlt,
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <Ionicons name="add" size={24} color={colors.accent} />
          </View>
          <View style={{ flex: 1 }}>
            <AppText variant="label">Add a company</AppText>
            <AppText variant="caption" muted>
              Sign in to another company you work for
            </AppText>
          </View>
        </Pressable>
      </View>
    </Sheet>
  );
}
