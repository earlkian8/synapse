/**
 * Every place the app can take someone, in one list (ADR 0088). The Services tab
 * lays it out by what each is for, Home shows the ones a person pinned, and
 * Search finds them by name — so a new module is added here once and appears in
 * all three, shown only to those who may open it.
 */
import type { Href } from 'expo-router';

import type { AuthUser } from '@/types/api';

import type { GlyphName } from './components/service-glyph';

export type ServiceGroupKey = 'time' | 'calendar' | 'leave' | 'recognition' | 'learning' | 'work';

export type ServiceGroup = {
  key: ServiceGroupKey;
  title: string;
  /** The group's hue: its tiles' wash and drawings are cut from it. */
  hue: string;
};

/**
 * The groups, in the order the Services tab shows them. One hue per kind of
 * thing, so the grid reads as families rather than a rainbow of one-offs.
 * Learning's jade sits far enough from Time's teal to be told apart in a grid.
 */
export const SERVICE_GROUPS: ServiceGroup[] = [
  { key: 'time', title: 'Your time', hue: '#0ABFBF' },
  { key: 'calendar', title: 'Calendar', hue: '#2F7CF6' },
  { key: 'leave', title: 'Time off', hue: '#5856D6' },
  { key: 'recognition', title: 'Recognition', hue: '#FF6A3D' },
  { key: 'learning', title: 'Learning', hue: '#2E9D57' },
  { key: 'work', title: 'Your employment', hue: '#5B6B8C' },
];

export type ServiceKey =
  | 'clock'
  | 'attendance'
  | 'schedule'
  | 'events'
  | 'holidays'
  | 'leave'
  | 'fileLeave'
  | 'recognition'
  | 'kudos'
  | 'nominate'
  | 'nominations'
  | 'rewards'
  | 'awards'
  | 'training'
  | 'trainingBrowse'
  | 'certificates'
  | 'record'
  | 'resignation'
  | 'account'
  | 'password';

export type Service = {
  key: ServiceKey;
  /** The full name, for Search and for screen readers. */
  title: string;
  /** The tile's label: a word or two, so a 4-column grid never truncates. */
  label: string;
  group: ServiceGroupKey;
  glyph: GlyphName;
  href: Href;
  /** Starts something (a form) rather than opening a place: its tile carries a plus. */
  action?: boolean;
  /** What else someone might type to find it in Search. */
  keywords: string;
  /** Whether this person may open it. Absent means everyone who is signed in. */
  available?: (user: AuthUser) => boolean;
};

const hasEmployee = (user: AuthUser) => !!user.employee;
const recognizes = (user: AuthUser) => user.can_recognize;
const trains = (user: AuthUser) => !!user.can_train && hasEmployee(user);

