import { Ionicons } from '@expo/vector-icons';
import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeIn, FadeOut } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { AppText } from '@/components/ui/text';
import { composite } from '@/theme/color';
import { useTheme } from '@/theme/theme';
import { status as statusTones, palette } from '@/theme/tokens';

type ToastType = 'success' | 'error' | 'info';
type Toast = { id: number; type: ToastType; message: string };

type ToastValue = { show: (message: string, type?: ToastType) => void };

const ToastContext = createContext<ToastValue | null>(null);

const ICONS: Record<ToastType, keyof typeof Ionicons.glyphMap> = {
  success: 'checkmark-circle',
  error: 'alert-circle',
  info: 'information-circle',
};

const TONES: Record<ToastType, string> = {
  success: statusTones.present,
  error: statusTones.absent,
  info: palette.teal,
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const { colors, readable } = useTheme();
  const insets = useSafeAreaInsets();
  const [toasts, setToasts] = useState<Toast[]>([]);
  const counter = useRef(0);

  const show = useCallback((message: string, type: ToastType = 'success') => {
    const id = counter.current++;
    setToasts((prev) => [...prev, { id, type, message }]);
    setTimeout(() => setToasts((prev) => prev.filter((t) => t.id !== id)), 2800);
  }, []);

  return (
    <ToastContext.Provider value={{ show }}>
      {children}
      <View pointerEvents="none" style={[styles.host, { top: insets.top + 8 }]}>
        {toasts.map((toast) => {
          // The ERP's Sonner treatment: a card washed with 8% of the status colour and a
          // solid bar down the leading edge, rather than a fully saturated banner.
          const tone = TONES[toast.type];
          const wash = composite(tone, 0.08, colors.card);

          return (
            <Animated.View
              key={toast.id}
              entering={FadeIn.duration(200)}
              exiting={FadeOut.duration(200)}
              accessibilityRole="alert"
              style={[
                styles.toast,
                {
                  backgroundColor: wash,
                  borderColor: colors.border,
                  borderLeftColor: readable(tone, wash),
                  borderLeftWidth: 3,
                  shadowColor: colors.shadow,
                },
              ]}
            >
              <Ionicons name={ICONS[toast.type]} size={20} color={readable(tone, wash)} />
              <AppText variant="label" style={{ flex: 1 }}>
                {toast.message}
              </AppText>
            </Animated.View>
          );
        })}
      </View>
    </ToastContext.Provider>
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
  host: {
    position: 'absolute',
    left: 16,
    right: 16,
    gap: 8,
    zIndex: 100,
    alignItems: 'center',
  },
  toast: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    width: '100%',
    paddingHorizontal: 16,
    paddingVertical: 13,
    borderRadius: 14,
    borderWidth: 1,
    shadowOpacity: 0.12,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 5,
  },
});
