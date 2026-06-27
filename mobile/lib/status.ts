/** Status → {label, colour} maps, shared so a state always looks the same. */
import { status as statusColors } from '@/theme/tokens';
import type { AttendanceStatus, LeaveStatus } from '@/types/api';

export type StatusMeta = { label: string; color: string };

const ATTENDANCE: Record<AttendanceStatus, StatusMeta> = {
  present: { label: 'Present', color: statusColors.present },
  late: { label: 'Late', color: statusColors.late },
  undertime: { label: 'Undertime', color: statusColors.undertime },
  absent: { label: 'Absent', color: statusColors.absent },
  on_leave: { label: 'On Leave', color: statusColors.leave },
  day_off: { label: 'Rest Day', color: statusColors.rest },
  holiday: { label: 'Holiday', color: statusColors.holiday },
  incomplete: { label: 'Incomplete', color: statusColors.incomplete },
};

const LEAVE: Record<LeaveStatus, StatusMeta> = {
  pending: { label: 'Pending', color: statusColors.late },
  approved: { label: 'Approved', color: statusColors.present },
  rejected: { label: 'Rejected', color: statusColors.absent },
  cancelled: { label: 'Cancelled', color: statusColors.rest },
};

export function attendanceMeta(status: AttendanceStatus): StatusMeta {
  return ATTENDANCE[status] ?? { label: status, color: statusColors.rest };
}

export function leaveMeta(status: LeaveStatus): StatusMeta {
  return LEAVE[status] ?? { label: status, color: statusColors.rest };
}
