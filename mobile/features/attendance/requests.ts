import type { AttendanceRequest, AttendanceRequestType } from '@/types/api';
import { formatClock, formatMinutes } from '@/lib/format';

/** What the employee can ask for (ADR 0039), in the words the ERP uses. */
export const REQUEST_TYPES: { value: AttendanceRequestType; label: string; hint: string }[] = [
  { value: 'correction', label: 'Correction', hint: 'A punch the clock missed or got wrong.' },
  { value: 'overtime', label: 'Overtime', hint: 'Time past your shift that needs approval.' },
  { value: 'official_business', label: 'Official business', hint: 'Away from the site on company business.' },
  { value: 'remote_work', label: 'Remote work', hint: 'Working somewhere other than the site.' },
];

export function requestTypeLabel(type: AttendanceRequestType): string {
  return REQUEST_TYPES.find((t) => t.value === type)?.label ?? type;
}

const CORRECTION_FIELDS = [
  { key: 'time_in', label: 'In' },
  { key: 'break_start', label: 'Break' },
  { key: 'break_end', label: 'Back' },
  { key: 'time_out', label: 'Out' },
] as const;

/** What a request asks for, in a line: "Out 6:00 PM", "2h overtime". */
export function describeRequest(request: Pick<AttendanceRequest, 'type' | 'payload'>): string {
  const { type, payload } = request;

  if (type === 'correction') {
    const parts = CORRECTION_FIELDS.filter(({ key }) => payload[key]).map(({ key, label }) => `${label} ${formatClock(payload[key])}`);
    return parts.length > 0 ? parts.join(' · ') : 'No times given';
  }

  if (type === 'overtime') {
    return `${formatMinutes(payload.minutes ?? 0)} overtime${payload.pre_approval ? ', in advance' : ''}`;
  }

  return payload.location || (type === 'official_business' ? 'A full working day' : 'Punches still required');
}
