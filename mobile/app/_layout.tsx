import { Inter_400Regular } from '@expo-google-fonts/inter/400Regular';
import { Inter_500Medium } from '@expo-google-fonts/inter/500Medium';
import { Inter_600SemiBold } from '@expo-google-fonts/inter/600SemiBold';
import { Inter_700Bold } from '@expo-google-fonts/inter/700Bold';
import Constants, { ExecutionEnvironment } from 'expo-constants';
import { useFonts } from 'expo-font';
import { Stack, useRouter, useSegments } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import 'react-native-reanimated';

import { ToastProvider } from '@/components/ui/toast';
import { AuthProvider, useAuth } from '@/lib/auth';
import { ThemeProvider, useTheme } from '@/theme/theme';

void SplashScreen.preventAutoHideAsync();
// A soft fade off the native splash. Expo Go can't take splash options (it warns), so
// only builds of the app get it.
if (Constants.executionEnvironment !== ExecutionEnvironment.StoreClient) {
  SplashScreen.setOptions({ duration: 280, fade: true });
}

export default function RootLayout() {
  // Inter, one file per weight (see theme/tokens.ts). The native splash stays up
  // until they are in, so no screen ever draws in a fallback face first.
  const [fontsLoaded, fontError] = useFonts({
    Inter_400Regular,
    Inter_500Medium,
    Inter_600SemiBold,
    Inter_700Bold,
  });

  if (!fontsLoaded && !fontError) {
    return null;
  }

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <ThemeProvider>
          <AuthProvider>
            <ToastProvider>
              <RootNavigator />
            </ToastProvider>
          </AuthProvider>
        </ThemeProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}

/**
 * Routes the user between the auth stack and the app shell based on session
 * state, and keeps the native splash up until the stored session is restored.
 */
function RootNavigator() {
  const { isLoading, isAuthenticated, hasEnteredWorkspace, needsWorkspace, organizations } =
    useAuth();
  const { scheme, colors } = useTheme();
  const segments = useSegments();
  const router = useRouter();

  useEffect(() => {
    if (isLoading) {
      return;
    }

    void SplashScreen.hideAsync();

    const inAuthGroup = segments[0] === '(auth)';
    const onPicker = segments[0] === 'select-workspace';
    const onJoin = segments[0] === 'join';
    // The cold-start splash (app/index.tsx) — a restored session must move on from it too.
    const first = segments[0] as string | undefined;
    const onSplash = first === undefined || first === 'index';
    // A fresh login by someone in several companies must pick one first.
    const needsChoice = isAuthenticated && !hasEnteredWorkspace && organizations.length > 1;
    // Signed in but belonging nowhere is a valid state (ADR 0026) — it has its own
    // screen rather than being treated as a failed session.
    const unaffiliated = isAuthenticated && needsWorkspace;

    if (!isAuthenticated && !inAuthGroup) {
      router.replace('/(auth)/login');
    } else if (unaffiliated && !onJoin) {
      router.replace('/join');
    } else if (needsChoice && !onPicker) {
      router.replace('/select-workspace');
    } else if (isAuthenticated && !unaffiliated && !needsChoice && (inAuthGroup || onPicker || onJoin || onSplash)) {
      router.replace('/(tabs)');
    }
  }, [
    isLoading,
    isAuthenticated,
    hasEnteredWorkspace,
    needsWorkspace,
    organizations.length,
    segments,
    router,
  ]);

  return (
    <>
      <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />
      <Stack
        screenOptions={{
          headerShown: false,
          contentStyle: { backgroundColor: colors.background },
          animation: 'slide_from_right',
          // Swipe back from anywhere on the screen, not just the edge.
          gestureEnabled: true,
          fullScreenGestureEnabled: true,
        }}
      >
        <Stack.Screen name="index" options={{ animation: 'fade' }} />
        <Stack.Screen name="(auth)" options={{ animation: 'fade' }} />
        <Stack.Screen name="join" options={{ animation: 'fade' }} />
        <Stack.Screen name="select-workspace" options={{ animation: 'fade' }} />
        <Stack.Screen name="(tabs)" options={{ animation: 'fade' }} />
        <Stack.Screen name="leave/new" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="leave/[id]" />
        <Stack.Screen
          name="attendance/[date]"
          options={{ presentation: 'modal', animation: 'slide_from_bottom' }}
        />
        <Stack.Screen name="awards/index" />
        <Stack.Screen name="events/index" />
        <Stack.Screen name="events/[id]" />
        <Stack.Screen name="recognition/index" />
        <Stack.Screen name="recognition/nominations" />
        <Stack.Screen name="recognition/kudos" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="recognition/nominate" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
        <Stack.Screen name="rewards/index" />
      </Stack>
    </>
  );
}
