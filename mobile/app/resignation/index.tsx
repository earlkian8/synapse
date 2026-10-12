import { LinearGradient } from 'expo-linear-gradient';
import { useRouter } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { Alert, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { ProgressBar } from '@/components/ui/progress-bar';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { ToneWell } from '@/components/ui/tone-well';
import { offboardingApi } from '@/features/offboarding/api';
import { CLEARANCE_STATUS, RESIGNATION_STATUS, relativeDay } from '@/features/offboarding/meta';
import { ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { MyExit, MyResignation } from '@/types/api';

/**
 * Leaving, from the phone (ADRs 0076, 0077): file a resignation and withdraw it
 * while HR has not decided; once there is an exit, follow it — the last day on
 * the one navy card, the clearance item by item with what is flagged, the exit
 * interview — and open the Certificate of Employment once the exit is completed.
 */
export default function ResignationScreen() {
  const { colors, status, readable, radius, squircle } = useTheme();
  const router = useRouter();
  const toast = useToast();
  const [opening, setOpening] = useState(false);

  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: MyExit }>(() => offboardingApi.show(), []);
  const me = data?.data ?? null;
  const pending = me?.resignations.find((r) => r.status === 'pending') ?? null;

  const withdraw = (resignation: MyResignation) =>
    Alert.alert('Withdraw your resignation?', 'HR stops reviewing it. You can file a new one later.', [
      { text: 'Keep it', style: 'cancel' },
      {
        text: 'Withdraw',
        style: 'destructive',
        onPress: async () => {
          try {
            await offboardingApi.withdraw(resignation.id);
            toast.show('Resignation withdrawn.', 'success');
            reload();
          } catch (e) {
            toast.show(e instanceof ApiError ? e.message : 'Couldn’t withdraw it. Try again.', 'error');
          }
        },
      },
    ]);

  const openCertificate = async () => {
    setOpening(true);

    try {
      const link = await offboardingApi.certificateLink();
      await WebBrowser.openBrowserAsync(link.data.url);
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t open your certificate. Try again.', 'error');
    } finally {
      setOpening(false);
    }
  };

  return (
    <Page title="Resignation" back refreshing={refreshing} onRefresh={refresh} gap={24}>
      {loading ? (
        <>
          <Skeleton height={170} radius={20} />
          <Skeleton height={220} radius={14} />
        </>
      ) : error && !me ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !me ? null : !me.has_employee ? (
        <EmptyState
          icon="person"
          title="Your account isn’t linked yet"
          message="Resigning belongs to an employee record. Ask HR to link your account to yours."
        />
      ) : (
        <>
          {me.exit && (
            <Animated.View entering={enter(0)}>
              <LinearGradient
                colors={colors.hero}
                start={{ x: 0, y: 0 }}
                end={{ x: 1, y: 1 }}
                style={[styles.hero, squircle, { borderRadius: radius.xl }]}
              >
                <AppText variant="caption2" weight="semibold" color={colors.onHeroSecondary} style={styles.eyebrow}>
                  {me.exit.status === 'completed' ? 'YOUR LAST DAY WAS' : 'YOUR LAST WORKING DAY'}
                </AppText>
                <AppText variant="title1" color={colors.onHero}>
                  {formatDate(me.exit.last_working_day)}
                </AppText>
                {me.exit.last_working_day && me.exit.status !== 'completed' && (
                  <AppText variant="subheadline" color={colors.onHeroSecondary}>
                    {relativeDay(me.today, me.exit.last_working_day)}
                  </AppText>
                )}
                <View style={styles.heroProgress}>
                  <ProgressBar
                    value={me.exit.clearance.total === 0 ? 0 : me.exit.clearance.cleared / me.exit.clearance.total}
                    color={palette.teal}
                    track="rgba(255,255,255,0.14)"
                  />
                  <View style={styles.rowBetween}>
                    <AppText variant="footnote" weight="semibold" color={colors.onHero} numeric>
                      {me.exit.clearance.cleared} of {me.exit.clearance.total} signed off
                    </AppText>
                    {me.exit.clearance.flagged > 0 && (
                      <AppText variant="footnote" color={colors.onHeroSecondary} numeric>
                        {me.exit.clearance.flagged} flagged
                      </AppText>
                    )}
                  </View>
                </View>
              </LinearGradient>
            </Animated.View>
          )}

          {me.exit && (me.interview?.can_answer || me.exit.certificate.ready) && (
            <Animated.View entering={enter(1)} style={styles.actions}>
              {me.interview?.can_answer && (
                <Button
                  label="Answer your exit interview"
                  icon="survey"
                  variant="primary"
                  fullWidth
                  onPress={() => router.push('/resignation/interview')}
                />
              )}
              {me.exit.certificate.ready && (
                <Button
                  label="Open your Certificate of Employment"
                  icon="certificate"
                  variant="tinted"
                  fullWidth
                  loading={opening}
                  onPress={() => void openCertificate()}
                />
              )}
            </Animated.View>
          )}

          {me.exit && (
            <Animated.View entering={enter(2)}>
              <ListSection
                header="Your clearance"
                footer="What each department signs off before you go. A flagged item says what is still needed from you."
              >
                {me.exit.items.map((item) => {
                  const meta = CLEARANCE_STATUS[item.status];

                  return (
                    <ListRow
                      key={item.id}
                      title={item.item}
                      subtitle={item.remarks ?? item.department ?? 'Unassigned'}
                      value={item.cleared_on ? formatDate(item.cleared_on) : meta.label}
                      accessory={<View style={[styles.dot, { backgroundColor: readable(meta.color, colors.card, 3) }]} />}
                      wrap
                    />
                  );
                })}
              </ListSection>
            </Animated.View>
          )}

          {me.interview && !me.interview.can_answer && me.interview.status === 'submitted' && (
            <ListSection>
              <ListRow
                icon="survey"
                iconColor={status.present}
                title="Exit interview"
                value={`Answered ${formatDate(me.interview.submitted_on)}`}
                onPress={() => router.push('/resignation/interview')}
              />
            </ListSection>
          )}

          {pending && (
            <Animated.View entering={enter(1)}>
              <Card style={styles.gap}>
                <View style={styles.cardHead}>
                  <ToneWell icon="hourglass" color={status.late} />
                  <View style={styles.flex}>
                    <AppText variant="headline">Your resignation is with HR</AppText>
                    <AppText variant="subheadline" tone="secondary">
                      Last day {formatDate(pending.proposed_last_day)} · {pending.notice_days} days’ notice
                    </AppText>
                  </View>
                </View>
                <AppText variant="footnote" tone="secondary">
                  HR will agree the date with you. Until they decide, you can take it back.
                </AppText>
                <Button label="Withdraw it" variant="gray" onPress={() => withdraw(pending)} />
              </Card>
            </Animated.View>
          )}

          {me.can_resign && (
            <Animated.View entering={enter(1)}>
              <Card style={styles.gap}>
                <View style={styles.cardHead}>
                  <ToneWell icon="exit" color={colors.tint} />
                  <View style={styles.flex}>
                    <AppText variant="headline">Thinking of leaving?</AppText>
                    <AppText variant="subheadline" tone="secondary">
                      Propose your last day and HR takes it from there. The Labor Code asks for {me.notice_days} days’
                      notice.
                    </AppText>
                  </View>
                </View>
                <Button label="File your resignation" variant="tinted" onPress={() => router.push('/resignation/new')} />
              </Card>
            </Animated.View>
          )}

          {me.resignations.length > 0 && (
            <Animated.View entering={enter(3)}>
              <ListSection header="Your resignations">
                {me.resignations.map((resignation) => {
                  const meta = RESIGNATION_STATUS[resignation.status];

                  return (
                    <ListRow
                      key={resignation.id}
                      title={`Last day ${formatDate(resignation.proposed_last_day)}`}
                      subtitle={
                        resignation.decision_note ??
                        `Filed ${formatDate(resignation.filed_on)} · ${resignation.notice_days} days’ notice`
                      }
                      accessory={<Pill label={meta.label} color={meta.color} />}
                      wrap
                    />
                  );
                })}
              </ListSection>
            </Animated.View>
          )}

          {!me.exit && !pending && !me.can_resign && (
            <EmptyState
              icon="checkCircle"
              title="Nothing to do here"
              message="Your employment has ended. If you need your records, ask HR."
            />
          )}
        </>
      )}
    </Page>
  );
}

const styles = StyleSheet.create({
  hero: { padding: 20, gap: 4, overflow: 'hidden' },
  eyebrow: { letterSpacing: 0.6, marginBottom: 4 },
  heroProgress: { gap: 8, marginTop: 14 },
  rowBetween: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  actions: { gap: 10 },
  gap: { gap: 12 },
  cardHead: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  flex: { flex: 1, gap: 2 },
  dot: { width: 10, height: 10, borderRadius: 5 },
});
