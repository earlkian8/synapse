import { formatClock, parseDateOnly } from '@/lib/format';
import { status } from '@/theme/tokens';
import type { ScheduleDay } from '@/types/api';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** A calendar date `days` after another, as "Y-m-d". */
export function shiftDate(iso: string, days: number): string {
  const date = parseDateOnly(iso);
  date.setDate(date.getDate() + days);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** "Oct 12 – 18", "Sep 28 – Oct 4", "Dec 28, 2026 – Jan 3, 2027". */
export function weekLabel(start: string, end: string): string {
  const a = parseDateOnly(start);
  const b = parseDateOnly(end);

  if (a.getFullYear() !== b.getFullYear()) {
    return `${MONTHS[a.getMonth()]} ${a.getDate()}, ${a.getFullYear()} – ${MONTHS[b.getMonth()]} ${b.getDate()}, ${b.getFullYear()}`;
  }

  if (a.getMonth() !== b.getMonth()) {
    return `${MONTHS[a.getMonth()]} ${a.getDate()} – ${MONTHS[b.getMonth()]} ${b.getDate()}`;
  }

  return `${MONTHS[a.getMonth()]} ${a.getDate()} – ${b.getDate()}`;
}

/** "Mon" and 12 for a day's date block. */
export function dayBlock(iso: string): { weekday: string; day: number } {
  const date = parseDateOnly(iso);
  return { weekday: DAYS[date.getDay()], day: date.getDate() };
}

/** "8h", "7h 30m" — what a day asks for. */
export function hours(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m === 0 ? `${h}h` : `${h}h ${m}m`;
}

/** "8:00 AM – 5:00 PM", a split shift's parts joined: the hours on the clock. */
export function shiftTimes(day: ScheduleDay): string {
  if (day.type === 'hours_only' || day.segments.length === 0) {
    return `${hours(day.required_minutes)}, any time`;
  }

  return day.segments.map((segment) => `${formatClock(segment.start)} – ${formatClock(segment.end)}`).join(', ');
}

/** The pill a day carries, if anything sets it apart from its usual shift. */
export function dayFlag(day: ScheduleDay): { label: string; color: string } | null {
  if (day.holiday?.day_off) return { label: 'Holiday', color: status.holiday };
  if (day.leave?.status === 'approved') return { label: day.leave.half_day ? 'Half day' : 'On leave', color: status.leave };
  if (day.leave?.status === 'pending') return { label: 'Pending', color: status.late };
  if (day.changed) return { label: 'Changed', color: status.overtime };
  return null;
}

/**
 * How a day reads in the week: its headline and the line under it.
 *
 * A day off for a holiday or a whole day of leave is named for what it is,
 * since its usual hours no longer apply; otherwise the headline is the hours.
 * The line says what the pill ({@link dayFlag}) does not: the leave's type and
 * half, the schedule and its length.
 */
export function dayLines(day: ScheduleDay): { title: string; subtitle?: string } {
  const leave = day.leave;
  const period = leave?.half_day_period === 'afternoon' ? 'afternoon' : 'morning';

  if (day.holiday?.day_off) {
    return { title: day.holiday.name, subtitle: 'Holiday · day off' };
  }

  if (leave?.status === 'approved' && !leave.half_day) {
    return { title: leave.type, subtitle: 'On leave · approved' };
  }

  if (!day.is_working_day) {
    return { title: 'Rest day' };
  }

  const parts: string[] = [];

  // Leave first: it is what the day's pill is about.
  if (leave?.status === 'approved') parts.push(`${leave.type}, ${period} off`);
  if (leave?.status === 'pending') parts.push(leave.half_day ? `${leave.type}, ${period}` : leave.type);
  if (day.holiday) parts.push(`${day.holiday.name}, worked`);
  if (day.schedule_name) parts.push(day.schedule_name);
  if (day.type !== 'hours_only' && day.required_minutes > 0) parts.push(hours(day.required_minutes));
  if (day.core) parts.push(`core ${formatClock(day.core.start)} – ${formatClock(day.core.end)}`);
  // Said by the pill unless leave took it.
  if (day.changed && leave) parts.push('changed for this day');

  return { title: shiftTimes(day), subtitle: parts.join(' · ') || undefined };
}
