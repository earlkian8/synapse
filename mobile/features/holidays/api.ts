import { api } from '@/lib/api';
import type { UpcomingHoliday } from '@/types/api';

/** The holidays one keeps over the coming year (ADR 0078). */
export const holidaysApi = {
  upcoming: () => api.get<{ data: UpcomingHoliday[] }>('/holidays'),
};
