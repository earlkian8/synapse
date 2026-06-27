import { api } from '@/lib/api';
import type { LeaveBalance, LeaveRequest, LeaveType, Paginated } from '@/types/api';

export type FileLeavePayload = {
  leave_type_id: number;
  start_date: string;
  end_date: string;
  is_half_day: boolean;
  half_day_period?: 'morning' | 'afternoon' | null;
  reason?: string | null;
};

export const leaveApi = {
  types: () => api.get<{ data: LeaveType[] }>('/leave/types'),

  balances: (year?: number) =>
    api.get<{ data: LeaveBalance[]; year: number }>(`/leave/balances${year ? `?year=${year}` : ''}`),

  requests: (status?: string) =>
    api.get<Paginated<LeaveRequest>>(`/leave/requests${status ? `?status=${status}` : ''}`),

  file: (payload: FileLeavePayload) =>
    api.post<{ data: LeaveRequest; message: string }>('/leave/requests', payload),

  cancel: (id: number) =>
    api.patch<{ data: LeaveRequest; message: string }>(`/leave/requests/${id}/cancel`),
};
