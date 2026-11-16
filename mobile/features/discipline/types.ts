/** My notices (ADR 0105) — as the API's DisciplineScreens answers them. */

export type CaseStatus = 'reported' | 'notice_issued' | 'explained' | 'hearing' | 'decided' | 'closed' | 'withdrawn';

export type Penalty = 'verbal_warning' | 'written_warning' | 'final_warning' | 'suspension' | 'dismissal';

export type NoticeSummary = {
  hashid: string;
  reference: string;
  status: CaseStatus;
  offense: { code: string; title: string; gravity: string } | null;
  incident_date: string;
  reply_due_on: string | null;
  reply_overdue: boolean;
  explained: boolean;
  hearing_at: string | null;
  finding: 'upheld' | 'cleared' | null;
  penalty: Penalty | null;
  decided_at: string | null;
};

export type Notice = NoticeSummary & {
  offense: { id: number; code: string; title: string; gravity: string; ground: string | null; just_cause: boolean } | null;
  incident_time: string | null;
  location: string | null;
  narrative: string;
  notice_grounds: string | null;
  notice_issued_at: string | null;
  notice_received_at: string | null;
  preventive_from: string | null;
  preventive_to: string | null;
  explanation: string | null;
  explained_at: string | null;
  hearing_requested: boolean;
  hearing_place: string | null;
  hearing_held_at: string | null;
  penalty_label: string | null;
  suspension_days: number | null;
  penalty_from: string | null;
  penalty_to: string | null;
  decision: string | null;
  decision_received_at: string | null;
  withdrawn_reason: string | null;
};

export type Standing = {
  key: 'dismissed' | 'suspended' | 'preventive' | 'final_warning' | 'warned' | 'under_review' | 'good';
  label: string;
  until: string | null;
};

export type MyNotices = {
  record: { standing: Standing; open: number; upheld: number; cleared: number };
  cases: NoticeSummary[];
};
