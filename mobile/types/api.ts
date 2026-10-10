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

/** A company the identity can act in. */
export type AuthOrganization = {
  id: number;
  name: string;
  logo: string | null;
  initials: string;
  /** The IANA zone punch times are shown and judged in, e.g. "Asia/Manila" (ADR 0036). */
  timezone: string;
};

/** A request to join a company that HR hasn't answered yet (ADR 0026). */
export type PendingJoinRequest = {
  id: number;
  organization: string | null;
  requested_human: string | null;
};

export type AuthUser = {
  id: number;
  name: string;
  email: string;
  /**
   * The active organisation this session is scoped to — null when they have
   * registered but not joined a company yet, which is a valid state (ADR 0026).
   */
  organization: AuthOrganization | null;
  /** Every organisation the identity belongs to, for the workspace switcher. */
  organizations: AuthOrganization[];
  /** True when they belong to no company at all: route them to the join screen. */
  needs_workspace: boolean;
  /** Join requests still awaiting an HR decision. */
  pending_requests: PendingJoinRequest[];
  employee: AuthEmployee | null;
  can_clock: boolean;
  /** My events: answering one's own invitations (ADR 0070). */
  can_respond_events: boolean;
  /** Recognition: kudos, nominations, points and rewards (ADR 0071). */
  can_recognize: boolean;
};

/** An invitation waiting to be claimed (ADR 0026). */
export type Invitation = {
  id: number;
  code: string;
  email: string;
  expires_at: string | null;
  expires_human: string | null;
  organization: AuthOrganization;
  employee: {
    full_name: string | null;
    employee_no: string | null;
    position: string | null;
    department: string | null;
  };
};

/** What redeeming a join code did: straight in, or queued for HR review. */
export type JoinOutcome = 'admitted' | 'pending';

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
  /** Where it was (ADR 0040): the nearest site and whether it was on it. */
  location?: { name: string; radius_meters: number } | null;
  distance_meters?: number | null;
  within_geofence?: boolean | null;
  /** The phone saved it offline and sent it later. */
  offline?: boolean;
};

export type AttendanceStatus =
  | 'present'
  | 'late'
  | 'undertime'
  | 'half_day'
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
  /** The buckets a payroll reads (ADR 0038). */
  regular_minutes: number;
  approved_overtime_minutes: number;
  night_minutes: number;
  rest_day_minutes: number;
  holiday_minutes: number;
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

// ── Events (ADR 0070) ───────────────────────────────────────────────────────

export type EventResponse = 'invited' | 'accepted' | 'tentative' | 'declined';
export type EventAnswer = Exclude<EventResponse, 'invited'>;

export type MyInvitation = {
  id: number;
  response: EventResponse;
  responded_at: string | null;
  event: {
    hashid: string;
    title: string;
    description: string | null;
    type: 'event' | 'meeting';
    starts_at: string | null;
    ends_at: string | null;
    location: string | null;
    status: 'upcoming' | 'ongoing' | 'past';
    reminder_minutes: number | null;
    room: { name: string; location: string | null } | null;
    series: { id: number; summary: string } | null;
    organizer: string | null;
    attendees_count: number;
    attending_count: number;
  };
};

export type CalendarLinks = { https: string; webcal: string };

// ── Recognition (ADR 0071) ──────────────────────────────────────────────────

export type Colleague = {
  id: number;
  name: string;
  initials: string;
  photo: string | null;
  position: string | null;
  department: string | null;
};

export type FeedItem = {
  kind: 'kudos' | 'award';
  id: number;
  at: string | null;
  awarded_on?: string | null;
  from: Colleague | null;
  to: Colleague | null;
  message: string | null;
  points: number;
  award_type?: { name: string; color: string | null } | null;
};

export type RecognitionMe = {
  has_employee: boolean;
  balance: number;
  kudos_left: number;
  kudos_points: number;
  kudos_monthly_limit: number;
};

export type NominatableType = { id: number; name: string; description: string | null; color: string | null; points: number };

export type Nomination = {
  id: number;
  status: 'pending' | 'approved' | 'rejected' | 'withdrawn';
  reason: string;
  created_at: string | null;
  reviewed_at: string | null;
  review_note: string | null;
  nominee: Colleague | null;
  award_type: { id: number; name: string; color: string | null; points: number } | null;
};

export type Reward = {
  id: number;
  hashid: string;
  name: string;
  description: string | null;
  cost: number;
  stock: number | null;
  affordable: boolean | null;
};

export type Redemption = {
  id: number;
  status: 'pending' | 'fulfilled' | 'declined' | 'cancelled';
  cost: number;
  note: string | null;
  response_note: string | null;
  created_at: string | null;
  reward: { name: string } | null;
};

export type LedgerLine = {
  id: number;
  amount: number;
  kind: 'award' | 'kudos' | 'redemption' | 'refund' | 'adjustment';
  note: string | null;
  at: string | null;
};
