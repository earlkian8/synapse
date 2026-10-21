import type { IconName } from '@/components/ui/icon';
import { SERVICES } from '@/features/services/catalog';
import { status as statusColors } from '@/theme/tokens';
import type { AuthUser, ProblemReport, ProblemReportStatus } from '@/types/api';

/**
 * The sender's choices, in the order the form offers them. The values and
 * labels mirror the server's `ProblemReport::CATEGORIES` and `::IMPACTS`.
 */
export const CATEGORIES: { value: ProblemReport['category']; label: string; hint: string; icon: IconName }[] = [
  { value: 'not_working', label: 'Something isn’t working', hint: 'An error, or a button that does nothing', icon: 'error' },
  { value: 'looks_wrong', label: 'Something looks wrong', hint: 'Text cut off, overlapping, unreadable', icon: 'layoutBroken' },
  { value: 'slow', label: 'It’s slow or won’t load', hint: 'A screen that spins or takes too long', icon: 'hourglass' },
  { value: 'other', label: 'Something else', hint: 'Anything that got in your way', icon: 'note' },
];

export const IMPACTS: { value: ProblemReport['impact']; label: string }[] = [
  { value: 'blocked', label: 'I can’t get my work done' },
  { value: 'workaround', label: 'I can work around it' },
  { value: 'minor', label: 'It’s minor' },
];

/** The three steps a report moves through. */
export const STEPS: { status: ProblemReportStatus; label: string }[] = [
  { status: 'new', label: 'Received' },
  { status: 'investigating', label: 'Being looked into' },
  { status: 'resolved', label: 'Resolved' },
];

const STATUS_COLORS: Record<ProblemReportStatus, string> = {
  new: statusColors.holiday,
  investigating: statusColors.late,
  resolved: statusColors.present,
  closed: statusColors.rest,
};

export function reportStatusColor(status: ProblemReportStatus): string {
  return STATUS_COLORS[status] ?? statusColors.rest;
}

export function categoryIcon(category: ProblemReport['category']): IconName {
  return CATEGORIES.find((c) => c.value === category)?.icon ?? 'note';
}

/**
 * Where in the app a problem can be: the five tabs, then every service this
 * person may open (the catalogue, so a new module is offered here too), then
 * the ways in and out that are no tab at all.
 */
export function appAreas(user: AuthUser | null): string[] {
  const services = SERVICES.filter((service) => !service.available || (user && service.available(user))).map(
    (service) => service.title,
  );

  return Array.from(
    new Set(['Home', 'Clock', 'Attendance', 'Services', 'Profile', ...services, 'Search', 'Signing in or switching company']),
  );
}
