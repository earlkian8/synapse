import * as Haptics from 'expo-haptics';
import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';
import { Platform, StyleSheet, View } from 'react-native';
import { Gesture, GestureDetector } from 'react-native-gesture-handler';
import Animated, {
  FadeInUp,
  FadeOutUp,
  LinearTransition,
  useAnimatedStyle,
  useSharedValue,
  withSpring,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { scheduleOnRN } from 'react-native-worklets';

import { Icon, type IconName } from '@/components/ui/icon';
import { Material } from '@/components/ui/material';
import { AppText } from '@/components/ui/text';
import { Touchable } from '@/components/ui/touchable';
import { springs } from '@/lib/motion';
import { useTheme } from '@/theme/theme';
import { status as statusTones, palette } from '@/theme/tokens';

type ToastType = 'success' | 'error' | 'info';
type Toast = { id: number; type: ToastType; message: string };

type ToastValue = { show: (message: string, type?: ToastType) => void };

const ToastContext = createContext<ToastValue | null>(null);

const ICONS: Record<ToastType, IconName> = {
  success: 'checkCircle',
  error: 'error',
  info: 'info',
};

const TONES: Record<ToastType, string> = {
  success: statusTones.present,
  error: statusTones.absent,
  info: palette.teal,
};

/** Dropped in on a spring. Web layout animations can't take custom initial values (see lib/motion). */
const ARRIVE =
  Platform.OS === 'web'
    ? FadeInUp.duration(260)
    : FadeInUp.springify().damping(18).stiffness(200).withInitialValues({ opacity: 0, transform: [{ translateY: -36 }] });

/** Long enough to read a two-line message, short enough not to linger. */
const DURATION = 3400;
/** Older banners give way: never more than this many on screen. */
const MAX = 2;

export function ToastProvider({ children }: { children: ReactNode }) {
  const insets = useSafeAreaInsets();
  const [toasts, setToasts] = useState<Toast[]>([]);
  const counter = useRef(0);

  const dismiss = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const show = useCallback(
    (message: string, type: ToastType = 'success') => {
      const id = counter.current++;
      // An error is felt as well as read: it is the one toast that needs attention.
      if (type === 'error') void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
      setToasts((prev) => [...prev.slice(-(MAX - 1)), { id, type, message }]);
      setTimeout(() => dismiss(id), DURATION);
    },
    [dismiss],
  );

  const value = useMemo(() => ({ show }), [show]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      <View pointerEvents="box-none" style={[styles.host, { top: insets.top + 6 }]}>
        {toasts.map((toast) => (
          <Banner key={toast.id} toast={toast} onDismiss={() => dismiss(toast.id)} />
        ))}
      </View>
    </ToastContext.Provider>
  );
}

/**
 * One banner: frosted, rounded, dropped in from the top on a spring like a
 * notification. Tap it or flick it up to send it away.
 */
function Banner({ toast, onDismiss }: { toast: Toast; onDismiss: () => void }) {
  const { colors, readable, scheme } = useTheme();
  const lift = useSharedValue(0);

  const swipe = Gesture.Pan()
    .activeOffsetY([-6, 6])
    .onUpdate((event) => {
      lift.set(event.translationY < 0 ? event.translationY : event.translationY / 6);
    })
    .onEnd((event) => {
      if (event.translationY < -24 || event.velocityY < -500) {
        scheduleOnRN(onDismiss);
      } else {
        lift.set(withSpring(0, springs.snappy));
      }
    });

  const liftStyle = useAnimatedStyle(() => ({ transform: [{ translateY: lift.get() }] }));

  const surface = scheme === 'dark' ? colors.elevated : colors.card;
  const tone = readable(TONES[toast.type], surface, 3);

  return (
    <Animated.View
      entering={ARRIVE}
      exiting={FadeOutUp.duration(200)}
      layout={LinearTransition.springify().damping(22).stiffness(240)}
      style={styles.slot}
    >
      <GestureDetector gesture={swipe}>
        <Animated.View style={[styles.shadow, liftStyle]}>
          <Touchable onPress={onDismiss} scaleTo={0.98} accessibilityRole="alert" accessibilityLabel={toast.message}>
            <Material style={styles.toast} opacity={0.98}>
              <Icon name={ICONS[toast.type]} size={22} color={tone} />
              <AppText variant="subheadline" weight="medium" style={styles.message} numberOfLines={3}>
                {toast.message}
              </AppText>
            </Material>
          </Touchable>
        </Animated.View>
      </GestureDetector>
    </Animated.View>
  );
}

export function useToast(): ToastValue {
  const ctx = useContext(ToastContext);

  if (!ctx) {
    throw new Error('useToast must be used within a ToastProvider');
  }

  return ctx;
}

const styles = StyleSheet.create({
  host: { position: 'absolute', left: 12, right: 12, gap: 8, zIndex: 100 },
  slot: { width: '100%' },
  shadow: {
    borderRadius: 22,
    boxShadow: '0 1px 3px rgba(0, 0, 0, 0.08), 0 12px 32px rgba(0, 0, 0, 0.16)',
  },
  toast: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: 16,
    paddingVertical: 14,
    borderRadius: 22,
    borderCurve: 'continuous',
    overflow: 'hidden',
  },
  message: { flex: 1 },
});
