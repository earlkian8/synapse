import * as Haptics from 'expo-haptics';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Alert, Linking, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Sheet } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { Touchable } from '@/components/ui/touchable';
import { dayBlock } from '@/features/schedule/meta';
import { trainingApi } from '@/features/training/api';
import { CertificateThumb } from '@/features/training/components/certificate-art';
import { DateLeaf, SeatMeter, useLearningInk } from '@/features/training/components/program-parts';
import { dateRange, ENROLLMENT_STATUS, MARK, MODE_LABEL, PROGRAM_STATUS, sittingTime, validityLabel } from '@/features/training/meta';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, parseDateOnly } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { MyTrainingEnrollment, TrainingProgramDetail } from '@/types/api';

type Sitting = TrainingProgramDetail['sessions'][number];

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * One program (ADR 0094), as the person taking it sees it: what it is, the
 * seats and the certificate, their own place on it, and every session with
 * their attendance at it. Joining, asking, withdrawing and leaving all happen
 * here, on the web's own rules — and the server's words, when it refuses.
 */
export default function TrainingProgramScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const toast = useToast();
  const { user, organization } = useAuth();

  const { data, loading, refreshing, refresh, error, reload, setData } = useQuery(() => trainingApi.program(id), [id]);
  const program = data?.data ?? null;
  const mine = program?.mine ?? null;

  const [confirming, setConfirming] = useState(false);
  const [joining, setJoining] = useState(false);
  const [adding, setAdding] = useState<number | null>(null);

  const seated = mine?.status === 'enrolled' || mine?.status === 'completed';

  const join = async () => {
    if (!program) return;
    setJoining(true);

    try {
      const result = await trainingApi.join(program.hashid);
      setData({ data: result.data });
      setConfirming(false);
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      toast.show(result.message, 'success');
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t send that. Try again.', 'error');
      // The seats may have changed under us: show them as they are now.
      void reload();
    } finally {
      setJoining(false);
    }
  };

  const withdraw = (enrollment: MyTrainingEnrollment) => {
    const asking = enrollment.status === 'requested';

    Alert.alert(
      asking ? 'Withdraw your request?' : 'Leave this program?',
      asking ? 'HR stops considering it. You can ask again later while the program takes people.' : 'Your seat goes to someone else. You can join again while seats last.',
      [
        { text: asking ? 'Keep it' : 'Stay', style: 'cancel' },
        {
          text: asking ? 'Withdraw' : 'Leave',
          style: 'destructive',
          onPress: async () => {
            try {
              const result = await trainingApi.withdraw(enrollment.id);
              toast.show(result.message, 'success');
              if (result.data) setData({ data: result.data });
              else router.back();
            } catch (e) {
              toast.show(e instanceof ApiError ? e.message : 'Couldn’t do that. Try again.', 'error');
            }
          },
        },
      ],
    );
  };

  /** Hand a session to the phone's calendar: a short-lived link its browser opens. */
  const addToCalendar = async (sitting: Sitting) => {
    setAdding(sitting.id);

    try {
      const link = await trainingApi.calendarLink(sitting.id);
      await Linking.openURL(link.data.url);
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t open your calendar. Try again.', 'error');
    } finally {
      setAdding(null);
    }
  };

  // Sessions are set on the company's clock; say so when this person's site keeps another.
  const otherClock = !!organization?.timezone && !!user?.employee?.timezone && user.employee.timezone !== organization.timezone;

  return (
    <Page title={program?.name ?? 'Training'} largeTitle={false} back refreshing={refreshing} onRefresh={refresh} gap={20}>
      {loading ? (
        <>
          <Skeleton height={200} radius={20} />
          <Skeleton height={120} radius={20} />
          <Skeleton height={180} radius={20} />
        </>
      ) : error && !program ? (
        <ErrorState message={error} onRetry={reload} />
      ) : program ? (
        <>
          <Animated.View entering={enter(0)}>
            <Hero program={program} />
          </Animated.View>

          {mine && (
            <Animated.View key={mine.status} entering={enter(1)}>
              <YourPlace
                enrollment={mine}
                held={program.sessions.filter((s) => s.is_held)}
                onCertificate={(certificateId) => router.push({ pathname: '/training/certificate/[id]', params: { id: String(certificateId) } })}
              />
            </Animated.View>
          )}

          <Animated.View entering={enter(2)}>
            <ListSection
              header="Sessions"
              leadingWidth={40}
              footer={
                program.sessions.length === 0
                  ? 'HR hasn’t set the sessions yet. They show here, and in My schedule, once they do.'
                  : otherClock
                    ? `Times are on the company’s clock (${organization?.timezone?.replace(/_/g, ' ')}). My schedule shows them at your site’s.`
                    : seated
                      ? 'They’re in My schedule too. Attendance is HR’s record, marked at each session.'
                      : undefined
              }
            >
              {program.sessions.length === 0 ? (
                <ListRow title="No sessions yet" />
              ) : (
                program.sessions.map((sitting) => (
                  <SittingRow
                    key={sitting.id}
                    sitting={sitting}
                    seated={seated}
                    adding={adding === sitting.id}
                    onAdd={() => void addToCalendar(sitting)}
                  />
                ))
              )}
            </ListSection>
          </Animated.View>

          <Animated.View entering={enter(3)} style={styles.actions}>
            <Actions program={program} onJoin={() => setConfirming(true)} onWithdraw={withdraw} />
          </Animated.View>

          <Sheet
            visible={confirming}
            onClose={() => setConfirming(false)}
            dismissible={!joining}
            title={program.join === 'ask' ? `Ask to join ${program.name}?` : `Join ${program.name}?`}
            message={
              program.join === 'ask'
                ? 'HR approves or declines your request, and you’re told either way. Asking takes no seat yet.'
                : 'You take a seat now. You can leave until the program starts.'
            }
          >
            <View style={styles.sheet}>
              <ListSection raised>
                <ListRow title="When" value={dateRange(program.start_date, program.end_date)} />
                <ListRow
                  title="Sessions"
                  value={program.sessions.length === 0 ? 'To be set' : `${program.sessions.length}, first ${formatDate(program.sessions[0].session_date)}`}
                  wrap
                />
                {program.capacity !== null && program.seats_remaining !== null && (
                  <ListRow title="Seats left" value={`${program.seats_remaining} of ${program.capacity}`} />
                )}
              </ListSection>
              <Button label={program.join === 'ask' ? 'Ask to join' : 'Join program'} onPress={() => void join()} loading={joining} />
              <Button label="Not now" variant="plain" onPress={() => setConfirming(false)} disabled={joining} />
            </View>
          </Sheet>
        </>
      ) : null}
    </Page>
  );
}

