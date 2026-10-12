import { status } from '@/theme/tokens';
import type { ClearanceStatus, ResignationStatus } from '@/types/api';

/** How a resignation's state reads, and its tone. */
export const RESIGNATION_STATUS: Record<ResignationStatus, { label: string; color: string }> = {
  pending: { label: 'Waiting for HR', color: status.late },
  accepted: { label: 'Accepted', color: status.present },
  declined: { label: 'Declined', color: status.absent },
  withdrawn: { label: 'Withdrawn', color: status.rest },
};

/** How a clearance item's state reads, and its tone. */
export const CLEARANCE_STATUS: Record<ClearanceStatus, { label: string; color: string }> = {
  pending: { label: 'Pending', color: status.rest },
  cleared: { label: 'Signed off', color: status.present },
  flagged: { label: 'Flagged', color: status.absent },
};

/** Whole days from one calendar date ("Y-m-d") to another. */
export function daysBetween(from: string, to: string): number {
  const day = (iso: string) => {
    const [y, m, d] = iso.split('-').map(Number);
    return Date.UTC(y, m - 1, d);
  };

  return Math.round((day(to) - day(from)) / 86_400_000);
}

/** "today", "tomorrow", "in 12 days", "3 days ago". */
export function relativeDay(today: string, iso: string): string {
  const days = daysBetween(today, iso);

  if (days === 0) return 'today';
  if (days === 1) return 'tomorrow';
  if (days === -1) return 'yesterday';

  return days > 0 ? `in ${days} days` : `${-days} days ago`;
}

/** A calendar date `days` after another, as "Y-m-d". */
export function addDays(iso: string, days: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d + days));

  return date.toISOString().slice(0, 10);
}
