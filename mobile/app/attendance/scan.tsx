import { CameraView, useCameraPermissions } from 'expo-camera';
import * as Haptics from 'expo-haptics';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Linking, StyleSheet, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { attendanceApi } from '@/features/attendance/api';
import { PunchSuccess } from '@/features/attendance/components/punch-success';
import { PUNCH_META } from '@/features/attendance/punch-meta';
import { looksLikeStationCode } from '@/features/attendance/qr';
import { ApiError } from '@/lib/api';
import { useClockZone } from '@/lib/clock-zone';
import { formatTime } from '@/lib/format';
import { getPreciseCoords, type PreciseFix } from '@/lib/location';
import { captureSelfie } from '@/lib/selfie';
import { useTheme } from '@/theme/theme';
import { palette } from '@/theme/tokens';
import type { PunchType, QrScanResponse } from '@/types/api';

type Phase =
  | { kind: 'aim' }
  | { kind: 'checking' }
  | { kind: 'selfie'; scan: QrScanResponse; type: PunchType }
  | { kind: 'punching'; scan: QrScanResponse }
  | { kind: 'refused'; message: string; locationOff?: boolean }
  | { kind: 'done'; type: PunchType; at: string };

const PUNCH_TYPES: PunchType[] = ['clock_in', 'break_start', 'break_end', 'clock_out'];

/**
 * Clock in by scanning a QR station's code (ADR 0103). The camera looks for the
 * code while the phone finds a fresh, precise fix; the scan goes to the server
 * with both, which judges the phone to be at the station or turns it away and
 * says why. A policy that wants a selfie gets one next, then the punch is made.
 *
 * Opened from Clock (`?type=` is the button they pressed), or from the phone's
 * own camera through the link a station shows (`?code=`), when the punch is
 * whatever the day expects next.
 */