/** What the program is: its name, who runs it, when, the seats and the certificate. */
function Hero({ program }: { program: TrainingProgramDetail }) {
  const { colors } = useTheme();
  const phase = PROGRAM_STATUS[program.status];

  return (
    <Card style={styles.hero}>
      <View style={styles.heroTop}>
        <DateLeaf date={program.start_date} size={54} />
        <View style={styles.heroTitles}>
          <AppText variant="title3">{program.name}</AppText>
          <AppText variant="subheadline" tone="secondary">
            {program.provider ?? 'In-house'}
          </AppText>
        </View>
      </View>

      <View style={styles.pills}>
        <Pill label={phase.label} color={phase.color} dot />
        <AppText variant="footnote" tone="tint" weight="medium">
          {MODE_LABEL[program.enrollment_mode]}
        </AppText>
      </View>

      {program.description && (
        <AppText variant="callout" tone="secondary" selectable>
          {program.description}
        </AppText>
      )}

      <View style={[styles.facts, { borderTopColor: colors.separator }]}>
        <Fact label="When" value={dateRange(program.start_date, program.end_date)} />
        <Fact label="Certificate" value={program.issues_certificate ? validityLabel(program.certificate_validity_months) : 'None'} />
        {program.enrollment_mode !== 'closed' && <SeatMeter capacity={program.capacity} left={program.seats_remaining} />}
      </View>
    </Card>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.fact}>
      <AppText variant="footnote" tone="secondary" style={styles.factLabel}>
        {label}
      </AppText>
      <AppText variant="footnote" weight="medium" numeric style={styles.flex}>
        {value}
      </AppText>
    </View>
  );
}

/**
 * The person's own place: a request waiting or declined (with HR's note), their
 * attendance as one mark a session, or how it ended and the certificate it earned.
 */
