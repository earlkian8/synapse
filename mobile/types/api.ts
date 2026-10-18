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
  /** The clock their days are judged on: their site's, or the company's (ADR 0079). */
  timezone?: string | null;
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
  /** Leaving: their resignation, exit interview and certificate (ADR 0076). */
  can_resign?: boolean;
  /** Training: their programs, sessions and certificates, and joining (ADR 0094). */
  can_train?: boolean;
  /** The app's own tour (ADR 0080): still to be offered? */
  tour?: { owed: boolean; version: number } | null;
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
  location?: { name: string; radius_meters: number; fence_type?: 'circle' | 'polygon' } | null;
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
  /** The clock the day was judged on, when it is a site's own (ADR 0079). */
  timezone?: string | null;
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

// ── Leaving (ADRs 0076, 0077) ───────────────────────────────────────────────

export type ResignationStatus = 'pending' | 'accepted' | 'declined' | 'withdrawn';

export type MyResignation = {
  id: string;
  status: ResignationStatus;
  proposed_last_day: string;
  reason: string | null;
  filed_on: string | null;
  notice_days: number;
  decided_on: string | null;
  decision_note: string | null;
  decided_by: string | null;
};

export type ExitInterviewState = {
  status: 'pending' | 'submitted' | 'waived';
  sent_on: string | null;
  submitted_on: string | null;
  can_answer: boolean;
};

export type ClearanceStatus = 'pending' | 'cleared' | 'flagged';

export type MyExit = {
  has_employee: boolean;
  can_resign: boolean;
  notice_days: number;
  today: string;
  resignations: MyResignation[];
  exit: {
    id: string;
    type: 'resignation' | 'termination' | 'retirement' | 'end_of_contract';
    status: 'initiated' | 'clearance' | 'completed' | 'cancelled';
    notice_date: string | null;
    last_working_day: string | null;
    completed_on: string | null;
    clearance: { total: number; cleared: number; flagged: number; status: 'pending' | 'in_progress' | 'cleared' };
    items: {
      id: number;
      item: string;
      department: string | null;
      status: ClearanceStatus;
      remarks: string | null;
      cleared_on: string | null;
    }[];
    certificate: { ready: boolean; number: string | null };
  } | null;
  interview: ExitInterviewState | null;
};

export type InterviewQuestion = {
  key: string;
  type: 'choice' | 'rating' | 'yes_no' | 'text';
  label: string;
  required: boolean;
  group?: string;
  options?: Record<string, string>;
};

export type ExitInterview = ExitInterviewState & {
  questions: InterviewQuestion[];
  answers: Record<string, string | number> | null;
  scale: Record<string, string>;
};

// ── Holidays (ADR 0078) ─────────────────────────────────────────────────────

export type UpcomingHoliday = {
  id: string;
  name: string;
  date: string;
  type: 'regular' | 'special_non_working' | 'special_working';
  day_off: boolean;
  repeats: string;
  /** Kept at some sites only; `site` names the person's when it is one of them. */
  local: boolean;
  site: string | null;
};

// ── Search (ADR 0081) ───────────────────────────────────────────────────────

export type SearchTarget =
  | { screen: 'leave'; id: number }
  | { screen: 'attendance'; date: string }
  | { screen: 'event'; id: string }
  | { screen: 'awards' }
  | { screen: 'holidays' }
  | { screen: 'training'; id: string }
  | { screen: 'certificate'; id: number };

export type SearchItem = {
  id: string;
  title: string;
  subtitle: string | null;
  hint: string | null;
  target: SearchTarget;
};

export type SearchGroup = {
  key: 'leave' | 'attendance' | 'events' | 'awards' | 'holidays' | 'training';
  label: string;
  items: SearchItem[];
};

// ── Account (ADR 0082) ──────────────────────────────────────────────────────

/** One's own sign-in account: the person's in every company, never HR's record. */
export type Account = {
  first_name: string;
  middle_name: string | null;
  last_name: string;
  full_name: string;
  email: string;
  email_verified: boolean;
  password_changed_at: string | null;
};

// ── My schedule (ADR 0083) ──────────────────────────────────────────────────

