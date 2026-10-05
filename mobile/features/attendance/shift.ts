import type { PunchType, Schedule } from '@/types/api';

/**
 * The minutes a day's shift asks for: the schedule's required hours when it states
 * them, otherwise the span from start to end (an overnight shift wraps past
 * midnight). Null when there is no shift to measure against.
 */
export function shiftMinutes(schedule: Schedule | null | undefined): number | null {
  if (!schedule) return null;

  if (schedule.required_hours) {
    return Math.round(schedule.required_hours * 60);
  }

  if (!schedule.start_time || !schedule.end_time) return null;

  const toMinutes = (hhmm: string) => {
    const [h, m] = hhmm.split(':').map((n) => parseInt(n, 10));
    return h * 60 + (m || 0);
  };

  const span = toMinutes(schedule.end_time) - toMinutes(schedule.start_time);
  return span > 0 ? span : span + 24 * 60;
}

/** Where the day stands, in a word, for headings: "Not clocked in", "On break"… */
export function dayHeadline(next: PunchType | null, clockedIn: boolean, completed: boolean): string {
  if (completed) return 'Day complete';
  if (next === 'break_end') return 'On break';
  if (clockedIn) return 'You’re clocked in';
  return 'Not clocked in';
}
