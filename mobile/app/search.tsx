import { useRouter, type Href } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import Animated from 'react-native-reanimated';

import { EmptyState } from '@/components/ui/empty-state';
import type { IconName } from '@/components/ui/icon';
import { Input } from '@/components/ui/input';
import { ListRow, ListSection } from '@/components/ui/list';
import { BarTextButton, Page } from '@/components/ui/page';
import { AppText } from '@/components/ui/text';
import { MAX_QUERY, MIN_QUERY, searchApi } from '@/features/search/api';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { fadeIn } from '@/lib/motion';
import { useTheme } from '@/theme/theme';
import type { SearchGroup, SearchTarget } from '@/types/api';

/** Wait this long after a keystroke before asking the server. */
const DEBOUNCE_MS = 250;

type Screen = { title: string; keywords: string; icon: IconName; href: Href; show?: boolean };

/** Where a result opens. */
function hrefFor(target: SearchTarget): Href {
  switch (target.screen) {
    case 'leave':
      return `/leave/${target.id}`;
    case 'attendance':
      return `/attendance/${target.date}`;
    case 'event':
      return `/events/${target.id}`;
    case 'awards':
      return '/awards';
    default:
      return '/holidays';
  }
}

const GROUP_ICONS: Record<SearchGroup['key'], IconName> = {
  leave: 'leave',
  attendance: 'calendar',
  events: 'calendarPlus',
  awards: 'trophy',
  holidays: 'holiday',
};

/**
 * Search (ADR 0081): the app's screens, found on the phone as one types, and
 * one's own records — leave, days ("late", "oct 3"), invitations, awards and
 * holidays — from the server. Never anybody else's.
 */
export default function SearchScreen() {
  const { status } = useTheme();
  const router = useRouter();
  const { user } = useAuth();

  const [query, setQuery] = useState('');
  const [groups, setGroups] = useState<SearchGroup[]>([]);
  const [searching, setSearching] = useState(false);
  const [failed, setFailed] = useState<string | null>(null);
  const latest = useRef(0);

  const term = query.trim();

  const screens: Screen[] = [
    { title: 'Clock in or out', keywords: 'clock punch time in out dtr', icon: 'clock', href: '/(tabs)/clock' },
    { title: 'My attendance', keywords: 'attendance days calendar late absent dtr', icon: 'attendance', href: '/(tabs)/attendance' },
    { title: 'My leave', keywords: 'leave requests balances vacation sick', icon: 'leave', href: '/(tabs)/requests' },
    { title: 'File leave', keywords: 'file leave request vacation sick new', icon: 'calendarPlus', href: '/leave/new' },
    { title: 'Holidays', keywords: 'holidays holy week next day off calendar', icon: 'holiday', href: '/holidays' },
    { title: 'My events', keywords: 'events invitations meetings rsvp', icon: 'calendar', href: '/events', show: user?.can_respond_events },
    { title: 'Recognition', keywords: 'recognition kudos thanks nominate', icon: 'heart', href: '/recognition', show: user?.can_recognize },
    { title: 'Points & rewards', keywords: 'points rewards redeem', icon: 'reward', href: '/rewards', show: user?.can_recognize },
    { title: 'My awards', keywords: 'awards trophy recognition', icon: 'trophy', href: '/awards' },
    { title: 'Resignation', keywords: 'resign resignation quit leave the company exit clearance certificate of employment coe interview', icon: 'exit', href: '/resignation', show: user?.can_resign },
    { title: 'Profile', keywords: 'profile details government ids sss tin philhealth pag-ibig', icon: 'profile', href: '/(tabs)/profile' },
  ];

  const words = term.toLowerCase().split(/\s+/).filter(Boolean);
  const foundScreens =
    words.length === 0
      ? []
      : screens.filter(
          (screen) =>
            screen.show !== false &&
            words.every((word) => `${screen.title} ${screen.keywords}`.toLowerCase().includes(word)),
        );

  useEffect(() => {
    if (term.length < MIN_QUERY) {
      return;
    }

    const id = ++latest.current;
    const timer = setTimeout(() => {
      setSearching(true);
      setFailed(null);

      searchApi
        .search(term.slice(0, MAX_QUERY))
        .then((result) => {
          if (id === latest.current) setGroups(result.groups);
        })
        .catch((e: unknown) => {
          if (id === latest.current) {
            setGroups([]);
            setFailed(e instanceof ApiError && e.status === 429 ? 'Too many searches. Wait a moment.' : 'Search didn’t answer. Try again.');
          }
        })
        .finally(() => {
          if (id === latest.current) setSearching(false);
        });
    }, DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [term]);

  const shownGroups = term.length >= MIN_QUERY ? groups : [];
  const nothing = term.length >= MIN_QUERY && !searching && !failed && shownGroups.length === 0 && foundScreens.length === 0;

  return (
    <Page
      title="Search"
      largeTitle={false}
      modal
      right={<BarTextButton label="Done" emphasized onPress={() => router.back()} />}
      gap={20}
    >
      <View style={styles.field}>
        <Input
          icon="search"
          value={query}
          onChangeText={setQuery}
          placeholder="Leave, a day, an event, a holiday…"
          autoFocus
          autoCorrect={false}
          returnKeyType="search"
          maxLength={MAX_QUERY}
          accessibilityLabel="Search"
        />
        {searching && <ActivityIndicator style={styles.spinner} />}
      </View>

      {term.length === 0 && (
        <AppText variant="footnote" tone="secondary" style={styles.hint}>
          Try “sick leave”, “late in october”, “oct 3”, “holy week” or “resign”. Only your own records are searched.
        </AppText>
      )}

      {foundScreens.length > 0 && (
        <Animated.View entering={fadeIn}>
          <ListSection header="Screens" withIcons>
            {foundScreens.map((screen) => (
              <ListRow
                key={screen.title}
                icon={screen.icon}
                iconColor={status.leave}
                title={screen.title}
                onPress={() => {
                  router.back();
                  router.push(screen.href);
                }}
              />
            ))}
          </ListSection>
        </Animated.View>
      )}

      {shownGroups.map((group) => (
        <Animated.View key={group.key} entering={fadeIn}>
          <ListSection header={group.label} withIcons>
            {group.items.map((item) => (
              <ListRow
                key={item.id}
                icon={GROUP_ICONS[group.key]}
                iconColor={status.overtime}
                title={item.title}
                subtitle={item.subtitle ?? undefined}
                value={item.hint ?? undefined}
                onPress={() => {
                  router.back();
                  router.push(hrefFor(item.target));
                }}
              />
            ))}
          </ListSection>
        </Animated.View>
      ))}

      {failed && (
        <AppText variant="footnote" tone="danger" style={styles.hint}>
          {failed}
        </AppText>
      )}

      {nothing && <EmptyState icon="search" title={`Nothing matches “${term}”`} message="Try a date, a month, or a kind of day." />}
    </Page>
  );
}

const styles = StyleSheet.create({
  field: { justifyContent: 'center' },
  spinner: { position: 'absolute', right: 14 },
  hint: { paddingHorizontal: 4 },
});
