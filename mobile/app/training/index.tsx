import { LinearGradient } from 'expo-linear-gradient';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useMemo, useState } from 'react';
import { Linking, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';
import Svg, { Path } from 'react-native-svg';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { Touchable } from '@/components/ui/touchable';
import { groupOf } from '@/features/services/catalog';
import { ServiceIcon } from '@/features/services/components/service-glyph';
import { trainingApi } from '@/features/training/api';
import { CertificateThumb } from '@/features/training/components/certificate-art';
import { DateLeaf } from '@/features/training/components/program-parts';
import { dateRange, daysFromToday, ENROLLMENT_STATUS, sittingTime, STANDING } from '@/features/training/meta';
import { useReloadOnReturn } from '@/features/training/use-reload-on-return';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatWeekday, parseDateOnly } from '@/lib/format';
import { enter, fadeIn } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { MyTrainingEnrollment, MyTrainingSession, TrainingCertificate } from '@/types/api';

type Tab = 'sessions' | 'programs' | 'certificates';

const TABS: Tab[] = ['sessions', 'programs', 'certificates'];
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** The order programs are listed in, by where the person stands on them. */
const PROGRAM_GROUPS: { title: string; statuses: MyTrainingEnrollment['status'][] }[] = [
  { title: 'Taking part', statuses: ['enrolled'] },
  { title: 'Waiting for HR', statuses: ['requested'] },
  { title: 'Finished', statuses: ['completed', 'dropped'] },
  { title: 'Declined', statuses: ['declined'] },
];

/**
 * My training (ADR 0094), the web's My training on the phone: the next session
 * on the screen's one navy card, then three views — the sessions coming up as an
 * agenda, the programs by where the person stands on each, and the certificates
 * they hold. `?tab=certificates` opens on those (the Certificates service).
 */
export default function MyTrainingScreen() {
  const params = useLocalSearchParams<{ tab?: string }>();
  const router = useRouter();
  const [tab, setTab] = useState<Tab>(TABS.includes(params.tab as Tab) ? (params.tab as Tab) : 'sessions');

  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => trainingApi.mine(), []);
  useReloadOnReturn(reload);

  const mine = data?.data ?? null;
  const next = mine?.sessions[0] ?? null;
  const empty = !!mine && mine.enrollments.length === 0 && mine.certificates.length === 0;

  const openProgram = (hashid: string | null | undefined) => hashid && router.push({ pathname: '/training/[id]', params: { id: hashid } });

  return (
    <Page title="My training" back refreshing={refreshing} onRefresh={refresh} gap={20}>
      {loading ? (
        <>
          <Skeleton height={190} radius={28} />
          <Skeleton height={34} radius={10} />
          <Skeleton height={240} radius={20} />
        </>
      ) : error && !mine ? (
        <ErrorState message={error} onRetry={reload} />
      ) : empty ? (
        <EmptyState
          icon="training"
          title="No training yet"
          message="Programs you join, or HR puts you on, show up here with their sessions and your certificates."
          action={<Button label="Browse training" variant="tinted" size="sm" fullWidth={false} onPress={() => router.push('/training/browse')} />}
        />
      ) : mine ? (
        <>
          {next && (
            <Animated.View entering={enter(0)}>
              <NextSession session={next} onOpen={() => openProgram(next.program.hashid)} />
            </Animated.View>
          )}

          <Segmented
            options={[
              { value: 'sessions', label: 'Coming up' },
              { value: 'programs', label: 'Programs' },
              { value: 'certificates', label: 'Certificates' },
            ]}
            value={tab}
            onChange={setTab}
            accessibilityLabel="Which training"
          />

          <Animated.View key={tab} entering={fadeIn} style={styles.tab}>
            {tab === 'sessions' && <Agenda sessions={mine.sessions} onOpen={(s) => openProgram(s.program.hashid)} />}
            {tab === 'programs' && <Programs enrollments={mine.enrollments} onOpen={(e) => openProgram(e.program.hashid)} />}
            {tab === 'certificates' && (
              <Certificates
                certificates={mine.certificates}
                expiringDays={mine.expiring_days}
                onOpen={(c) => router.push({ pathname: '/training/certificate/[id]', params: { id: String(c.id) } })}
              />
            )}
          </Animated.View>

          <ListSection leadingWidth={30}>
            <ListRow
              leading={<ServiceIcon glyph="books" hue={groupOf('learning').hue} size={30} />}
              title="Browse training"
              subtitle="Programs open for you to join"
              onPress={() => router.push('/training/browse')}
            />
          </ListSection>
        </>
      ) : null}
    </Page>
  );
}

/**
 * The next session, on navy: what and when, how soon, where, and the phone's
 * calendar one tap away. A mortarboard is drawn faintly into the corner.
 */
