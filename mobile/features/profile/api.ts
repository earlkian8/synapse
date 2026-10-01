import { api } from '@/lib/api';
import type { Profile } from '@/types/api';

export const profileApi = {
  show: () => api.get<{ data: Profile }>('/profile'),
};
