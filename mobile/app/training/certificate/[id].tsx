import { useLocalSearchParams, useRouter } from 'expo-router';
import { Share, StyleSheet } from 'react-native';
import Animated from 'react-native-reanimated';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/empty-state';
import { ListRow, ListSection } from '@/components/ui/list';
import { Page } from '@/components/ui/page';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/skeleton';
import { AppText } from '@/components/ui/text';
import { trainingApi } from '@/features/training/api';
import { CertificateArt } from '@/features/training/components/certificate-art';
import { STANDING, standingNote } from '@/features/training/meta';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import { enter } from '@/lib/motion';
import { useQuery } from '@/lib/use-query';
import type { CertificateDetail } from '@/types/api';

/**
 * One of the person's certificates (ADR 0094), drawn as the certificate it is,
 * with its standing in words and the details under it. Shared as text — the
 * person's own choice of where it goes.
 */
export default function CertificateScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { user, organization } = useAuth();

  const { data, loading, refreshing, refresh, error, reload } = useQuery(() => trainingApi.certificate(id), [id]);
  const certificate = data?.data ?? null;
  const holder = certificate?.employee?.full_name ?? user?.employee?.full_name ?? user?.name ?? '';

  const share = (c: CertificateDetail) => {
    const facts = [
      c.certificate_no ? `Certificate ${c.certificate_no}` : 'Certificate',
      c.issued_date ? `issued ${formatDate(c.issued_date)}` : null,
      c.expiry_date ? `valid until ${formatDate(c.expiry_date)}` : 'with no expiry',
    ].filter(Boolean);

    void Share.share({ message: `${holder} completed ${c.name}${c.issuer ? ` (${c.issuer})` : ''}. ${facts.join(', ')}.` });
  };

  return (
    <Page title={certificate?.name ?? 'Certificate'} largeTitle={false} back refreshing={refreshing} onRefresh={refresh} gap={20}>
      {loading ? (
        <>
          <Skeleton height={250} radius={14} />
          <Skeleton height={90} radius={20} />
          <Skeleton height={200} radius={20} />
        </>
      ) : error && !certificate ? (
        <ErrorState message={error} onRetry={reload} />
      ) : certificate ? (
        <>
          <Animated.View entering={enter(0)}>
            <CertificateArt
              holder={holder}
              name={certificate.name}
              issuer={certificate.issuer}
              organization={certificate.organization}
              issued={certificate.issued_date}
              expiry={certificate.expiry_date}
              number={certificate.certificate_no}
              standing={certificate.standing}
              stampIn
            />
          </Animated.View>

          <Animated.View entering={enter(1)}>
            <Card style={styles.standing}>
              <Pill label={STANDING[certificate.standing].label} color={STANDING[certificate.standing].color} dot />
              <AppText variant="subheadline" tone="secondary">
                {standingNote(certificate.standing, certificate.expiry_date, organization?.timezone)}
              </AppText>
            </Card>
          </Animated.View>

          <Animated.View entering={enter(2)}>
            <ListSection footer="Training certificates are issued when HR marks the program completed. They’re withdrawn in Training, never from your record.">
              {certificate.certificate_no && <ListRow title="Certificate no." value={certificate.certificate_no} />}
              <ListRow title="Issued" value={formatDate(certificate.issued_date)} />
              <ListRow title="Expires" value={certificate.expiry_date ? formatDate(certificate.expiry_date) : 'Never'} />
              {certificate.issuer && <ListRow title="Issued by" value={certificate.issuer} wrap />}
              {certificate.score !== null && <ListRow title="Your score" value={String(Math.round(certificate.score))} />}
              {certificate.program &&
                (certificate.program.archived ? (
                  <ListRow title="Program" value="No longer offered" />
                ) : (
                  <ListRow
                    title="Program"
                    value={certificate.program.name}
                    onPress={() => router.push({ pathname: '/training/[id]', params: { id: certificate.program!.hashid } })}
                  />
                ))}
            </ListSection>
          </Animated.View>

          <Animated.View entering={enter(3)} style={styles.actions}>
            <Button label="Share" icon="share" variant="gray" onPress={() => share(certificate)} />
          </Animated.View>
        </>
      ) : null}
    </Page>
  );
}

const styles = StyleSheet.create({
  standing: { gap: 8 },
  actions: { gap: 10 },
});

