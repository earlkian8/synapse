/** Status → {label, colour} maps, shared so a state always looks the same. */
import { status as statusColors } from '@/theme/tokens';
import type { AttendanceRequestStatus, AttendanceStatus, LeaveStatus } from '@/types/api';

export type StatusMeta = { label: string; color: string };

const ATTENDANCE: Record<AttendanceStatus, StatusMeta> = {
  present: { label: 'Present', color: statusColors.present },
  late: { label: 'Late', color: statusColors.late },
  undertime: { label: 'Undertime', color: statusColors.undertime },
  half_day: { label: 'Half Day', color: statusColors.halfDay },
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

/** An attendance request's status (ADR 0039) reads exactly as a leave request's. */
export function requestMeta(status: AttendanceRequestStatus): StatusMeta {
  return LEAVE[status] ?? { label: status, color: statusColors.rest };
}