export default function ScanStationScreen() {
  const params = useLocalSearchParams<{ type?: string; code?: string }>();
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { colors, spacing, readable } = useTheme();
  const zone = useClockZone();
  const [permission, requestPermission] = useCameraPermissions();

  const wanted = PUNCH_TYPES.includes(params.type as PunchType) ? (params.type as PunchType) : null;
  const [phase, setPhase] = useState<Phase>({ kind: params.code ? 'checking' : 'aim' });
  const [fix, setFix] = useState<PreciseFix | null>(null);
  // The fix is asked for as the screen opens, and awaited by the scan.
  const fixPromise = useRef<Promise<PreciseFix> | null>(null);
  const handled = useRef(false);

  const locate = useCallback(() => {
    const pending = getPreciseCoords();
    fixPromise.current = pending;
    void pending.then(setFix);

    return pending;
  }, []);

  useEffect(() => {
    void locate();
  }, [locate]);

  const leave = useCallback(() => {
    if (router.canGoBack()) router.back();
    else router.replace('/clock');
  }, [router]);

  const punch = useCallback(
    async (scan: QrScanResponse, type: PunchType, photoUri: string | null) => {
      setPhase({ kind: 'punching', scan });

      try {
        await attendanceApi.qrPunch(scan.ticket, type, photoUri);
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
        setPhase({ kind: 'done', type, at: formatTime(new Date().toISOString(), zone) });
        setTimeout(leave, 1700);
      } catch (error) {
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
        setPhase({
          kind: 'refused',
          message: error instanceof ApiError ? error.message : 'Couldn’t reach SYNAPSE. A QR punch needs a connection; try again.',
        });
      }
    },
    [leave, zone],
  );

  const submit = useCallback(
    async (code: string) => {
      setPhase({ kind: 'checking' });
      const located = await (fixPromise.current ?? locate());

      if (located.denied) {
        setPhase({
          kind: 'refused',
          message: 'SYNAPSE needs your location to check you’re at the station. Allow it in Settings, then scan again.',
          locationOff: true,
        });
        return;
      }

      try {
        const scan = await attendanceApi.qrScan(code, located.coords);
        const type = wanted && scan.day.allowed.includes(wanted) ? wanted : scan.day.next_expected;

        if (!type) {
          setPhase({ kind: 'refused', message: 'You’ve clocked out for today. There’s nothing left to punch.' });
          return;
        }

        if (scan.day.selfie_required) {
          setPhase({ kind: 'selfie', scan, type });
          return;
        }

        await punch(scan, type, null);
      } catch (error) {
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
        setPhase({
          kind: 'refused',
          message: error instanceof ApiError ? error.message : 'Couldn’t reach SYNAPSE. A QR punch needs a connection; try again.',
        });
      }
    },
    [locate, punch, wanted],
  );

  // A code handed over by the phone's own camera (the station's link).
  useEffect(() => {
    if (params.code && !handled.current) {
      handled.current = true;
      void submit(params.code);
    }
  }, [params.code, submit]);

  const onScanned = useCallback(
    ({ data }: { data: string }) => {
      if (phase.kind !== 'aim' || handled.current) return;

      if (!looksLikeStationCode(data)) {
        return;
      }

      handled.current = true;
      void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium);
      void submit(data);
    },
    [phase.kind, submit],
  );

  const again = () => {
    handled.current = false;
    if (fix?.denied || !fix?.coords) void locate();
    setPhase({ kind: 'aim' });
  };

  const takeSelfie = async (scan: QrScanResponse, type: PunchType) => {
    const uri = await captureSelfie();
    if (uri) await punch(scan, type, uri);
  };

  const cameraOn = !params.code && permission?.granted && (phase.kind === 'aim' || phase.kind === 'checking');
  const label = wanted ? PUNCH_META[wanted].label : 'Clock';

  return (
    <View style={[styles.root, { backgroundColor: palette.navy }]}>
      {cameraOn && (
        <CameraView
          style={StyleSheet.absoluteFill}
          facing="back"
          barcodeScannerSettings={{ barcodeTypes: ['qr'] }}
          onBarcodeScanned={phase.kind === 'aim' ? onScanned : undefined}
        />
      )}

      {cameraOn && <Viewfinder busy={phase.kind === 'checking'} />}

      <View style={[styles.top, { paddingTop: insets.top + 8 }]}>
        <Touchable
          onPress={leave}
          style={styles.close}
          accessibilityRole="button"
          accessibilityLabel="Close the scanner"
          haptic="light"
        >
          <Icon name="close" size={18} color="#FFFFFF" />
        </Touchable>
        <AppText variant="headline" color="#FFFFFF" style={styles.title} numberOfLines={1}>
          {label} with a QR code
        </AppText>
        <View style={styles.close} />
      </View>

      <View style={[styles.panel, { paddingBottom: insets.bottom + spacing.lg, backgroundColor: colors.card }]}>
        {!params.code && permission && !permission.granted ? (
          <PanelBody
            icon="camera"
            title="Allow the camera to scan"
            body="SYNAPSE uses the camera only to read the station’s code. Nothing is recorded."
          >
            <Button
              label={permission.canAskAgain ? 'Allow camera' : 'Open Settings'}
              icon="camera"
              variant="tint"
              onPress={() => (permission.canAskAgain ? void requestPermission() : void Linking.openSettings())}
            />
          </PanelBody>
        ) : phase.kind === 'aim' ? (
          <PanelBody
            icon="qr"
            title="Point at the code on the screen"
            body="It changes every few seconds, so scan the one showing now. You need to be at the station’s site."
          >
            <LocationLine fix={fix} />
          </PanelBody>
        ) : phase.kind === 'checking' || phase.kind === 'punching' ? (
          <View style={styles.busy} accessibilityLiveRegion="polite">
            <ActivityIndicator />
            <AppText variant="headline">
              {phase.kind === 'checking' ? 'Checking you’re at the station…' : `Recording your ${label.toLowerCase()}…`}
            </AppText>
          </View>
        ) : phase.kind === 'selfie' ? (
          <PanelBody
            icon="camera"
            title={`Scanned at ${phase.scan.station.name}`}
            body="Your company asks for a selfie with every punch. Take one to finish."
          >
            <Button
              label="Take selfie"
              icon="camera"
              variant="tint"
              onPress={() => void takeSelfie(phase.scan, phase.type)}
            />
          </PanelBody>
        ) : phase.kind === 'refused' ? (
          <Animated.View entering={FadeIn.duration(180)} accessibilityLiveRegion="assertive">
            <PanelBody icon="warning" tone={readable('#FF3B30', colors.card)} title="Not recorded" body={phase.message}>
              <View style={{ gap: spacing.sm }}>
                {phase.locationOff && (
                  <Button label="Open Settings" icon="location" variant="gray" onPress={() => void Linking.openSettings()} />
                )}
                <Button label="Scan again" icon="qr" variant="tint" onPress={again} />
              </View>
            </PanelBody>
          </Animated.View>
        ) : null}
      </View>

      {phase.kind === 'done' && <PunchSuccess punch={phase.type} time={phase.at} />}
    </View>
  );
}

