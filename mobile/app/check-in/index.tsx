import { LinearGradient } from 'expo-linear-gradient';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { checkInsApi } from '@/features/check-ins/api';
import { MoodFace } from '@/features/check-ins/components/mood-face';
import { MoodYear } from '@/features/check-ins/components/mood-year';
import { answerLabel, monthName, MOOD_WORDS } from '@/features/check-ins/meta';
import { useReloadOnReturn } from '@/features/training/use-reload-on-return';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { CheckInOverview, MyCheckIn } from '@/types/api';

/**
 * My check-in (ADR 0102), the web's on the phone: this month on the screen's one
 * hero card (waiting, or how it was told with a way to change it), the last twelve
 * months as a row of faces, the chosen month's answers, and who can see them.
 */
export default function CheckInScreen() {
  const router = useRouter();
  const { spacing } = useTheme();
  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => checkInsApi.overview(), []);
  useReloadOnReturn(reload);
  const overview = data?.data ?? null;
  const [picked, setPicked] = useState<string | null>(null);

  const shownPeriod = picked ?? overview?.history[0]?.period ?? null;
  const shown = overview?.history.find((c) => c.period === shownPeriod) ?? null;

  return (
    <Page title="Check-in" back refreshing={refreshing} onRefresh={refresh} gap={24}>
      {loading ? (
        <>
          <Skeleton height={220} radius={28} />
          <Skeleton height={80} radius={20} />
        </>
      ) : error && !overview ? (
        <ErrorState message={error} onRetry={reload} />
      ) : !overview ? null : !overview.has_employee ? (
        <EmptyState icon="person" title="Not linked to an employee" message="Check-ins belong to employees. Ask HR to link your account." />
      ) : !overview.enabled && overview.history.length === 0 ? (
        <EmptyState icon="info" title="Check-ins are off" message="Your company hasn’t turned on monthly check-ins." />
      ) : (
        <>
          <Animated.View entering={enter(0)}>
            <ThisMonth overview={overview} onAnswer={(edit) => router.push({ pathname: '/check-in/answer', params: edit ? { edit: '1' } : {} })} />
          </Animated.View>

          {overview.history.length > 0 && (
            <Animated.View entering={enter(1)} style={{ gap: spacing.sm }}>
              <View style={styles.heading}>
                <AppText variant="headline">Your year</AppText>
                <AppText variant="footnote" tone="secondary">
                  {overview.history.length} of 12 months
                </AppText>
              </View>
              <Card>
                <MoodYear
                  months={overview.months}
                  history={overview.history}
                  selected={shownPeriod}
                  current={overview.period}
                  onSelect={setPicked}
                />
              </Card>
              {shown && <MonthAnswers checkIn={shown} overview={overview} />}
            </Animated.View>
          )}

          <Animated.View entering={enter(2)}>
            <ListSection header="Who sees your check-in" withIcons>
              <ListRow icon="eye" title="You" subtitle="Your answers and your notes, every month." wrap />
              <ListRow
                icon="people"
                title="HR and department heads"
                subtitle="Results for groups of five or more, never one person’s."
                wrap
              />
              <ListRow icon="eyeSlash" title="Nobody else" subtitle="Not your record, not the assistant, not exports." wrap />
            </ListSection>
          </Animated.View>
        </>
      )}
    </Page>
  );
}

/** This month on the hero card: waiting, or as it was told. */
function ThisMonth({ overview, onAnswer }: { overview: CheckInOverview; onAnswer: (edit: boolean) => void }) {
  const { colors, radius, squircle } = useTheme();
  const current = overview.current;
  const month = monthName(overview.period);
  const days = `${overview.days_left} ${overview.days_left === 1 ? 'day' : 'days'}`;

  return (
    <LinearGradient
      colors={colors.hero}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={[styles.hero, { borderRadius: radius.xl }, squircle]}
    >
      <View style={styles.heroTop}>
        <View style={styles.flex}>
          <AppText variant="footnote" color={colors.onHeroSecondary}>
            {overview.period_label}
          </AppText>
          <AppText variant="title1" color={colors.onHero}>
            {current ? MOOD_WORDS[current.answers.mood - 1] : `How’s ${month} going?`}
          </AppText>
          <AppText variant="subheadline" color={colors.onHeroSecondary}>
            {current
              ? `Checked in. You can change it for ${days} more.`
              : overview.enabled
                ? `Six quick questions, about a minute. ${days} left.`
                : 'Check-ins are turned off.'}
          </AppText>
        </View>
        <MoodFace mood={current?.answers.mood ?? null} size={92} />
      </View>
      {overview.enabled && (
        <Button
          label={current ? 'Change my answers' : 'Check in'}
          variant={current ? 'gray' : 'tint'}
          onPress={() => onAnswer(!!current)}
          fullWidth
        />
      )}
    </LinearGradient>
  );
}

/** A month's answers, as it was told. */
function MonthAnswers({ checkIn, overview }: { checkIn: MyCheckIn; overview: CheckInOverview }) {
  const options = (key: string) => overview.questions.find((q) => q.key === key)?.options;

  return (
    <ListSection header={monthName(checkIn.period, true)}>
      <ListRow title="Mood" value={MOOD_WORDS[checkIn.answers.mood - 1]} />
      <ListRow title="Job satisfaction" value={answerLabel(options('satisfaction'), checkIn.answers.satisfaction)} />
      <ListRow title="Engagement" value={`${checkIn.engagement.toFixed(1)} of 6`} />
      <ListRow title="Considered leaving" value={answerLabel(options('leave_thoughts'), checkIn.answers.leave_thoughts)} />
      {checkIn.note ? <ListRow title={checkIn.note} subtitle="Your note · only you can read it" wrap /> : null}
    </ListSection>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  heading: { flexDirection: 'row', alignItems: 'baseline', justifyContent: 'space-between', paddingHorizontal: 4 },
  hero: { padding: 20, gap: 18 },
  heroTop: { flexDirection: 'row', alignItems: 'center', gap: 12 },
});
