import { Tabs } from 'expo-router';

import { TabBar } from '@/components/ui/tab-bar';
import { PunchQueueRunner } from '@/features/attendance/punch-queue-runner';
import { useTheme } from '@/theme/theme';

export default function TabsLayout() {
  const { colors } = useTheme();

  return (
    <>
      {/* Sends punches saved while offline, wherever the app is open. */}
      <PunchQueueRunner />
      <Tabs
        tabBar={(props) => <TabBar {...props} />}
        screenOptions={{
          headerShown: false,
          // A quick crossfade between tabs, rather than a hard cut.
          animation: 'fade',
          sceneStyle: { backgroundColor: colors.background },
        }}
      >
        <Tabs.Screen name="index" options={{ title: 'Home' }} />
        <Tabs.Screen name="attendance" options={{ title: 'Attendance' }} />
        <Tabs.Screen name="clock" options={{ title: 'Clock' }} />
        <Tabs.Screen name="requests" options={{ title: 'Leave' }} />
        <Tabs.Screen name="profile" options={{ title: 'Profile' }} />
      </Tabs>
    </>
  );
}
