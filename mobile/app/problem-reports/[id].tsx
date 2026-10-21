import { Image } from 'expo-image';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Modal, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import Animated from 'react-native-reanimated';

import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/icon';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { ToneWell } from '@/components/ui/tone-well';
import { Touchable } from '@/components/ui/touchable';
import { problemReportsApi } from '@/features/problem-reports/api';
import { ReportProgress } from '@/features/problem-reports/components/report-progress';
import { categoryIcon, reportStatusColor } from '@/features/problem-reports/meta';
import { fileSource } from '@/lib/api';
import { formatAgo, formatDate } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import { useTheme } from '@/theme/theme';
import type { ProblemReport } from '@/types/api';

/**
 * One of one's own problem reports: where it stands, the SYNAPSE team's reply,
 * what was sent, and the screenshots. Read-only — a new problem is a new report.
 */
export default function ProblemReportScreen() {
  const { colors, radius } = useTheme();
  const insets = useSafeAreaInsets();
  const { id } = useLocalSearchParams<{ id: string }>();
  // A screenshot opened full size. Uploads are private (ADR 0087), so it is
  // shown here, with the token, rather than handed to the browser.
  const [viewing, setViewing] = useState<string | null>(null);
  const { data, loading, refreshing, refresh, error, reload } = useQuery<{ data: ProblemReport }>(
    () => problemReportsApi.show(id),
    [id],
  );
  const report = data?.data ?? null;

  return (
    <Page title="Report" largeTitle={false} back refreshing={refreshing} onRefresh={refresh}>
      {loading ? (
        <>
          <Skeleton height={200} radius={20} />
          <Skeleton height={120} radius={20} />
        </>
      ) : error && !report ? (
        <ErrorState message={error} onRetry={reload} />
      ) : report ? (
        <>
          <Animated.View entering={enter(0)}>
            <Card style={styles.hero}>
              <View style={styles.heroTop}>
                <ToneWell icon={categoryIcon(report.category)} color={reportStatusColor(report.status)} size={44} />
                <Pill label={report.status_label} color={reportStatusColor(report.status)} dot />
              </View>
              <View style={styles.heroText}>
                <AppText variant="title3">{report.subject}</AppText>
                <AppText variant="footnote" tone="secondary">
                  {report.reference} · sent {formatDate(report.created_at)}
                </AppText>
              </View>
              <View style={[styles.progress, { borderTopColor: colors.separator }]}>
                <ReportProgress report={report} />
              </View>
            </Card>
          </Animated.View>

          <Animated.View entering={enter(1)}>
            <ListSection header="Reply from the SYNAPSE team">
              {report.reply ? (
                <View style={styles.replyWrap}>
                  <View style={[styles.replyBar, { backgroundColor: colors.tint }]} />
                  <View style={styles.flex}>
                    <AppText variant="body" selectable>
                      {report.reply}
                    </AppText>
                    <AppText variant="footnote" tone="secondary" style={styles.replyWhen}>
                      {formatAgo(report.replied_at)}
                    </AppText>
                  </View>
                </View>
              ) : (
                <View style={styles.block}>
                  <AppText variant="subheadline" tone="secondary">
                    {report.is_open
                      ? 'No reply yet. You’ll get a notification when there is one.'
                      : 'The team closed this without a reply.'}
                  </AppText>
                </View>
              )}
            </ListSection>
          </Animated.View>

          <Animated.View entering={enter(2)}>
            <ListSection header="What you wrote">
              <View style={styles.block}>
                <AppText variant="body" selectable>
                  {report.description}
                </AppText>
              </View>
            </ListSection>
          </Animated.View>

          {report.attachments.length > 0 && (
            <Animated.View entering={enter(3)} style={styles.shots}>
              <AppText variant="footnote" tone="secondary" style={styles.shotsLabel}>
                SCREENSHOTS
              </AppText>
              <View style={styles.thumbs}>
                {report.attachments.map((attachment) => (
                  <Touchable
                    key={attachment.id}
                    scaleTo={0.97}
                    onPress={() => setViewing(attachment.url)}
                    accessibilityRole="imagebutton"
                    accessibilityLabel={`Open screenshot ${attachment.name}`}
                  >
                    <View style={[styles.thumb, { borderRadius: radius.md, backgroundColor: colors.fill }]}>
                      <Image
                        source={fileSource(attachment.url)}
                        style={StyleSheet.absoluteFill}
                        contentFit="cover"
                        contentPosition="top"
                        transition={200}
                      />
                    </View>
                  </Touchable>
                ))}
              </View>
            </Animated.View>
          )}

          <Animated.View entering={enter(4)}>
            <ListSection header="Details">
              <ListRow title="Kind" value={report.category_label} />
              <ListRow title="Effect on your work" value={report.impact_label} />
              {report.page ? <ListRow title="Where" value={report.page} /> : null}
              <ListRow
                title="Sent from"
                value={report.app_version ? `${report.platform_label} ${report.app_version}` : report.platform_label}
              />
            </ListSection>
          </Animated.View>
        </>
      ) : null}

      <Modal visible={viewing !== null} animationType="fade" onRequestClose={() => setViewing(null)} statusBarTranslucent>
        <View style={styles.viewer}>
          {viewing && (
            <Image source={fileSource(viewing)} style={StyleSheet.absoluteFill} contentFit="contain" transition={150} />
          )}
          <Touchable
            onPress={() => setViewing(null)}
            hitSlop={10}
            scaleTo={0.9}
            accessibilityRole="button"
            accessibilityLabel="Close screenshot"
            style={[styles.viewerClose, { top: insets.top + 12 }]}
          >
            <View style={styles.viewerCloseFace}>
              <Icon name="close" size={16} color="#FFFFFF" weight="semibold" />
            </View>
          </Touchable>
        </View>
      </Modal>
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  hero: { gap: 14 },
  heroTop: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  heroText: { gap: 4 },
  progress: { borderTopWidth: StyleSheet.hairlineWidth, paddingTop: 16 },
  block: { padding: 16 },
  replyWrap: { flexDirection: 'row', gap: 12, padding: 16 },
  replyBar: { width: 3, borderRadius: 2 },
  replyWhen: { marginTop: 8 },
  shots: { gap: 8 },
  shotsLabel: { marginLeft: 16, letterSpacing: 0.2 },
  thumbs: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  thumb: { width: 104, height: 104, overflow: 'hidden', borderCurve: 'continuous' },
  viewer: { flex: 1, backgroundColor: '#000000' },
  viewerClose: { position: 'absolute', right: 16 },
  viewerCloseFace: {
    width: 36,
    height: 36,
    borderRadius: 18,
    backgroundColor: 'rgba(255,255,255,0.18)',
    alignItems: 'center',
    justifyContent: 'center',
  },
});
