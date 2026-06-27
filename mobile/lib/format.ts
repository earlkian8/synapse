/** Small, dependency-free date/time/number formatters for the app. */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

/** "8:02 AM" from an ISO timestamp (or "—" when null). */
export function formatTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = new Date(iso);
  let hours = d.getHours();
  const minutes = d.getMinutes().toString().padStart(2, '0');
  const period = hours >= 12 ? 'PM' : 'AM';
  hours = hours % 12 || 12;
  return `${hours}:${minutes} ${period}`;
}

/** "8:00 AM" from a clock-face "HH:MM" string. */
export function formatClock(hhmm: string | null | undefined): string {
  if (!hhmm) return '—';
  const [h, m] = hhmm.split(':');
  let hours = parseInt(h, 10);
  const period = hours >= 12 ? 'PM' : 'AM';
  hours = hours % 12 || 12;
  return `${hours}:${(m ?? '00').padStart(2, '0')} ${period}`;
}

/** "Mon, Jun 15 2026" from a Date. */
export function formatLongDate(d: Date): string {
  return `${DAYS[d.getDay()]}, ${MONTHS[d.getMonth()]} ${d.getDate()} ${d.getFullYear()}`;
}

/** "Jun 15, 2026" from an ISO date string ("Y-m-d"). */
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = parseDateOnly(iso);
  return `${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`;
}

/** "Jun 15" — compact, no year. */
export function formatShortDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  const d = parseDateOnly(iso);
  return `${MONTHS[d.getMonth()]} ${d.getDate()}`;
}

/** Parse a "Y-m-d" as a *local* date (avoids the UTC shift `new Date('Y-m-d')` causes). */
export function parseDateOnly(iso: string): Date {
  const [y, m, d] = iso.split('-').map((n) => parseInt(n, 10));
  return new Date(y, (m ?? 1) - 1, d ?? 1);
}

/** "7h 45m" from a minute count. */
export function formatMinutes(total: number): string {
  if (!total || total <= 0) return '0h 0m';
  const h = Math.floor(total / 60);
  const m = total % 60;
  return `${h}h ${m}m`;
}

/** "07:45:12" elapsed clock from a millisecond duration (live worked counter). */
export function formatElapsed(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600)
    .toString()
    .padStart(2, '0');
  const m = Math.floor((total % 3600) / 60)
    .toString()
    .padStart(2, '0');
  const s = (total % 60).toString().padStart(2, '0');
  return `${h}:${m}:${s}`;
}

/** Title-case a snake/space token, e.g. "on_leave" → "On Leave". */
export function humanize(value: string | null | undefined): string {
  if (!value) return '—';
  return value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}
