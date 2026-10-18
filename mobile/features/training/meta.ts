import { formatClock, parseDateOnly, todayDateKey } from '@/lib/format';
import { status } from '@/theme/tokens';
import type {
  CertificateStanding,
  TrainingEnrollmentMode,
  TrainingEnrollmentStatus,
  TrainingMark,
  TrainingProgramStatus,
} from '@/types/api';

/** The words and tones training is told in, the same on every screen (ADR 0094). */

/** Where the person stands on a program. */
export const ENROLLMENT_STATUS: Record<TrainingEnrollmentStatus, { label: string; color: string }> = {
  requested: { label: 'Asked to join', color: status.late },
  enrolled: { label: 'Enrolled', color: status.overtime },
  completed: { label: 'Completed', color: status.present },
  dropped: { label: 'Dropped', color: status.rest },
  declined: { label: 'Declined', color: status.absent },
};

/** A program's lifecycle, derived from its dates on the server. */
export const PROGRAM_STATUS: Record<TrainingProgramStatus, { label: string; color: string }> = {
  upcoming: { label: 'Upcoming', color: status.holiday },
  ongoing: { label: 'Running now', color: status.present },
  completed: { label: 'Ended', color: status.rest },
};

export const MODE_LABEL: Record<TrainingEnrollmentMode, string> = {
  open: 'Open to join',
  approval: 'Join on approval',
  closed: 'HR enrolls',
};

/** A session's attendance, as HR marked it. */
export const MARK: Record<TrainingMark, { label: string; color: string }> = {
  present: { label: 'Present', color: status.present },
  late: { label: 'Late', color: status.late },
  absent: { label: 'Absent', color: status.absent },
  excused: { label: 'Excused', color: status.holiday },
};

export const STANDING: Record<CertificateStanding, { label: string; color: string }> = {
  valid: { label: 'Valid', color: status.present },
  expiring: { label: 'Expires soon', color: status.late },
  expired: { label: 'Expired', color: status.absent },
  renewed: { label: 'Renewed', color: status.rest },
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** "Oct 28 – 31, 2026", "Oct 28 – Nov 2, 2026", "From Oct 28, 2026", "Dates to be set". */
export function dateRange(start: string | null, end: string | null): string {
  if (!start && !end) return 'Dates to be set';
  if (!start) return `Until ${longDate(end!)}`;
  if (!end) return `From ${longDate(start)}`;

  const a = parseDateOnly(start);
  const b = parseDateOnly(end);

  if (start === end) return longDate(start);
  if (a.getFullYear() !== b.getFullYear()) return `${longDate(start)} – ${longDate(end)}`;
  if (a.getMonth() !== b.getMonth()) return `${MONTHS[a.getMonth()]} ${a.getDate()} – ${MONTHS[b.getMonth()]} ${b.getDate()}, ${b.getFullYear()}`;

  return `${MONTHS[a.getMonth()]} ${a.getDate()} – ${b.getDate()}, ${b.getFullYear()}`;
}

function longDate(iso: string): string {
  const d = parseDateOnly(iso);
  return `${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

/** "Valid for 2 years", "Valid for 18 months", "Never expires". */
export function validityLabel(months: number | null): string {
  if (!months) return 'Never expires';
  if (months % 12 === 0) return `Valid for ${months / 12} ${months === 12 ? 'year' : 'years'}`;
  return `Valid for ${months} ${months === 1 ? 'month' : 'months'}`;
}

/** "9:00 AM – 12:00 PM" from a session's local times. */
export function sittingTime(starts: string, ends: string): string {
  return `${formatClock(starts)} – ${formatClock(ends)}`;
}

/** Whole days from the organisation's today to a date: negative once it has passed. */
export function daysFromToday(iso: string, timeZone?: string | null): number {
  const today = parseDateOnly(todayDateKey(timeZone));
  return Math.round((parseDateOnly(iso).getTime() - today.getTime()) / 86_400_000);
}

/** "Seats are full", "3 seats left", "Open seating". */
export function seatsLabel(capacity: number | null, left: number | null): string {
  if (capacity === null || left === null) return 'No seat limit';
  if (left === 0) return 'Every seat is taken';
  return `${left} of ${capacity} ${capacity === 1 ? 'seat' : 'seats'} left`;
}

/** What a certificate's standing means for the person holding it. */
export function standingNote(standing: CertificateStanding, expiry: string | null, timeZone?: string | null): string {
  if (standing === 'renewed') return 'You earned this certificate again since, so the newer one is the one that counts.';
  if (!expiry) return 'This certificate doesn’t expire.';

  const days = daysFromToday(expiry, timeZone);
  if (standing === 'expired') return `It expired ${-days === 1 ? 'yesterday' : `${-days} days ago`}. Ask HR about taking the training again.`;
  if (standing === 'expiring') return `It expires ${days === 0 ? 'today' : days === 1 ? 'tomorrow' : `in ${days} days`}. Ask HR about renewing it.`;
  return `Valid until ${longDate(expiry)}.`;
}
