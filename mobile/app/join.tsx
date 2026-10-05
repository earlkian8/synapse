import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { Page } from '@/components/ui/page';
import { Section } from '@/components/ui/section';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { declineInvitation, fetchInvitations } from '@/features/workspaces/api';
import { CompanyLogo } from '@/features/workspaces/workspace-switcher';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';
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
  const { colors, fonts, spacing } = useTheme();
  const { user, joinWithCode, acceptInvite, pendingRequests, logout } = useAuth();
  const toast = useToast();

  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [claiming, setClaiming] = useState<number | null>(null);

  const invitations = useQuery<{ data: Invitation[] }>(() => fetchInvitations(), []);
  const offers = invitations.data?.data ?? [];

  const waiting = pendingRequests.length > 0;
  const firstName = user?.name?.split(' ')[0];

  /** One field, two kinds of code: try it as an invitation, then as a join code. */
  const submit = async () => {
    const entered = code.trim();

    if (entered === '' || submitting) {
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
    <Page
      title={firstName ? `Hello, ${firstName}` : 'Hello'}
      subtitle="Your account is ready. Connect it to your company to clock in, file leave and see your records."
      refreshing={invitations.refreshing}
      onRefresh={invitations.refresh}
    >
      {/* Already asked — say so, so they don't ask twice. */}
      {waiting && (
        <Animated.View entering={enter(0)}>
          <Card style={styles.waiting}>
            <View style={[styles.well, { backgroundColor: colors.tintSoft }]}>
              <Icon name="hourglass" size={18} color={colors.tintText} />
            </View>
            <View style={styles.flex}>
              <AppText variant="headline">Waiting for approval</AppText>
              {pendingRequests.map((request) => (
                <AppText key={request.id} variant="subheadline" tone="secondary">
                  {request.organization} · asked {request.requested_human}
                </AppText>
              ))}
            </View>
          </Card>
        </Animated.View>
      )}

      {/* ── Invitations addressed to them ───────────────────── */}
      {invitations.loading ? (
        <Skeleton height={168} radius={20} />
      ) : (
        offers.length > 0 && (
          <Section title={offers.length === 1 ? 'You’re invited' : 'Invitations'}>
            {offers.map((invitation, index) => (
              <Animated.View key={invitation.id} entering={enter(index)}>
                <Card style={{ gap: spacing.lg }}>
                  <View style={styles.offer}>
                    <CompanyLogo uri={invitation.organization.logo} initials={invitation.organization.initials} size={46} />
                    <View style={styles.flex}>
                      <AppText variant="headline" numberOfLines={1}>
                        {invitation.organization.name}
                      </AppText>
                      <AppText variant="subheadline" tone="secondary" numberOfLines={2}>
                        {[invitation.employee.position, invitation.employee.department]
                          .filter(Boolean)
                          .join(' · ') || 'Invited you to join'}
                      </AppText>
                    </View>
                  </View>

                  <View style={styles.actions}>
                    <Button
                      label={`Join ${invitation.organization.name}`}
                      onPress={() => claim(invitation)}
                      loading={claiming === invitation.id}
                      disabled={claiming !== null && claiming !== invitation.id}
                    />
                    <Button label="Not me — decline" variant="plain" size="sm" fullWidth={false} onPress={() => dismiss(invitation)} style={styles.decline} />
                  </View>
                </Card>
              </Animated.View>
            ))}
          </Section>
        )
      )}

      {/* ── The code field ──────────────────────────────────── */}
      <Section title="Have a code?">
        <Card style={{ gap: spacing.lg }}>
          <AppText variant="subheadline" tone="secondary">
            Enter the company join code or an invitation code from your HR team.
          </AppText>

          <Input
            placeholder="ABC1234"
            autoCapitalize="characters"
            autoCorrect={false}
            autoComplete="off"
            value={code}
            onChangeText={(text) => setCode(text.toUpperCase())}
            editable={!submitting}
            onSubmitEditing={submit}
            returnKeyType="go"
            maxLength={16}
            accessibilityLabel="Join or invitation code"
            style={{ fontFamily: fonts.semibold, fontSize: 22, letterSpacing: 6, textAlign: 'center', fontVariant: ['tabular-nums'] }}
          />

          <Button label="Continue" onPress={submit} loading={submitting} disabled={code.trim() === ''} />
        </Card>
      </Section>

      <View style={styles.footer}>
        <AppText variant="footnote" tone="secondary" center>
          Setting up a company? Create it on the SYNAPSE web app. This app is for employees.
        </AppText>
        <Button label="Sign out" variant="plain" size="sm" fullWidth={false} onPress={() => void logout()} style={styles.decline} />
      </View>
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  waiting: { flexDirection: 'row', alignItems: 'flex-start', gap: 12 },
  well: { width: 36, height: 36, borderRadius: 18, alignItems: 'center', justifyContent: 'center' },
  offer: { flexDirection: 'row', alignItems: 'center', gap: 14 },
  actions: { gap: 4 },
  decline: { alignSelf: 'center' },
  footer: { gap: 8, alignItems: 'center', paddingHorizontal: 16 },
});
