import Constants from 'expo-constants';
import { Image } from 'expo-image';
import * as ImagePicker from 'expo-image-picker';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Alert, ScrollView, StyleSheet, useWindowDimensions, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { Icon } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { Sheet } from '@/components/ui/sheet';
import { AppText } from '@/components/ui/text';
import { useToast } from '@/components/ui/toast';
import { Touchable } from '@/components/ui/touchable';
import { deviceLabel, problemReportsApi, type NewProblemReport } from '@/features/problem-reports/api';
import { appAreas, CATEGORIES, IMPACTS } from '@/features/problem-reports/meta';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { enter } from '@/lib/motion';
import { useTheme } from '@/theme/theme';
import type { ProblemReport } from '@/types/api';

const MAX_SCREENSHOTS = 3;
const MAX_BYTES = 8 * 1024 * 1024;
const THUMB = 84;

type Shot = NewProblemReport['screenshots'][number];

/**
 * Report a problem to the SYNAPSE team (ADR 0096), as a form sheet: what kind,
 * a summary, what happened, where in the app, how much it is in the way, and
 * up to three screenshots from the photo library. It goes to the people who
 * run SYNAPSE, not to the company, and the sheet says what is sent with it.
 */
export default function NewProblemReportScreen() {
  const { colors, radius, status } = useTheme();
  const router = useRouter();
  const toast = useToast();
  const { user } = useAuth();
  const { height } = useWindowDimensions();

  const [category, setCategory] = useState<ProblemReport['category'] | null>(null);
  const [impact, setImpact] = useState<ProblemReport['impact'] | null>(null);
  const [subject, setSubject] = useState('');
  const [description, setDescription] = useState('');
  const [area, setArea] = useState<string | null>(null);
  const [shots, setShots] = useState<Shot[]>([]);
  const [picking, setPicking] = useState(false);
  const [areasOpen, setAreasOpen] = useState(false);
  const [sending, setSending] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const ready = !!category && !!impact && subject.trim().length >= 4 && description.trim().length >= 10;
  const started = !!category || !!impact || subject.trim() !== '' || description.trim() !== '' || shots.length > 0;

  const cancel = () => {
    if (!started || sending) {
      router.back();
      return;
    }

    Alert.alert('Discard this report?', 'What you’ve written won’t be sent.', [
      { text: 'Keep writing', style: 'cancel' },
      { text: 'Discard', style: 'destructive', onPress: () => router.back() },
    ]);
  };

  const addScreenshots = async () => {
    setPicking(true);

    try {
      const result = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        allowsMultipleSelection: true,
        selectionLimit: MAX_SCREENSHOTS - shots.length,
        // Below 1, iOS hands back a JPEG rather than a HEIC the server can't read.
        quality: 0.85,
      });

      if (result.canceled) return;

      const fitting = result.assets.filter((asset) => !asset.fileSize || asset.fileSize <= MAX_BYTES);

      if (fitting.length < result.assets.length) {
        toast.show('Each screenshot must be under 8 MB.', 'error');
      }

      setShots((current) =>
        [
          ...current,
          ...fitting.map((asset) => ({ uri: asset.uri, mimeType: asset.mimeType, fileName: asset.fileName })),
        ].slice(0, MAX_SCREENSHOTS),
      );
      setErrors(({ screenshots: _ignored, ...rest }) => rest);
    } catch {
      toast.show('Couldn’t open your photos. Try again.', 'error');
    } finally {
      setPicking(false);
    }
  };

  const send = async () => {
    if (!category || !impact) return;

    setSending(true);
    setErrors({});

    try {
      const { data } = await problemReportsApi.send({
        category,
        impact,
        subject,
        description,
        page: area,
        screenshots: shots,
      });

      toast.show(`Report ${data.reference} sent.`, 'success');
      router.back();
      router.push({ pathname: '/problem-reports/[id]', params: { id: data.reference } });
    } catch (e) {
      if (e instanceof ApiError && Object.keys(e.fieldErrors).length > 0) {
        const fields = Object.fromEntries(
          Object.entries(e.fieldErrors).map(([key, message]) => [key.startsWith('screenshots') ? 'screenshots' : key, message]),
        );
        setErrors(fields);
        toast.show(fields.screenshots ?? 'Check the highlighted fields.', 'error');
      } else if (e instanceof ApiError && e.status === 429) {
        toast.show('You’ve sent several reports in a short time. Wait a few minutes, then send this one.', 'error');
      } else {
        toast.show(e instanceof ApiError && e.status > 0 ? e.message : 'The report didn’t go through. Try again.', 'error');
      }
    } finally {
      setSending(false);
    }
  };

  return (
    <Page
      title="Report a problem"
      largeTitle={false}
      modal
      gap={24}
      left={<BarTextButton label="Cancel" onPress={cancel} disabled={sending} />}
      right={<BarTextButton label={sending ? 'Sending…' : 'Send'} emphasized onPress={() => void send()} disabled={!ready || sending} />}
    >
      <Animated.View entering={enter(0)}>
        <AppText variant="subheadline" tone="secondary" style={styles.intro}>
          Tell the SYNAPSE team what went wrong. Every report is read, and their reply comes back to you here.
        </AppText>
      </Animated.View>

      <Animated.View entering={enter(1)}>
        <ListSection header="What kind of problem?" withIcons>
          {CATEGORIES.map((option) => (
            <ListRow
              key={option.value}
              icon={option.icon}
              iconColor={category === option.value ? colors.tint : status.rest}
              title={option.label}
              subtitle={option.hint}
              selected={category === option.value}
              onPress={() => setCategory(option.value)}
            />
          ))}
        </ListSection>
      </Animated.View>

      <Animated.View entering={enter(2)} style={styles.fields}>
        <Input
          label="Summary"
          value={subject}
          onChangeText={setSubject}
          placeholder="e.g. My clock-in photo won’t upload"
          maxLength={120}
          error={errors.subject}
          returnKeyType="next"
        />
        <Input
          label="What happened?"
          value={description}
          onChangeText={setDescription}
          placeholder="What you were doing, what you expected, and what happened instead."
          multiline
          maxLength={5000}
          error={errors.description}
          hint="Copy in any error message you saw."
          style={styles.description}
        />
      </Animated.View>

      <Animated.View entering={enter(3)}>
        <ListSection header="Where in the app?">
          <ListRow
            title="Part of the app"
            value={area ?? 'Choose'}
            onPress={() => setAreasOpen(true)}
          />
        </ListSection>
      </Animated.View>

      <Animated.View entering={enter(4)}>
        <ListSection header="How much is it getting in your way?">
          {IMPACTS.map((option) => (
            <ListRow
              key={option.value}
              title={option.label}
              selected={impact === option.value}
              onPress={() => setImpact(option.value)}
            />
          ))}
        </ListSection>
      </Animated.View>

      <Animated.View entering={enter(5)} style={styles.shots}>
        <AppText variant="footnote" tone="secondary" style={styles.label}>
          {`SCREENSHOTS · OPTIONAL, UP TO ${MAX_SCREENSHOTS}`}
        </AppText>
        <View style={styles.thumbs}>
          {shots.map((shot, index) => (
            <View key={shot.uri} style={[styles.thumb, { borderRadius: radius.md, backgroundColor: colors.fill }]}>
              <Image source={{ uri: shot.uri }} style={StyleSheet.absoluteFill} contentFit="cover" contentPosition="top" />
              <Touchable
                onPress={() => setShots((current) => current.filter((item) => item.uri !== shot.uri))}
                hitSlop={8}
                scaleTo={0.9}
                accessibilityRole="button"
                accessibilityLabel={`Remove screenshot ${index + 1}`}
                style={styles.remove}
              >
                <View style={styles.removeFace}>
                  <Icon name="close" size={11} color="#FFFFFF" weight="bold" />
                </View>
              </Touchable>
            </View>
          ))}
          {shots.length < MAX_SCREENSHOTS && (
            <Touchable
              onPress={() => void addScreenshots()}
              disabled={picking || sending}
              scaleTo={0.96}
              accessibilityRole="button"
              accessibilityLabel="Add a screenshot from your photos"
            >
              <View
                style={[
                  styles.thumb,
                  styles.add,
                  { borderRadius: radius.md, borderColor: errors.screenshots ? colors.danger : colors.separator },
                ]}
              >
                <Icon name="camera" size={20} color={colors.tintText} />
                <AppText variant="caption" weight="medium" tone="tint">
                  {shots.length === 0 ? 'Add' : 'Add more'}
                </AppText>
              </View>
            </Touchable>
          )}
        </View>
        {!!errors.screenshots && (
          <AppText variant="footnote" tone="danger" style={styles.label}>
            {errors.screenshots}
          </AppText>
        )}
      </Animated.View>

      <Animated.View entering={enter(6)} style={styles.notes}>
        <View style={styles.note}>
          <Icon name="info" size={14} color={colors.textTertiary} />
          <AppText variant="footnote" tone="secondary" style={styles.flex}>
            Sent with it: your name and email, so the team can reply, and SYNAPSE{' '}
            {Constants.expoConfig?.version ?? '1.0.0'} on {deviceLabel()}.
          </AppText>
        </View>
        <View style={styles.note}>
          <Icon name="person" size={14} color={colors.textTertiary} />
          <AppText variant="footnote" tone="secondary" style={styles.flex}>
            A mistake in your own records — a leave balance, a time entry, your details? Your HR team corrects
            those.
          </AppText>
        </View>
      </Animated.View>

      <Sheet visible={areasOpen} onClose={() => setAreasOpen(false)} title="Where in the app?">
        <ScrollView style={{ maxHeight: height * 0.55 }} showsVerticalScrollIndicator={false}>
          <ListSection raised>
            {appAreas(user).map((name) => (
              <ListRow
                key={name}
                title={name}
                selected={area === name}
                onPress={() => {
                  setArea(name);
                  setAreasOpen(false);
                }}
              />
            ))}
          </ListSection>
        </ScrollView>
      </Sheet>
    </Page>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  intro: { paddingHorizontal: 4 },
  fields: { gap: 16 },
  description: { minHeight: 132 },
  shots: { gap: 8 },
  label: { marginLeft: 16, letterSpacing: 0.2 },
  thumbs: { flexDirection: 'row', flexWrap: 'wrap', gap: 10 },
  thumb: { width: THUMB, height: THUMB, overflow: 'hidden', borderCurve: 'continuous' },
  add: { borderWidth: 1.5, borderStyle: 'dashed', alignItems: 'center', justifyContent: 'center', gap: 4 },
  remove: { position: 'absolute', top: 5, right: 5 },
  removeFace: {
    width: 22,
    height: 22,
    borderRadius: 11,
    backgroundColor: 'rgba(0,0,0,0.6)',
    alignItems: 'center',
    justifyContent: 'center',
  },
  notes: { gap: 10, paddingHorizontal: 4 },
  note: { flexDirection: 'row', gap: 8, alignItems: 'flex-start' },
});