function NextSession({ session, onOpen }: { session: MyTrainingSession; onOpen: () => void }) {
  const { colors, radius, squircle } = useTheme();
  const { organization } = useAuth();
  const toast = useToast();
  const [adding, setAdding] = useState(false);
  const days = daysFromToday(session.session_date, organization?.timezone);
  const when = days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : `${formatWeekday(session.session_date)}, in ${days} days`;

  const add = async () => {
    setAdding(true);
    try {
      const link = await trainingApi.calendarLink(session.id);
      await Linking.openURL(link.data.url);
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t open your calendar. Try again.', 'error');
    } finally {
      setAdding(false);
    }
  };

  return (
    <LinearGradient colors={colors.hero} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={[styles.hero, squircle, { borderRadius: radius.xl }]}>
      <Svg width={190} height={150} style={styles.heroArt} pointerEvents="none">
        <Path d="M95 18 L178 58 L95 98 L12 58 Z" stroke="rgba(255,255,255,0.08)" strokeWidth={1.5} fill="none" />
        <Path d="M45 74 V108 C45 124 68 136 95 136 C122 136 145 124 145 108 V74" stroke="rgba(46,157,87,0.38)" strokeWidth={1.5} fill="none" />
        <Path d="M95 58 L150 72 V112" stroke="rgba(255,255,255,0.1)" strokeWidth={1.5} fill="none" strokeLinecap="round" />
      </Svg>

      <AppText variant="footnote" weight="semibold" color={colors.onHeroSecondary}>
        Next session · {when}
      </AppText>
      <AppText variant="title2" color={colors.onHero} numberOfLines={2} style={styles.heroTitle}>
        {session.program.name ?? 'Training'}
      </AppText>
      <AppText variant="subheadline" color={colors.onHeroSecondary} numeric>
        {formatDate(session.session_date)}, {sittingTime(session.starts_at, session.ends_at)}
      </AppText>
      {(session.title || session.location) && (
        <AppText variant="subheadline" color={colors.onHeroSecondary} numberOfLines={2}>
          {[session.title, session.location].filter(Boolean).join(', ')}
        </AppText>
      )}

      <View style={styles.heroActions}>
        <Button label="Add to calendar" icon="calendarPlus" variant="tint" size="sm" fullWidth={false} loading={adding} onPress={() => void add()} />
        {/* Bright teal: the plain button's ink is cut for white cards, not for navy. */}
        <Touchable onPress={onOpen} feedback="opacity" hitSlop={10} accessibilityRole="button" style={styles.heroLink}>
          <AppText variant="subheadline" weight="semibold" color={palette.teal}>
            Open program
          </AppText>
        </Touchable>
      </View>
    </LinearGradient>
  );
}

/** The sessions coming up, day by day, as My events lists invitations. */
function Agenda({ sessions, onOpen }: { sessions: MyTrainingSession[]; onOpen: (s: MyTrainingSession) => void }) {
  const { colors } = useTheme();
  const { organization } = useAuth();

  const days = useMemo(() => {
    const byDay: { date: string; items: MyTrainingSession[] }[] = [];
    for (const session of sessions) {
      const last = byDay.at(-1);
      if (last && last.date === session.session_date) last.items.push(session);
      else byDay.push({ date: session.session_date, items: [session] });
    }
    return byDay;
  }, [sessions]);

  if (days.length === 0) {
    return <EmptyState icon="calendar" title="No sessions coming up" message="Sessions of the programs you’re on show here once HR sets them." />;
  }

  return (
    <>
      {days.map((day, index) => {
        const date = parseDateOnly(day.date);
        const today = daysFromToday(day.date, organization?.timezone) === 0;

        return (
          <Animated.View key={day.date} entering={enter(index)} style={styles.day}>
            <View style={styles.dayHead}>
              <AppText variant="footnote" weight="semibold" color={today ? colors.tintText : colors.textSecondary}>
                {today ? 'Today' : formatWeekday(day.date).slice(0, 3)}
              </AppText>
              <AppText variant="title2" numeric>
                {date.getDate()}
              </AppText>
              <AppText variant="footnote" tone="secondary">
                {MONTHS[date.getMonth()]}
              </AppText>
            </View>
            <View style={styles.cards}>
              {day.items.map((session) => (
                <Card
                  key={session.id}
                  onPress={() => onOpen(session)}
                  accessibilityLabel={`${session.program.name}, ${sittingTime(session.starts_at, session.ends_at)}`}
                  style={styles.card}
                >
                  <AppText variant="headline" numberOfLines={2}>
                    {session.program.name}
                  </AppText>
                  <AppText variant="subheadline" tone="secondary" numeric>
                    {sittingTime(session.starts_at, session.ends_at)}
                    {session.location ? ` · ${session.location}` : ''}
                  </AppText>
                  {session.title && (
                    <AppText variant="footnote" tone="secondary" numberOfLines={1}>
                      {session.title}
                    </AppText>
                  )}
                </Card>
              ))}
            </View>
          </Animated.View>
        );
      })}
    </>
  );
}