function YourPlace({ enrollment, held, onCertificate }: { enrollment: MyTrainingEnrollment; held: Sitting[]; onCertificate: (id: number) => void }) {
  const { colors } = useTheme();
  const state = ENROLLMENT_STATUS[enrollment.status];
  const { attended, counted, rate } = enrollment.attendance;

  return (
    <Card style={styles.place}>
      <View style={styles.placeTop}>
        <AppText variant="headline" style={styles.flex}>
          Your place
        </AppText>
        <Pill label={state.label} color={state.color} dot />
      </View>

      {enrollment.status === 'requested' && (
        <AppText variant="subheadline" tone="secondary">
          {enrollment.requested_on ? `You asked on ${formatDate(enrollment.requested_on)}. ` : ''}HR decides, and you’re told when they do.
        </AppText>
      )}

      {enrollment.status === 'declined' &&
        (enrollment.decision_note ? (
          <View style={[styles.note, { backgroundColor: colors.fill }]}>
            <AppText variant="footnote" tone="secondary">
              HR’s note
            </AppText>
            <AppText variant="subheadline" selectable>
              {enrollment.decision_note}
            </AppText>
          </View>
        ) : (
          <AppText variant="subheadline" tone="secondary">
            HR declined your request without a note. Ask them if you’d like to know why.
          </AppText>
        ))}

      {enrollment.status === 'dropped' && (
        <AppText variant="subheadline" tone="secondary">
          HR took you off the roster for this program.
        </AppText>
      )}

      {(enrollment.status === 'enrolled' || enrollment.status === 'completed') && (
        <>
          <View style={styles.figures}>
            <Figure value={counted === 0 ? '—' : `${attended} of ${counted}`} label={counted === 0 ? 'No sessions marked yet' : 'Sessions attended'} />
            {rate !== null && <Figure value={`${rate}%`} label="Attendance" />}
            {enrollment.score !== null && <Figure value={String(Math.round(enrollment.score))} label="Score" />}
          </View>
          {held.length > 0 && <AttendanceStrip sittings={held} />}
        </>
      )}

      {enrollment.status === 'completed' && (
        <AppText variant="subheadline" tone="secondary">
          {enrollment.completed_at ? `Completed on ${formatDate(enrollment.completed_at)}.` : 'Completed.'}
        </AppText>
      )}

      {enrollment.certificate_id !== null && (
        <Touchable
          onPress={() => onCertificate(enrollment.certificate_id!)}
          feedback="highlight"
          restColor={colors.fill}
          haptic="selection"
          accessibilityRole="button"
          accessibilityLabel="View your certificate"
          style={[styles.certificateRow, { backgroundColor: colors.fill }]}
        >
          <CertificateThumb standing="valid" width={44} />
          <AppText variant="subheadline" weight="medium" style={styles.flex}>
            View your certificate
          </AppText>
          <Icon name="chevronRight" size={13} color={colors.textTertiary} weight="semibold" />
        </Touchable>
      )}
    </Card>
  );
}

function Figure({ value, label }: { value: string; label: string }) {
  return (
    <View style={styles.figure}>
      <AppText variant="title2" numeric>
        {value}
      </AppText>
      <AppText variant="caption" tone="secondary">
        {label}
      </AppText>
    </View>
  );
}

/** One square a held session, in the colour HR marked it: the attendance at a glance. */
function AttendanceStrip({ sittings }: { sittings: Sitting[] }) {
  const { colors, readable } = useTheme();

  return (
    <View style={styles.strip} accessible accessibilityLabel={sittings.map((s) => `${formatDate(s.session_date)}: ${s.mark ? MARK[s.mark].label : 'not marked'}`).join(', ')}>
      {sittings.map((s) => (
        <View
          key={s.id}
          style={[
            styles.square,
            s.mark
              ? { backgroundColor: readable(MARK[s.mark].color, colors.card, 3) }
              : { borderWidth: 1.5, borderColor: colors.fillStrong, borderStyle: 'dashed' },
          ]}
        />
      ))}
    </View>
  );
}

