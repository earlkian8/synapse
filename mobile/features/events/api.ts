import { api } from '@/lib/api';
import type { CalendarLinks, EventAnswer, MyInvitation } from '@/types/api';

export type EventScope = 'this' | 'following';

/** My events (ADR 0070): own invitations, answered from the phone. */
export const eventsApi = {
  list: () => api.get<{ data: MyInvitation[]; pending: number }>('/events'),
  show: (hashid: string) => api.get<{ data: MyInvitation }>(`/events/${hashid}`),
  respond: (hashid: string, response: EventAnswer, scope: EventScope = 'this') =>
    api.post<{ data: MyInvitation; answered: number }>(`/events/${hashid}/respond`, { response, scope }),
  calendar: () => api.get<CalendarLinks>('/calendar-feed'),
  resetCalendar: () => api.post<CalendarLinks>('/calendar-feed/reset'),
};
