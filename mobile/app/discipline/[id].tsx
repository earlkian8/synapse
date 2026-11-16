import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { disciplineApi, STATUS_WORDS } from '@/features/discipline/api';
import type { Notice } from '@/features/discipline/types';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useClockZone } from '@/lib/clock-zone';
import { formatAgo, formatDate, formatTime } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';

/** The most an explanation may hold (ExplanationRequest). */
const MAX = 10000;

/**
 * One notice served on you (ADR 0105): the charge and the facts, your written
 * answer — with a hearing if you want one — and, once decided, the decision.
 * Saying you received a notice is proof it was served, not that you agree.
 */
export default function NoticeScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { status, spacing } = useTheme();
  const toast = useToast();
  const zone = useClockZone();
  const { refresh: refreshSession } = useAuth();
  const { data, loading, refreshing, refresh, error, reload, setData } = useQuery<{ data: Notice }>(() => disciplineApi.one(id), [id]);
  const notice = data?.data ?? null;

  const [draft, setDraft] = useState<string | null>(null);
  const [hearing, setHearing] = useState<boolean | null>(null);
  const [sending, setSending] = useState(false);

  const open = !!notice && ['notice_issued', 'explained', 'hearing'].includes(notice.status);
  const text = draft ?? notice?.explanation ?? '';
  const asksHearing = hearing ?? notice?.hearing_requested ?? false;

  const acknowledge = async (which: 'notice' | 'decision') => {
    try {
      const answer = await disciplineApi.acknowledge(id, which);
      setData(answer);
      toast.show('HR can see you received it.', 'success');
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t send that. Try again.', 'error');
    }
  };

  const send = async () => {
    if (text.trim().length < 10) {
      toast.show('Write a little more: say what happened from your side.', 'error');
      return;
    }

    setSending(true);

    try {
      const answer = await disciplineApi.explain(id, text.trim(), asksHearing);
      setData(answer);
      setDraft(null);
      toast.show('Your explanation was sent to HR.', 'success');
      void refreshSession();
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t send it. Try again.', 'error');
    } finally {
      setSending(false);
    }
  };

  return (
    <Page title={notice?.offense?.title ?? 'Notice'} back refreshing={refreshing} onRefresh={refresh} gap={24}>
      {loading ? (
        <>
          <Skeleton height={140} radius={20} />
          <Skeleton height={220} radius={20} />
        </>
      ) : error && !notice ? (
        <ErrorState message={error} onRetry={reload} />
      ) : notice ? (
        <>
          <Animated.View entering={enter(0)}>
            <View style={styles.meta}>
              <Pill label={STATUS_WORDS[notice.status]} color={open && !notice.explained_at ? status.late : status.present} dot />
              <AppText variant="footnote" tone="secondary">
                {notice.reference}
              </AppText>
            </View>
          </Animated.View>

          {notice.decided_at && (
            <Animated.View entering={enter(1)}>
              <Card style={{ gap: spacing.sm }}>
                <AppText variant="footnote" tone="secondary">
                  The decision, {formatDate(notice.decided_at.slice(0, 10))}
                </AppText>
                <AppText variant="headline">
                  {notice.finding === 'upheld'
                    ? `The charge is upheld: ${notice.penalty_label}${notice.penalty === 'suspension' ? `, ${notice.suspension_days} days from ${formatDate(notice.penalty_from)}` : ''}.`
                    : 'The charge is not upheld. No penalty is imposed.'}
                </AppText>
                <AppText variant="subheadline">{notice.decision}</AppText>
                <Received at={notice.decision_received_at} onAcknowledge={() => void acknowledge('decision')} />
              </Card>
            </Animated.View>
          )}

          <Animated.View entering={enter(2)}>
            <Card style={{ gap: spacing.sm }}>
              <AppText variant="footnote" tone="secondary">
                The charge, served {formatAgo(notice.notice_issued_at, zone)}
              </AppText>
              <AppText variant="body">{notice.notice_grounds}</AppText>
              <AppText variant="footnote" tone="secondary" style={styles.facts}>
                What is reported to have happened, {formatDate(notice.incident_date)}
                {notice.location ? `, ${notice.location}` : ''}:
              </AppText>
              <AppText variant="subheadline">{notice.narrative}</AppText>
              {notice.preventive_from && (
                <AppText variant="footnote" color={status.late}>
                  You are on preventive suspension from {formatDate(notice.preventive_from)} to {formatDate(notice.preventive_to)} while this is heard. It is not a penalty.
                </AppText>
              )}
              <Received at={notice.notice_received_at} onAcknowledge={() => void acknowledge('notice')} />
            </Card>
          </Animated.View>

          {notice.hearing_at && (
            <ListSection header="Hearing" withIcons>
              <ListRow
                icon="calendar"
                title={`${formatDate(notice.hearing_at.slice(0, 10))}, ${formatTime(notice.hearing_at, zone)}`}
                subtitle={notice.hearing_held_at ? 'Held' : (notice.hearing_place ?? 'You may bring a representative.')}
              />
            </ListSection>
          )}

          <Animated.View entering={enter(3)} style={{ gap: spacing.md }}>
            <AppText variant="headline">Your explanation</AppText>
            <AppText variant="footnote" tone="secondary">
              {notice.explained_at
                ? `Sent ${formatAgo(notice.explained_at, zone)}.${open ? ' You can change it until the case is decided.' : ''}`
                : notice.reply_overdue
                  ? `It was due ${formatDate(notice.reply_due_on)}. HR may decide without it.`
                  : `Due ${formatDate(notice.reply_due_on)}. Say what happened from your side.`}
            </AppText>

            {open ? (
              <>
                <Input
                  label="In your own words"
                  value={text}
                  onChangeText={(value) => setDraft(value.slice(0, MAX))}
                  multiline
                  maxLength={MAX}
                />
                <ListSection raised>
                  <ListRow
                    title="I ask for a hearing"
                    subtitle="To explain in person. HR holds it before deciding."
                    selected={asksHearing}
                    onPress={notice.hearing_requested ? undefined : () => setHearing(!asksHearing)}
                    accessibilityLabel={`Ask for a hearing, ${asksHearing ? 'on' : 'off'}`}
                  />
                </ListSection>
                <Button
                  label={notice.explained_at ? 'Send the changes' : 'Send my explanation'}
                  icon="send"
                  variant="tint"
                  loading={sending}
                  disabled={sending}
                  onPress={() => void send()}
                />
              </>
            ) : (
              <Card>
                <AppText variant="subheadline">{notice.explanation ?? 'You didn’t send one.'}</AppText>
              </Card>
            )}
          </Animated.View>
        </>
      ) : null}
    </Page>
  );
}

/** Whether they've said they received a notice, or the button to say so. */
function Received({ at, onAcknowledge }: { at: string | null; onAcknowledge: () => void }) {
  const zone = useClockZone();

  return at ? (
    <AppText variant="footnote" tone="secondary">
      You said you received it {formatAgo(at, zone)}.
    </AppText>
  ) : (
    <Button label="I’ve received it" icon="check" variant="gray" size="sm" fullWidth={false} onPress={onAcknowledge} accessibilityHint="Tells HR it reached you. It doesn’t mean you agree." />
  );
}

const styles = StyleSheet.create({
  meta: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  facts: { marginTop: 6 },
});
