import { Tabs } from 'expo-router';

import { TabBar } from '@/components/ui/tab-bar';
import { PunchQueueRunner } from '@/features/attendance/punch-queue-runner';

export default function TabsLayout() {
  return (
    <>
      {/* Sends punches saved while offline, wherever the app is open. */}
      <PunchQueueRunner />
      <Tabs tabBar={(props) => <TabBar {...props} />} screenOptions={{ headerShown: false }}>
        <Tabs.Screen name="index" options={{ title: 'Home' }} />
        <Tabs.Screen name="attendance" options={{ title: 'Attendance' }} />
        <Tabs.Screen name="clock" options={{ title: 'Clock' }} />
        <Tabs.Screen name="requests" options={{ title: 'Leave' }} />
        <Tabs.Screen name="profile" options={{ title: 'Profile' }} />
      </Tabs>
    </>
  );
}