export type ScheduleDay = {
  date: string;
  is_today: boolean;
  is_past: boolean;
  is_working_day: boolean;
  type: 'fixed' | 'flexible' | 'hours_only' | string;
  /** The server's reading: "08:00–17:00 · 18:00–20:00", "8h" or "Rest day". */
  label: string;
  segments: { start: string; end: string }[];
  required_minutes: number;
  /** A flexible day's core hours, when it sets them. */
  core: { start: string; end: string } | null;
  schedule_name: string | null;
  /** HR changed the shift for this date only. */
  changed: boolean;
  holiday: { name: string; type: UpcomingHoliday['type']; day_off: boolean } | null;
  leave: {
    id: number;
    type: string;
    color: string | null;
    status: 'approved' | 'pending';
    half_day: boolean;
    half_day_period: 'morning' | 'afternoon' | null;
  } | null;
  /** Work is expected: a working day no holiday or approved leave excuses. */
  is_due: boolean;
  due_minutes: number;
  /** Training sessions on this day, at this person's clock (ADR 0094). They excuse no work. */
  training: ScheduleTraining[];
};

export type ScheduleTraining = {
  id: number;
  title: string | null;
  starts_at: string;
  ends_at: string;
  location: string | null;
  program: { hashid: string; name: string };
};

export type ScheduleWeek = {
  start: string;
  end: string;
  today: string;
  timezone: string;
  days: ScheduleDay[];
  totals: { shifts: number; minutes: number; training: number };
};

// ── Training (ADR 0094) ─────────────────────────────────────────────────────

export type TrainingProgramStatus = 'upcoming' | 'ongoing' | 'completed';
export type TrainingEnrollmentStatus = 'requested' | 'enrolled' | 'completed' | 'dropped' | 'declined';
/** Who may join: HR alone, anyone while seats last, or anyone HR approves. */
export type TrainingEnrollmentMode = 'closed' | 'open' | 'approval';
export type TrainingMark = 'present' | 'late' | 'absent' | 'excused';
export type CertificateStanding = 'valid' | 'expiring' | 'expired' | 'renewed';

/** A program as a participant sees it: never its budget or HR's notes. */
export type TrainingListing = {
  id: number;
  hashid: string;
  name: string;
  description: string | null;
  provider: string | null;
  start_date: string | null;
  end_date: string | null;
  capacity: number | null;
  status: TrainingProgramStatus;
  enrollment_mode: TrainingEnrollmentMode;
  issues_certificate: boolean;
  /** Null: it never expires. */
  certificate_validity_months: number | null;
  seats_remaining: number | null;
  is_full: boolean;
  sessions_count: number;
};

export type CatalogEntry = TrainingListing & {
  mine: { id: number; status: TrainingEnrollmentStatus } | null;
};

export type TrainingRate = { attended: number; counted: number; rate: number | null };

export type MyTrainingEnrollment = {
  id: number;
  status: TrainingEnrollmentStatus;
  score: number | null;
  completed_at: string | null;
  requested_on: string | null;
  /** HR's note on a declined request. */
  decision_note: string | null;
  attendance: TrainingRate;
  can_withdraw: boolean;
  certificate_id: number | null;
  program: {
    hashid: string;
    name: string;
    provider: string | null;
    description: string | null;
    start_date: string | null;
    end_date: string | null;
    status: TrainingProgramStatus;
  };
};

/** A sitting: a day, and local start and end times on the organisation's clock. */
export type TrainingSitting = {
  id: number;
  title: string | null;
  session_date: string;
  starts_at: string;
  ends_at: string;
  location: string | null;
  notes: string | null;
};

export type MyTrainingSession = TrainingSitting & {
  program: { hashid: string | null; name: string | null; provider: string | null };
};

export type TrainingCertificate = {
  id: number;
  name: string;
  issuer: string | null;
  certificate_no: string | null;
  issued_date: string | null;
  expiry_date: string | null;
  standing: CertificateStanding;
  score: number | null;
  program: { hashid: string; name: string; archived: boolean } | null;
  employee?: { full_name: string; employee_no: string | null } | null;
};

export type CertificateDetail = TrainingCertificate & {
  completed_on: string | null;
  organization: string | null;
  expiring_days: number;
};

export type MyTraining = {
  enrollments: MyTrainingEnrollment[];
  sessions: MyTrainingSession[];
  certificates: TrainingCertificate[];
  expiring_days: number;
};

/** One program with the person's own place on it. */
export type TrainingProgramDetail = TrainingListing & {
  sessions: (TrainingSitting & { is_held: boolean; mark: TrainingMark | null })[];
  mine: MyTrainingEnrollment | null;
  /** What the person can do: join at once, ask, nothing (full), or nothing at all. */
  join: 'join' | 'ask' | 'full' | null;
};
