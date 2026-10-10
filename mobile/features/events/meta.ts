import { status } from '@/theme/tokens';
import type { EventAnswer, EventResponse } from '@/types/api';

/** An invitee's own answers as they read to them, and each one's colour. */
export const ANSWERS: { value: EventAnswer; label: string; color: string; icon: 'check' | 'info' | 'close' }[] = [
  { value: 'accepted', label: 'Going', color: status.present, icon: 'check' },
  { value: 'tentative', label: 'Maybe', color: status.late, icon: 'info' },
  { value: 'declined', label: 'Not going', color: status.absent, icon: 'close' },
];

export const RESPONSE_LABEL: Record<EventResponse, string> = {
  invited: 'Not answered',
  accepted: 'Going',
  tentative: 'Maybe',
  declined: 'Not going',
};

export const RESPONSE_COLOR: Record<EventResponse, string> = {
  invited: status.rest,
  accepted: status.present,
  tentative: status.late,
  declined: status.absent,
};

/** "1 hour before" for a reminder, or null. */
export function reminderLabel(minutes: number | null): string | null {
  if (minutes === null) return null;
  if (minutes < 60) return `${minutes} minutes before`;
  if (minutes < 1440) return `${minutes / 60} ${minutes === 60 ? 'hour' : 'hours'} before`;
  return `${minutes / 1440} ${minutes === 1440 ? 'day' : 'days'} before`;
}