/** The panel's words: what to do now, and the action that does it. */
function PanelBody({
  icon,
  title,
  body,
  tone,
  children,
}: {
  icon: 'qr' | 'camera' | 'warning';
  title: string;
  body: string;
  tone?: string;
  children?: React.ReactNode;
}) {
  const { colors, spacing } = useTheme();

  return (
    <View style={{ gap: spacing.lg }}>
      <View style={styles.heading}>
        <Icon name={icon} size={22} color={tone ?? colors.primary} />
        <View style={styles.flex}>
          <AppText variant="headline">{title}</AppText>
          <AppText variant="subheadline" tone="secondary">
            {body}
          </AppText>
        </View>
      </View>
      {children}
    </View>
  );
}

/** Whether the phone knows where it is yet, and how closely. */
function LocationLine({ fix }: { fix: PreciseFix | null }) {
  const { colors, status, readable } = useTheme();
  const accuracy = fix?.coords?.accuracy;
  const ready = !!fix?.coords;
  const rough = ready && accuracy != null && accuracy > 100;

  return (
    <View style={styles.location} accessibilityLiveRegion="polite">
      {fix === null ? (
        <ActivityIndicator size="small" />
      ) : (
        <Icon
          name={ready ? 'location' : 'locationSlash'}
          size={16}
          color={ready && !rough ? readable(status.present, colors.card) : readable(status.late, colors.card)}
        />
      )}
      <AppText variant="footnote" tone="secondary" style={styles.flex}>
        {fix === null
          ? 'Finding where you are…'
          : fix.denied
            ? 'Location is off for SYNAPSE. The scan will ask for it.'
            : !ready
              ? 'Can’t find where you are yet. Step near a window.'
              : rough
                ? `Your location is rough (within ${Math.round(accuracy!)} m). Turn on precise location.`
                : `Location ready${accuracy != null ? `, within ${Math.round(accuracy)} m` : ''}.`}
      </AppText>
    </View>
  );
}

/** The square the code is aimed into, with the camera dimmed around it. */
function Viewfinder({ busy }: { busy: boolean }) {
  const corner = busy ? '#FFFFFF' : palette.teal;

  return (
    <View pointerEvents="none" style={StyleSheet.absoluteFill}>
      <View style={styles.scrim} />
      <View style={styles.row}>
        <View style={styles.scrim} />
        <View style={styles.window}>
          <View style={[styles.corner, styles.tl, { borderColor: corner }]} />
          <View style={[styles.corner, styles.tr, { borderColor: corner }]} />
          <View style={[styles.corner, styles.bl, { borderColor: corner }]} />
          <View style={[styles.corner, styles.br, { borderColor: corner }]} />
        </View>
        <View style={styles.scrim} />
      </View>
      <View style={[styles.scrim, styles.lower]} />
    </View>
  );
}

const WINDOW = 248;
const CORNER = 34;

const styles = StyleSheet.create({
  root: { flex: 1 },
  flex: { flex: 1 },
  top: {
    position: 'absolute',
    left: 0,
    right: 0,
    top: 0,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    gap: 12,
  },
  close: {
    width: 36,
    height: 36,
    borderRadius: 18,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.35)',
  },
  title: { flex: 1, textAlign: 'center' },
  panel: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    borderCurve: 'continuous',
    paddingTop: 22,
    paddingHorizontal: 20,
  },
  heading: { flexDirection: 'row', gap: 12, alignItems: 'flex-start' },
  busy: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 10 },
  location: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  scrim: { flex: 1, backgroundColor: 'rgba(6,14,32,0.62)' },
  lower: { flex: 1.6 },
  row: { flexDirection: 'row', height: WINDOW },
  window: { width: WINDOW, height: WINDOW },
  corner: { position: 'absolute', width: CORNER, height: CORNER, borderWidth: 0 },
  tl: { top: 0, left: 0, borderTopWidth: 4, borderLeftWidth: 4, borderTopLeftRadius: 18 },
  tr: { top: 0, right: 0, borderTopWidth: 4, borderRightWidth: 4, borderTopRightRadius: 18 },
  bl: { bottom: 0, left: 0, borderBottomWidth: 4, borderLeftWidth: 4, borderBottomLeftRadius: 18 },
  br: { bottom: 0, right: 0, borderBottomWidth: 4, borderRightWidth: 4, borderBottomRightRadius: 18 },
});
