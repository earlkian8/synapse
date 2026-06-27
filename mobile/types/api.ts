/** Shared shapes returned by the SYNAPSE mobile API (server/routes/api.php). */

export type Schedule = {
  name?: string;
  start_time?: string | null;
  end_time?: string | null;
  grace_minutes?: number | null;
  required_hours?: number | null;
};

export type AuthEmployee = {
  id: number;
  full_name: string;
  employee_no: string | null;
  photo: string | null;
  schedule: Schedule | null;
};

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  employee: AuthEmployee | null;
  can_clock: boolean;
};

export type PunchType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';

export type Punch = {
  id: number;
  type: PunchType;
  punched_at: string | null;
  source: string;
  latitude: number | null;
  longitude: number | null;
  accuracy: number | null;
  photo: string | null;
  note: string | null;
};

export type AttendanceStatus =
  | 'present'
  | 'late'
  | 'undertime'
  | 'absent'
  | 'on_leave'
  | 'day_off'
  | 'holiday'
  | 'incomplete';

export type AttendanceRecord = {
  id: number | null;
  work_date: string | null;
  status: AttendanceStatus;
  scheduled_start: string | null;
  scheduled_end: string | null;
  first_in_at: string | null;
  last_out_at: string | null;
  worked_minutes: number;
  break_minutes: number;
  late_minutes: number;
  undertime_minutes: number;
  overtime_minutes: number;
  is_manual: boolean;
  remarks: string | null;
  punches?: Punch[];
};

export type TodayResponse = {
  data: AttendanceRecord;
  next_expected: PunchType | null;
  allowed: PunchType[];
};

export type AttendanceSummary = {
  from: string;
  to: string;
  days_recorded: number;
  status_counts: Record<AttendanceStatus, number>;
  worked_minutes: number;
  break_minutes: number;
  late_minutes: number;
  undertime_minutes: number;
  overtime_minutes: number;
};

export type LeaveType = {
  id: number;
  name: string;
  code: string;
  description: string | null;
  color: string | null;
  default_days: number;
  is_paid: boolean;
  allow_half_day: boolean;
  requires_approval: boolean;
};

export type LeaveBalance = {
  leave_type_id: number;
  name: string;
  code: string;
  color: string | null;
  entitled: number;
  used: number;
  pending: number;
  remaining: number;
  is_custom: boolean;
};

export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export type LeaveRequest = {
  id: number;
  hashid: string;
  status: LeaveStatus;
  start_date: string | null;
  end_date: string | null;
  days: number;
  is_half_day: boolean;
  half_day_period: 'morning' | 'afternoon' | null;
  reason: string | null;
  review_note: string | null;
  reviewed_at: string | null;
  type: { id: number; name: string; code: string; color: string | null; is_paid: boolean } | null;
  created_at: string | null;
  created_human: string | null;
};

export type Award = {
  id: number;
  awarded_on: string | null;
  reason: string | null;
  award_type: { id: number; name: string; color: string | null } | null;
  granted_by: { id: number; name: string } | null;
};

export type Profile = {
  id: number;
  full_name: string;
  initials: string;
  employee_no: string | null;
  photo: string | null;
  first_name: string | null;
  middle_name: string | null;
  last_name: string | null;
  suffix: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  birth_date: string | null;
  gender: string | null;
  civil_status: string | null;
  employment_type: string | null;
  employment_status: string | null;
  date_hired: string | null;
  date_regularized: string | null;
  department: { id: number; name: string } | null;
  position: { id: number; title: string } | null;
  manager: { id: number; full_name: string } | null;
  schedule: Schedule | null;
  government_ids: {
    tin: string | null;
    sss_no: string | null;
    philhealth_no: string | null;
    pagibig_no: string | null;
  };
  bank: { name: string | null; account_no: string | null };
};

export type Paginated<T> = {
  data: T[];
  links?: unknown;
  meta?: { current_page: number; last_page: number; total: number };
};