/** One session: its day, its hours and place, and my mark — or, ahead of it, Add to calendar. */
function SittingRow({ sitting, seated, adding, onAdd }: { sitting: Sitting; seated: boolean; adding: boolean; onAdd: () => void }) {
  const { colors } = useTheme();
  const ink = useLearningInk();
  const { weekday, day } = dayBlock(sitting.session_date);
  const month = MONTHS[parseDateOnly(sitting.session_date).getMonth()];
  const mark = sitting.mark ? MARK[sitting.mark] : null;

  return (
    <ListRow
      leading={
        <View style={styles.dateBlock}>
          <AppText variant="caption2" weight="semibold" tone="secondary">
            {weekday.toUpperCase()}
          </AppText>
          <AppText variant="headline" numeric color={sitting.is_held ? colors.textSecondary : colors.text}>
            {day}
          </AppText>
          <AppText variant="caption2" tone="secondary">
            {month}
          </AppText>
        </View>
      }
      title={sittingTime(sitting.starts_at, sitting.ends_at)}
      subtitle={[sitting.title, sitting.location].filter(Boolean).join(' · ') || undefined}
      accessibilityLabel={[formatDate(sitting.session_date), sittingTime(sitting.starts_at, sitting.ends_at), sitting.title, sitting.location, mark?.label].filter(Boolean).join(', ')}
      accessory={
        mark ? (
          <Pill label={mark.label} color={mark.color} />
        ) : seated && !sitting.is_held ? (
          <Touchable
            onPress={onAdd}
            disabled={adding}
            scaleTo={0.9}
            haptic="light"
            hitSlop={6}
            accessibilityRole="button"
            accessibilityLabel={`Add the ${formatDate(sitting.session_date)} session to your calendar`}
            style={[styles.add, { backgroundColor: ink.wash, opacity: adding ? 0.5 : 1 }]}
          >
            <Icon name="calendarPlus" size={16} color={ink.deep} weight="semibold" />
          </Touchable>
        ) : seated && sitting.is_held ? (
          <AppText variant="footnote" tone="tertiary">
            Not marked
          </AppText>
        ) : undefined
      }
    />
  );
}

/** What the person can do here, with the rule behind it in a line under the button. */
function Actions({
  program,
  onJoin,
  onWithdraw,
}: {
  program: TrainingProgramDetail;
  onJoin: () => void;
  onWithdraw: (enrollment: MyTrainingEnrollment) => void;
}) {
  const mine = program.mine;

  if (program.join === 'join' || program.join === 'ask') {
    return (
      <>
        <Button label={program.join === 'ask' ? 'Ask to join' : 'Join program'} onPress={onJoin} />
        <AppText variant="footnote" tone="secondary" center>
          {program.join === 'ask' ? 'HR decides who joins this one.' : 'You take a seat at once, while seats last.'}
        </AppText>
      </>
    );
  }

  if (program.join === 'full') {
    return (
      <>
        <Button label="Every seat is taken" disabled />
        <AppText variant="footnote" tone="secondary" center>
          A seat may open if someone leaves before it starts.
        </AppText>
      </>
    );
  }

  if (mine?.can_withdraw) {
    return (
      <Button label={mine.status === 'requested' ? 'Withdraw request' : 'Leave program'} variant="destructive" onPress={() => onWithdraw(mine)} />
    );
  }

  if (mine?.status === 'enrolled') {
    return (
      <AppText variant="footnote" tone="secondary" center>
        The program has started, or attendance has been taken. Ask HR if you need to come off the roster.
      </AppText>
    );
  }

  return null;
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  hero: { gap: 14 },
  heroTop: { flexDirection: 'row', gap: 14, alignItems: 'flex-start' },
  heroTitles: { flex: 1, gap: 3, paddingTop: 2 },
  pills: { flexDirection: 'row', alignItems: 'center', gap: 12 },
  facts: { gap: 10, paddingTop: 14, borderTopWidth: StyleSheet.hairlineWidth },
  fact: { flexDirection: 'row', gap: 12 },
  factLabel: { width: 84 },
  place: { gap: 14 },
  placeTop: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  note: { borderRadius: 12, padding: 12, gap: 3, borderCurve: 'continuous' },
  figures: { flexDirection: 'row', gap: 28 },
  figure: { gap: 1 },
  strip: { flexDirection: 'row', flexWrap: 'wrap', gap: 4 },
  square: { width: 14, height: 14, borderRadius: 4, borderCurve: 'continuous' },
  certificateRow: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 10, borderRadius: 14, borderCurve: 'continuous' },
  dateBlock: { width: 40, alignItems: 'center', gap: 1 },
  add: { width: 34, height: 34, borderRadius: 17, alignItems: 'center', justifyContent: 'center' },
  actions: { gap: 10 },
  sheet: { gap: 12 },
});