export const SERVICES: Service[] = [
  {
    key: 'clock',
    title: 'Clock in or out',
    label: 'Clock',
    group: 'time',
    glyph: 'clock',
    href: '/(tabs)/clock',
    keywords: 'clock punch time in out break dtr',
  },
  {
    key: 'attendance',
    title: 'My attendance',
    label: 'Attendance',
    group: 'time',
    glyph: 'attendance',
    href: '/(tabs)/attendance',
    keywords: 'attendance days calendar late absent dtr records',
  },
  {
    key: 'schedule',
    title: 'My schedule',
    label: 'Schedule',
    group: 'calendar',
    glyph: 'schedule',
    href: '/schedule',
    keywords: 'schedule shift shifts roster week rest day hours work plan',
    available: hasEmployee,
  },
  {
    key: 'events',
    title: 'My events',
    label: 'Events',
    group: 'calendar',
    glyph: 'events',
    href: '/events',
    keywords: 'events invitations meetings rsvp calendar',
    available: (user) => user.can_respond_events,
  },
  {
    key: 'holidays',
    title: 'Holidays',
    label: 'Holidays',
    group: 'calendar',
    glyph: 'holidays',
    href: '/holidays',
    keywords: 'holidays holy week next day off calendar',
  },
  {
    key: 'leave',
    title: 'My leave',
    label: 'Leave',
    group: 'leave',
    glyph: 'leave',
    href: '/leave',
    keywords: 'leave requests balances vacation sick time off',
  },
  {
    key: 'fileLeave',
    title: 'File leave',
    label: 'File leave',
    group: 'leave',
    glyph: 'leave',
    href: '/leave/new',
    action: true,
    keywords: 'file leave request vacation sick new time off',
  },
  {
    key: 'recognition',
    title: 'Recognition',
    label: 'Recognition',
    group: 'recognition',
    glyph: 'kudos',
    href: '/recognition',
    keywords: 'recognition kudos thanks wall',
    available: recognizes,
  },
  {
    key: 'kudos',
    title: 'Send kudos',
    label: 'Send kudos',
    group: 'recognition',
    glyph: 'kudos',
    href: '/recognition/kudos',
    action: true,
    keywords: 'kudos thanks thank a colleague recognition',
    available: (user) => user.can_recognize && hasEmployee(user),
  },
  {
    key: 'nominate',
    title: 'Nominate a colleague',
    label: 'Nominate',
    group: 'recognition',
    glyph: 'rosette',
    href: '/recognition/nominate',
    action: true,
    keywords: 'nominate nomination award colleague',
    available: recognizes,
  },
  {
    key: 'nominations',
    title: 'My nominations',
    label: 'Nominations',
    group: 'recognition',
    glyph: 'rosette',
    href: '/recognition/nominations',
    keywords: 'nominations nominated award',
    available: recognizes,
  },
  {
    key: 'rewards',
    title: 'Points & rewards',
    label: 'Rewards',
    group: 'recognition',
    glyph: 'gift',
    href: '/rewards',
    keywords: 'points rewards redeem catalogue',
    available: recognizes,
  },
  {
    key: 'awards',
    title: 'My awards',
    label: 'Awards',
    group: 'recognition',
    glyph: 'trophy',
    href: '/awards',
    keywords: 'awards trophy recognition',
  },
  {
    key: 'training',
    title: 'My training',
    label: 'Training',
    group: 'learning',
    glyph: 'mortarboard',
    href: '/training',
    keywords: 'training trainings programs program courses course sessions seminar learning attendance score',
    available: trains,
  },
  {
    key: 'trainingBrowse',
    title: 'Browse training',
    label: 'Browse',
    group: 'learning',
    glyph: 'books',
    href: '/training/browse',
    keywords: 'browse training catalogue catalog join sign up enroll programs courses seminars learn',
    available: trains,
  },
  {
    key: 'certificates',
    title: 'My certificates',
    label: 'Certificates',
    group: 'learning',
    glyph: 'certificate',
    href: { pathname: '/training', params: { tab: 'certificates' } },
    keywords: 'certificates certificate cert certification training expiry expires renew',
    available: trains,
  },
  {
    key: 'record',
    title: 'My employee record',
    label: 'My record',
    group: 'work',
    glyph: 'idCard',
    href: '/(tabs)/profile',
    keywords: 'profile record 201 details government ids sss tin philhealth pag-ibig',
  },
  {
    key: 'resignation',
    title: 'Resignation',
    label: 'Resignation',
    group: 'work',
    glyph: 'door',
    href: '/resignation',
    keywords: 'resign resignation quit leave the company exit clearance certificate of employment coe interview',
    available: (user) => !!user.can_resign,
  },
  {
    key: 'account',
    title: 'Name and email',
    label: 'Account',
    group: 'work',
    glyph: 'person',
    href: '/account/details',
    keywords: 'account name email address change edit sign in',
  },
  {
    key: 'password',
    title: 'Change password',
    label: 'Password',
    group: 'work',
    glyph: 'lock',
    href: '/account/password',
    keywords: 'account password change security sign in',
  },
];

/** The services this person may open, in catalogue order. */
export function servicesFor(user: AuthUser | null): Service[] {
  if (!user) return [];
  return SERVICES.filter((service) => !service.available || service.available(user));
}

export function groupOf(key: ServiceGroupKey): ServiceGroup {
  return SERVICE_GROUPS.find((group) => group.key === key) ?? SERVICE_GROUPS[0];
}
