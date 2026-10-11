import { api } from '@/lib/api';
import type { ScheduleWeek } from '@/types/api';

/** One's own week of shifts (ADR 0083): any date in it, this week when left out. */
export const scheduleApi = {
  week: (date?: string) => api.get<{ data: ScheduleWeek }>(date ? `/schedule?week=${date}` : '/schedule'),
};
