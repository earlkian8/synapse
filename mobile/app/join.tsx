import { Ionicons } from '@expo/vector-icons';
import { useCallback, useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  View,
} from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Screen } from '@/components/ui/screen';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { declineInvitation, fetchInvitations } from '@/features/workspaces/api';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { Invitation } from '@/types/api';

/**
 * Where an account with no company lands (ADR 0026).
 *
 * Invitations come first and unprompted: if HR has already named this person, the
 * right move is one tap, and asking them to type a code they were emailed would be
 * busywork. The code field below is the fallback for everyone else — someone who
 * was told the company code verbally, or whose invitation went to an address they
 * no longer read.
 */
export default function JoinScreen() {
  const { colors, spacing, radius } = useTheme();
  const { user, joinWithCode, acceptInvite, pendingRequests, logout } = useAuth();
  const toast = useToast();

  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [claiming, setClaiming] = useState<number | null>(null);

  const invitations = useQuery<{ data: Invitation[] }>(useCallback(fetchInvitations, []));

  const waiting = pendingRequests.length > 0;

  /** One field, two kinds of code: try it as an invitation, then as a join code. */
  const submit = async () => {
    const entered = code.trim();

    if (entered === '') {
      return;
    }

    setSubmitting(true);

    try {
      // Invitation codes are 8 characters and join codes 7, but rather than
      // branch on length (and be wrong when the server's format changes) we
      // simply try the more specific one first.
      try {
        await acceptInvite(entered);
        toast.show('You’re in — welcome aboard.', 'success');
        return;
      } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 422) {
          throw error;
        }
      }

      const outcome = await joinWithCode(entered);

      toast.show(
        outcome === 'admitted'
          ? 'You’re in — welcome aboard.'
          : 'Request sent. Your HR team will review it.',
        outcome === 'admitted' ? 'success' : 'info',
      );
      setCode('');
    } catch (error) {
      toast.show(
        error instanceof ApiError
          ? error.message
          : 'Could not reach the server. Check your connection.',
        'error',
      );
    } finally {
      setSubmitting(false);
    }
  };

  const claim = async (invitation: Invitation) => {
    setClaiming(invitation.id);

    try {
      await acceptInvite(invitation.code);
      toast.show(`Welcome to ${invitation.organization.name}.`, 'success');
    } catch (error) {
      toast.show(
        error instanceof ApiError ? error.message : 'Could not accept that invitation.',
        'error',
      );
    } finally {
      setClaiming(null);
    }
  };

  const dismiss = async (invitation: Invitation) => {
    try {
      await declineInvitation(invitation.id);
      await invitations.reload();
      toast.show('Invitation declined.', 'info');
    } catch {
      toast.show('Could not decline that invitation.', 'error');
    }
  };

  return (
    <Screen>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={{ padding: spacing.lg, gap: spacing.lg }}
          keyboardShouldPersistTaps="handled"
        >
          <Animated.View entering={FadeIn.duration(400)} style={{ gap: 6 }}>
            <AppText variant="title">
              Hello{user?.name ? `, ${user.name.split(' ')[0]}` : ''}
            </AppText>
            <AppText variant="body" muted>
              Your account is ready. Connect it to your company to clock in, file leave, and see
              your records.
            </AppText>
          </Animated.View>

          {/* Already asked — say so, so they don't ask twice. */}
          {waiting && (
            <View
              style={{
                backgroundColor: colors.accentSoft,
                borderRadius: radius.md,
                padding: spacing.lg,
                gap: 4,
              }}
            >
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                <Ionicons name="time-outline" size={18} color={colors.accent} />
                <AppText variant="label">Waiting for approval</AppText>
              </View>
              {pendingRequests.map((request) => (
                <AppText key={request.id} variant="caption" muted>
                  {request.organization} · asked {request.requested_human}
                </AppText>
              ))}
            </View>
          )}

          {/* ── Invitations addressed to them ───────────────────── */}
          {invitations.loading ? (
            <ActivityIndicator color={colors.accent} />
          ) : (
            (invitations.data?.data ?? []).map((invitation) => (
              <View
                key={invitation.id}
                style={{
                  backgroundColor: colors.card,
                  borderRadius: radius.lg,
                  borderWidth: 1,
                  borderColor: colors.accent,
                  padding: spacing.lg,
                  gap: spacing.md,
                }}
              >
                <View
                  style={{
                    flexDirection: 'row',
                    alignItems: 'center',
                    gap: 12,
                  }}
                >
                  <View
                    style={{
                      width: 44,
                      height: 44,
                      borderRadius: 14,
                      backgroundColor: colors.brand,
                      alignItems: 'center',
                      justifyContent: 'center',
                    }}
                  >
                    <AppText variant="label" style={{ color: colors.brandText }}>
                      {invitation.organization.initials}
                    </AppText>
                  </View>
                  <View style={{ flex: 1 }}>
                    <AppText variant="label">{invitation.organization.name}</AppText>
                    <AppText variant="caption" muted>
                      {[invitation.employee.position, invitation.employee.department]
                        .filter(Boolean)
                        .join(' · ') || 'Invited you to join'}
                    </AppText>
                  </View>
                </View>

                <Button
                  label={`Join ${invitation.organization.name}`}
                  onPress={() => claim(invitation)}
                  loading={claiming === invitation.id}
                />
                <Pressable onPress={() => dismiss(invitation)} hitSlop={8}>
                  <AppText variant="caption" faint center>
                    Not me — decline
                  </AppText>
                </Pressable>
              </View>
            ))
          )}

          {/* ── The code field ──────────────────────────────────── */}
          <View
            style={{
              backgroundColor: colors.card,
              borderRadius: radius.lg,
              borderWidth: 1,
              borderColor: colors.border,
              padding: spacing.lg,
              gap: spacing.md,
            }}
          >
            <View style={{ gap: 4 }}>
              <AppText variant="label">Have a code?</AppText>
              <AppText variant="caption" muted>
                Enter the company join code or an invitation code from your HR team.
              </AppText>
            </View>

            <Input
              placeholder="ABC1234"
              autoCapitalize="characters"
              autoCorrect={false}
              value={code}
              onChangeText={setCode}
              editable={!submitting}
              onSubmitEditing={submit}
              returnKeyType="go"
              maxLength={16}
              style={{
                fontFamily: Platform.OS === 'ios' ? 'Menlo' : 'monospace',
                fontSize: 22,
                letterSpacing: 6,
                textAlign: 'center',
              }}
            />

            <Button
              label="Continue"
              onPress={submit}
              loading={submitting}
              disabled={code.trim() === ''}
            />
          </View>

          <View style={{ gap: spacing.md, alignItems: 'center' }}>
            <AppText variant="caption" faint center>
              Setting up a company? Create it on the SYNAPSE web app — this app is for employees.
            </AppText>
            <Pressable onPress={() => void logout()} hitSlop={8}>
              <AppText variant="caption" style={{ color: colors.textMuted }}>
                Sign out
              </AppText>
            </Pressable>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </Screen>
  );
}
