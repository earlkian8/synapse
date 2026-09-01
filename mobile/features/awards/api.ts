import { api } from '@/lib/api';
import type { Award } from '@/types/api';

export const awardsApi = {
  list: () => api.get<{ data: Award[] }>('/awards'),
};
