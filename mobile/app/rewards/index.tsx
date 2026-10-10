import { Alert, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Section } from '@/components/ui/section';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { recognitionApi } from '@/features/recognition/api';
import { ApiError } from '@/lib/api';
import { formatAgo } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { LedgerLine, Redemption, Reward } from '@/types/api';

const KIND: Record<LedgerLine['kind'], string> = {
  award: 'Award',
  kudos: 'Kudos',
  redemption: 'Reward',
  refund: 'Refund',
  adjustment: 'Adjustment',
};

const REQUEST: Record<Redemption['status'], { label: string; tone: 'late' | 'present' | 'absent' | 'rest' }> = {
  pending: { label: 'Requested', tone: 'late' },
  fulfilled: { label: 'Handed over', tone: 'present' },
  declined: { label: 'Declined', tone: 'absent' },
  cancelled: { label: 'Cancelled', tone: 'rest' },
};

type Data = { balance: number; rewards: Reward[]; redemptions: Redemption[]; history: LedgerLine[] };

/**
 * Points & rewards (ADR 0071): the balance, what it buys, the person's
 * requests, and the lines that explain the balance.
 */
export default function RewardsScreen() {
  const { colors, status, readable } = useTheme();
  const toast = useToast();

  const { data, loading, refreshing, refresh, error, reload } = useQuery<Data>(async () => {
    const [rewards, points] = await Promise.all([recognitionApi.rewards(), recognitionApi.points()]);
    return { ...rewards, history: points.history };
  }, []);

  const redeem = (reward: Reward) =>
    Alert.alert(
      `Redeem ${reward.name}?`,
      `${reward.cost} points come off now. If HR declines it, or you cancel before it’s handed over, they come back.`,
      [
        { text: 'Not now', style: 'cancel' },
        {
          text: 'Redeem',
          onPress: async () => {
            try {
              await recognitionApi.redeem(reward.hashid, null);
              toast.show(`${reward.name} requested — HR will be in touch.`, 'success');
              void reload();
            } catch (e) {
              toast.show(e instanceof ApiError ? e.message : 'Couldn’t redeem it. Try again.', 'error');
            }
          },
        },
      ],
    );

  const cancel = async (redemption: Redemption) => {
    try {
      await recognitionApi.cancel(redemption.id);
      toast.show('Request cancelled — your points are back.', 'success');
      void reload();
    } catch (e) {
      toast.show(e instanceof ApiError ? e.message : 'Couldn’t cancel it. Try again.', 'error');
    }
  };

  return (
    <Page title="Points & Rewards" back refreshing={refreshing} onRefresh={refresh} gap={24}>
      {loading ? (
        <>
          <Skeleton height={90} radius={20} />
          <Skeleton height={120} radius={20} />
        </>
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : data ? (
        <>
          <Animated.View entering={enter(0)} style={styles.balance}>
            <AppText variant="footnote" tone="secondary">
              Your balance
            </AppText>
            <AppText variant="largeTitle" numeric accessibilityLabel={`${data.balance} points`}>
              {data.balance.toLocaleString()}
            </AppText>
            <AppText variant="footnote" tone="secondary">
              Awards and kudos earn points. Spend them here; HR hands the reward over.
            </AppText>
          </Animated.View>

          <Animated.View entering={enter(1)}>
            <Section title="Rewards">
              {data.rewards.length === 0 ? (
                <Card>
                  <AppText variant="subheadline" tone="secondary">
                    HR hasn’t put any rewards up yet. Your points keep until they do.
                  </AppText>
                </Card>
              ) : (
                data.rewards.map((reward) => {
                  const out = reward.stock === 0;
                  return (
                    <Card key={reward.id} style={styles.reward}>
                      <View style={styles.rewardTop}>
                        <View style={styles.flex}>
                          <AppText variant="headline">{reward.name}</AppText>
                          {reward.description && (
                            <AppText variant="footnote" tone="secondary">
                              {reward.description}
                            </AppText>
                          )}
                        </View>
                        <AppText variant="title3" numeric>
                          {reward.cost.toLocaleString()}
                        </AppText>
                      </View>
                      <View style={styles.rewardFoot}>
                        <AppText variant="caption" tone="secondary">
                          {out ? 'Out of stock' : reward.stock !== null ? `${reward.stock} left` : ' '}
                        </AppText>
                        <Button
                          label={out ? 'Out of stock' : reward.affordable ? 'Redeem' : `${(reward.cost - data.balance).toLocaleString()} more needed`}
                          icon="reward"
                          size="sm"
                          variant={reward.affordable && !out ? 'primary' : 'gray'}
                          fullWidth={false}
                          disabled={!reward.affordable || out}
                          onPress={() => redeem(reward)}
                        />
                      </View>
                    </Card>
                  );
                })
              )}
            </Section>
          </Animated.View>

          {data.redemptions.length > 0 && (
            <Animated.View entering={enter(2)}>
              <ListSection header="Your requests">
                {data.redemptions.map((r) => (
                  <ListRow
                    key={r.id}
                    title={r.reward?.name ?? 'A reward'}
                    subtitle={[`${r.cost} points`, formatAgo(r.created_at), r.response_note].filter(Boolean).join(' · ')}
                    accessory={
                      r.status === 'pending' ? (
                        <AppText variant="body" tone="tint" onPress={() => void cancel(r)} accessibilityRole="button">
                          Cancel
                        </AppText>
                      ) : (
                        <Pill label={REQUEST[r.status].label} color={status[REQUEST[r.status].tone]} on={colors.card} dot />
                      )
                    }
                  />
                ))}
              </ListSection>
            </Animated.View>
          )}

          <Animated.View entering={enter(3)}>
            <ListSection header="Where your points came from" footer={data.history.length === 0 ? 'Awards and kudos from colleagues add points here.' : undefined}>
              {data.history.map((line) => (
                <ListRow
                  key={line.id}
                  title={line.note ?? KIND[line.kind]}
                  subtitle={`${KIND[line.kind]} · ${formatAgo(line.at)}`}
                  accessory={
                    <AppText variant="headline" numeric color={line.amount > 0 ? readable(status.present, colors.card, 4.5) : colors.textSecondary}>
                      {line.amount > 0 ? '+' : '−'}
                      {Math.abs(line.amount).toLocaleString()}
                    </AppText>
                  }
                />
              ))}
            </ListSection>
          </Animated.View>
        </>
      ) : null}
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  balance: { gap: 2, paddingHorizontal: 4 },
  reward: { gap: 12 },
  rewardTop: { flexDirection: 'row', gap: 12, alignItems: 'flex-start' },
  rewardFoot: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 10 },
});