/** The person's programs, grouped by where they stand on each. */
function Programs({ enrollments, onOpen }: { enrollments: MyTrainingEnrollment[]; onOpen: (e: MyTrainingEnrollment) => void }) {
  if (enrollments.length === 0) {
    return <EmptyState icon="training" title="No programs yet" message="Join one from Browse, or HR may put you on one." />;
  }

  return (
    <>
      {PROGRAM_GROUPS.map((group) => {
        const rows = enrollments.filter((e) => group.statuses.includes(e.status));
        if (rows.length === 0) return null;

        return (
          <ListSection key={group.title} header={group.title} leadingWidth={40}>
            {rows.map((enrollment) => (
              <ProgramRow key={enrollment.id} enrollment={enrollment} onPress={() => onOpen(enrollment)} />
            ))}
          </ListSection>
        );
      })}
    </>
  );
}

function ProgramRow({ enrollment, onPress }: { enrollment: MyTrainingEnrollment; onPress: () => void }) {
  const state = ENROLLMENT_STATUS[enrollment.status];
  const { attended, counted } = enrollment.attendance;
  const { program } = enrollment;

  const detail =
    enrollment.status === 'completed' && enrollment.score !== null
      ? `Score ${Math.round(enrollment.score)}`
      : enrollment.status === 'enrolled' && counted > 0
        ? `Attended ${attended} of ${counted}`
        : enrollment.status === 'declined' && enrollment.decision_note
          ? `HR: ${enrollment.decision_note}`
          : dateRange(program.start_date, program.end_date);

  return (
    <ListRow
      leading={<DateLeaf date={program.start_date} size={40} />}
      title={program.name}
      subtitle={[program.provider, detail].filter(Boolean).join(' · ')}
      // The group's header names the status; only Finished mixes two.
      accessory={enrollment.status === 'dropped' ? <Pill label={state.label} color={state.color} /> : undefined}
      onPress={onPress}
      accessibilityLabel={`${program.name}, ${state.label}, ${detail}`}
    />
  );
}

/** The certificates the person holds, each with its standing. */
function Certificates({
  certificates,
  expiringDays,
  onOpen,
}: {
  certificates: TrainingCertificate[];
  expiringDays: number;
  onOpen: (c: TrainingCertificate) => void;
}) {
  if (certificates.length === 0) {
    return (
      <EmptyState
        icon="certificate"
        title="No certificates yet"
        message="Completing a program that issues one earns you a certificate. It shows here, ready to show anyone."
      />
    );
  }

  return (
    <ListSection
      leadingWidth={52}
      footer={`You’re told once when a certificate is ${expiringDays} days from expiring. HR renews it by enrolling you again.`}
    >
      {certificates.map((certificate) => {
        const standing = STANDING[certificate.standing];
        const when = certificateWhen(certificate);

        return (
          <ListRow
            key={certificate.id}
            leading={<CertificateThumb standing={certificate.standing} badge />}
            title={certificate.name}
            subtitle={when}
            onPress={() => onOpen(certificate)}
            accessibilityLabel={`${certificate.name}, ${standing.label}, ${when}`}
          />
        );
      })}
    </ListSection>
  );
}

/** A certificate's standing and its date, in words: "Expires soon, Nov 8, 2026". */
function certificateWhen(certificate: TrainingCertificate): string {
  const expiry = certificate.expiry_date ? formatDate(certificate.expiry_date) : null;

  switch (certificate.standing) {
    case 'renewed':
      return 'Renewed since';
    case 'expired':
      return `Expired ${expiry}`;
    case 'expiring':
      return `Expires soon, ${expiry}`;
    default:
      return expiry ? `Valid until ${expiry}` : 'Valid, never expires';
  }
}

const styles = StyleSheet.create({
  tab: { gap: 20 },
  hero: { padding: 20, overflow: 'hidden', gap: 2 },
  heroArt: { position: 'absolute', right: -42, top: -14 },
  heroTitle: { marginTop: 8, marginBottom: 4 },
  heroActions: { flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 18 },
  heroLink: { paddingHorizontal: 12, paddingVertical: 8 },
  day: { flexDirection: 'row', gap: 12 },
  dayHead: { width: 44, alignItems: 'center', paddingTop: 4 },
  cards: { flex: 1, gap: 10 },
  card: { gap: 4 },
});

