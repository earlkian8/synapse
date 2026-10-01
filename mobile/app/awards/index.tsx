import { Ionicons } from '@expo/vector-icons';
import { RefreshControl, ScrollView, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Screen, ScreenHeader } from '@/components/ui/screen';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { awardsApi } from '@/features/awards/api';
import { formatDate } from '@/lib/format';
import { useQuery } from '@/lib/use-query';
import { composite, withAlpha } from '@/theme/color';
import { useTheme } from '@/theme/theme';
import { status as statusTones } from '@/theme/tokens';
import type { Award } from '@/types/api';

export default function AwardsScreen() {
  const { colors, spacing, readable } = useTheme();

  const { data, loading, refreshing, refresh } = useQuery<{ data: Award[] }>(() => awardsApi.list(), []);
  const awards = data?.data ?? [];

  return (
    <Screen edges={['top', 'bottom']}>
      <ScreenHeader title="Awards" subtitle="Your recognition" back />

      <ScrollView
        contentContainerStyle={{ padding: spacing.lg, paddingBottom: 40, gap: spacing.md }}
        showsVerticalScrollIndicator={false}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} tintColor={colors.accent} />}
      >
        {loading ? (
          <>
            <Skeleton height={110} radius={18} />
            <Skeleton height={110} radius={18} />
          </>
        ) : awards.length === 0 ? (
          <EmptyState
            icon="trophy-outline"
            title="No awards yet"
            message="Recognition you receive from your team will appear here."
          />
        ) : (
          <>
            {/* Count banner */}
            <Card elevated style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
              <View
                style={{
                  width: 52,
                  height: 52,
                  borderRadius: 16,
                  backgroundColor: withAlpha(statusTones.late, 0.14),
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <Ionicons
                  name="trophy"
                  size={26}
                  color={readable(statusTones.late, composite(statusTones.late, 0.14, colors.card))}
                />
              </View>
              <View>
                <AppText variant="title" style={{ fontVariant: ['tabular-nums'] }}>
                  {awards.length}
                </AppText>
                <AppText variant="caption" muted>
                  {awards.length === 1 ? 'recognition' : 'recognitions'} received
                </AppText>
              </View>
            </Card>

            {awards.map((award, index) => {
              const tint = award.award_type?.color ?? statusTones.late;
              return (
                <Animated.View key={award.id} entering={FadeIn.duration(320).delay(index * 50)}>
                  <Card style={{ gap: spacing.md }}>
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: spacing.md }}>
                      <View
                        style={{
                          width: 46,
                          height: 46,
                          borderRadius: 14,
                          backgroundColor: withAlpha(tint, 0.16),
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}
                      >
                        <Ionicons name="ribbon" size={22} color={readable(tint, composite(tint, 0.16, colors.card))} />
                      </View>
                      <View style={{ flex: 1 }}>
                        <AppText variant="heading">{award.award_type?.name ?? 'Award'}</AppText>
                        <AppText variant="caption" faint>
                          {formatDate(award.awarded_on)}
                          {award.granted_by ? ` · by ${award.granted_by.name}` : ''}
                        </AppText>
                      </View>
                    </View>
                    {award.reason && (
                      <AppText variant="body" muted>
                        “{award.reason}”
                      </AppText>
                    )}
                  </Card>
                </Animated.View>
              );
            })}
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
