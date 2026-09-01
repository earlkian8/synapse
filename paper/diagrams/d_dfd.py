"""Figures 3.4-3.18 - context diagram, Level-1 DFD and the thirteen module-level
Level-2 DFDs. Gane-Sarson notation throughout.

Module DFDs share one layout so they can be read as a set:
    external entities (left)  |  numbered processes (centre)  |  data stores (right)
                                                              |  externals / notes (far right)
"""

from svgkit import (DASH, FILL_LIGHT, FILL_MID, INK, MUTED, SW, T_SMALL, WHITE,
                    Fig, legend, rect, text)

# -- Shared module-DFD grid ---------------------------------------------------

CW = 1300
EX, EW = 26, 190            # external entities
PX, PW = 300, 356           # processes
SX, SW_ = 740, 268          # data stores
RX, RW = 1064, 210          # far-right externals / notes
ROW0, PITCH, PH = 92, 104, 84

DFD_LEGEND = [
    ("entity", "external entity"),
    ("round", "process"),
    ("store", "data store"),
    ("arrow", "data flow"),
    ("dashed-arrow", "external / derived flow"),
]


def _rowy(row, h=PH):
    return ROW0 + row * PITCH + (PH - h) / 2


def _sub(lines):
    """First line normal weight, the rest muted and small."""
    out = []
    for i, l in enumerate(lines):
        out.append(l if i == 0 else (l, 9, "normal", MUTED))
    return out


def build_module(number, name, subtitle, entities, processes, stores, externals,
                 flows, footer=None):
    """Declarative module-level DFD.

    entities   [(key, row, [lines])]
    processes  [(key, tag, [lines])]        - laid out top to bottom, one per row
    stores     [(key, row, tag, [lines])]
    externals  [(key, row, kind, [lines])]  - kind 'entity' or 'note'
    flows      [(a, b, label, opts)]        - opts is a dict passed to Fig.edge
    """
    def maker():
        rows = len(processes)
        height = ROW0 + rows * PITCH + 108
        f = Fig(CW, height,
                "Figure %s  Level-2 Data Flow Diagram - %s" % (number, name),
                subtitle)

        for key, row, lines in entities:
            f.node(key, EX, _rowy(row), EW, PH, "entity", _sub(lines), WHITE,
                   None, None, 10.5)

        for i, (key, tag, lines) in enumerate(processes):
            f.node(key, PX, _rowy(i), PW, PH, "proc", _sub(lines), WHITE, None,
                   tag, 10.5)

        for key, row, tag, lines in stores:
            f.node(key, SX, _rowy(row, 56), SW_, 56, "store", _sub(lines), WHITE,
                   None, tag, 10)

        for key, row, kind, lines in externals:
            h = 84 if kind == "entity" else 120
            f.node(key, RX, _rowy(row, h), RW, h, kind, _sub(lines), WHITE,
                   DASH if kind == "entity" and lines and "Gemini" in lines[0] else None,
                   None, 10 if kind == "entity" else 9.5)

        # spread the vertical runs of the store edges so they do not overlap
        via_cycle = [700, 682, 718, 691, 709, 673, 727]
        vi = 0
        for flow in flows:
            a, b, label = flow[0], flow[1], flow[2]
            opts = dict(flow[3]) if len(flow) > 3 else {}
            if "sides" not in opts:
                opts["sides"] = ("e", "w")
            if opts["sides"] == ("e", "w") and (b in dict((s[0], s) for s in stores)
                                                or a in dict((s[0], s) for s in stores)):
                if "via" not in opts:
                    opts["via"] = via_cycle[vi % len(via_cycle)]
                    vi += 1
            f.edge(a, b, label, **opts)

        legend(f, EX + 4, height - 82, DFD_LEGEND, title="LEGEND")
        if footer:
            f.front(text(CW / 2 + 120, height - 22, footer, T_SMALL, "middle",
                         fill=MUTED))
        slug = name.lower().replace(" ", "-").replace("&", "and").replace(",", "")
        return "fig-%s-dfd2-%s.svg" % (number.replace(".", "-"), slug), f
    return maker


# -- Figure 3.4  Context diagram ---------------------------------------------

def fig_context():
    f = Fig(1120, 780, "Figure 3.4  Context Diagram (Data Flow Diagram Level 0)",
            "SYNAPSE as one process, with every external entity it exchanges data with")

    f.node("sys", 400, 300, 320, 150, "circle",
           [("0", 15, "bold", INK), "SYNAPSE",
            ("Multi-Model ML and LLM", 9.5, "normal", MUTED),
            ("HR ERP", 9.5, "normal", MUTED)], FILL_LIGHT)

    f.node("hr", 40, 120, 200, 80, "entity",
           ["HR Manager / Officer", ("full module access", 9, "normal", MUTED)])
    f.node("head", 40, 300, 200, 80, "entity",
           ["Department Head", ("approvals + team visibility", 9, "normal", MUTED)])
    f.node("staff", 40, 480, 200, 80, "entity",
           ["Employee (Staff)", ("self-service, web + mobile", 9, "normal", MUTED)])
    f.node("appl", 40, 630, 200, 70, "entity",
           ["Job Applicant", ("public careers page", 9, "normal", MUTED)])

    f.node("gem", 880, 120, 200, 80, "entity",
           ["Google Gemini API", ("LLM assistant + insights", 9, "normal", MUTED)],
           WHITE, DASH)
    f.node("ml", 880, 300, 200, 80, "entity",
           ["ML Inference Service", ("3 scikit-learn models", 9, "normal", MUTED)])
    f.node("mail", 880, 480, 200, 80, "entity",
           ["Mail / Push Providers", ("SMTP, VAPID web push", 9, "normal", MUTED)],
           WHITE, DASH)
    f.node("exec", 880, 630, 200, 70, "entity",
           ["Executive Leadership", ("reports + analytics", 9, "normal", MUTED)])

    f.edge("hr", "sys", ["employee, posting, appraisal,", "schedule and setup records"],
           sides=("e", "w"), offset=-18, label_dy=-16, label_at=0.4)
    f.edge("sys", "hr", ["dashboards, action queue,", "notifications"],
           sides=("w", "e"), offset=20, label_dy=22, label_at=0.4)
    f.edge("head", "sys", "approval decisions, ratings", sides=("e", "w"), offset=-16)
    f.edge("sys", "head", "team attendance, appraisals", sides=("w", "e"), offset=16,
           label_dy=18)
    f.edge("staff", "sys", ["punches (GPS + selfie),", "leave filings, RSVPs"],
           sides=("e", "w"), offset=-18, label_dy=-16, label_at=0.4)
    f.edge("sys", "staff", ["DTR, balances, awards,", "invitations"],
           sides=("w", "e"), offset=20, label_dy=22, label_at=0.4)
    f.edge("appl", "sys", "application + resume", sides=("e", "w"), offset=-14)
    f.edge("sys", "appl", "status message / portal link", sides=("w", "e"), offset=14,
           label_dy=18)

    f.edge("sys", "gem", ["compact digest + documents", "+ permitted tool schemas"],
           sides=("e", "w"), offset=-18, dashed=True, label_dy=-16, label_at=0.55)
    f.edge("gem", "sys", ["strict JSON insight /", "function call"],
           sides=("w", "e"), offset=20, dashed=True, label_dy=22, label_at=0.45)
    f.edge("sys", "ml", "feature vectors (batch)", sides=("e", "w"), offset=-16)
    f.edge("ml", "sys", "probability, score, tier, factors", sides=("w", "e"),
           offset=16, label_dy=18)
    f.edge("sys", "mail", "notification payloads", sides=("e", "w"), offset=-14)
    f.edge("sys", "exec", "reports, ML signals, exports", sides=("e", "w"), offset=14)

    legend(f, 40, 726, [("entity", "external entity"), ("round", "system boundary"),
                        ("dashed-arrow", "external AI dependency")], title="LEGEND")
    return "fig-3-4-context-diagram.svg", f


# -- Figure 3.5  Level 1 ------------------------------------------------------

def fig_dfd1():
    f = Fig(1300, 920, "Figure 3.5  Data Flow Diagram - Level 1",
            "The twelve top-level processes of SYNAPSE and the data stores they share")

    for k, y, lbl in [("hr", 116, "HR Manager /|Officer"), ("head", 300, "Department|Head"),
                      ("staff", 484, "Employee|(Staff)"), ("appl", 668, "Job|Applicant")]:
        f.node(k, 16, y, 140, 74, "entity", lbl.split("|"))

    P = [
        ("p1", 214, 92, "1.0", ["Talent Acquisition", "posting, pipeline, fit", "score, interview, hire"]),
        ("p2", 214, 208, "2.0", ["Onboarding", "programme match, checklist,", "ownership routing"]),
        ("p3", 214, 324, "3.0", ["Employee 201 File", "directory, documents,", "certifications, history"]),
        ("p4", 214, 440, "4.0", ["Time and Attendance", "punch validation,", "daily derivation"]),
        ("p5", 214, 556, "5.0", ["Leave Management", "day computation,", "approval routing"]),
        ("p6", 214, 672, "6.0", ["Performance", "frameworks, two-level", "scoring, calibration"]),
        ("p7", 700, 92, "7.0", ["Training and Development", "programmes, enrolment,", "completion scoring"]),
        ("p8", 700, 208, "8.0", ["Awards and Recognition", "nomination board,", "citation drafting"]),
        ("p9", 700, 324, "9.0", ["Events and Meetings", "invitation, RSVP,", "reminders, .ics export"]),
        ("p10", 700, 440, "10.0", ["Offboarding and Clearance", "routed sign-off,", "separation bridge"]),
        ("p11", 700, 556, "11.0", ["Predictive Analytics", "feature mapping, batch", "scoring, run storage"]),
        ("p12", 700, 672, "12.0", ["Assistant, Dashboard, Reports", "tool dispatch, aggregation,", "AI insight, export"]),
    ]
    for k, x, y, tag, lines in P:
        f.node(k, x, y, 240, 94, "proc", _sub(lines), WHITE, None, tag, 10.5)

    S = [("d1", 474, 92, "D1", "Recruitment"), ("d2", 474, 158, "D2", "Onboarding"),
         ("d3", 474, 224, "D3", "Employee 201"), ("d4", 474, 290, "D4", "Attendance"),
         ("d5", 474, 356, "D5", "Leave"), ("d6", 474, 422, "D6", "Performance"),
         ("d7", 474, 488, "D7", "Training"), ("d8", 474, 554, "D8", "Awards"),
         ("d9", 474, 620, "D9", "Events"), ("d10", 474, 686, "D10", "Offboarding"),
         ("d11", 474, 752, "D11", "Prediction runs")]
    for k, x, y, tag, lbl in S:
        f.node(k, x, y, 190, 50, "store", [(lbl, 9.8, "normal", INK)], WHITE, None, tag)

    f.node("d12", 700, 800, 400, 50, "store",
           [("Activity log  /  notifications  /  conversations", 9.8, "normal", INK)],
           FILL_LIGHT, None, "D12")

    f.node("gem", 1140, 208, 140, 74, "entity", ["Gemini API"], WHITE, DASH)
    f.node("mls", 1140, 556, 140, 74, "entity", ["ML Inference", "Service"])

    for a, b in [("p1", "d1"), ("p2", "d2"), ("p3", "d3"), ("p4", "d4"), ("p5", "d5"),
                 ("p6", "d6")]:
        f.edge(a, b, sides=("e", "w"), both=True)
    for a, b in [("p7", "d7"), ("p8", "d8"), ("p9", "d9"), ("p10", "d10"),
                 ("p11", "d11")]:
        f.edge(a, b, sides=("w", "e"), both=True)

    f.edge("p1", "p2", sides=("w", "w"), via=192, label="hire event", label_dx=8)
    f.edge("p2", "p3", sides=("w", "w"), via=178, label="new hire checklist", label_dx=8)

    f.edge("hr", "p1", sides=("e", "w"))
    f.edge("hr", "p3", sides=("e", "w"))
    f.edge("head", "p5", sides=("e", "w"))
    f.edge("head", "p6", sides=("e", "w"))
    f.edge("staff", "p4", sides=("e", "w"))
    f.edge("staff", "p5", sides=("e", "w"))
    f.edge("appl", "p1", sides=("e", "w"))

    f.edge("p11", "mls", sides=("e", "w"), both=True, label="feature batch")
    f.edge("p12", "gem", sides=("e", "e"), via=1290, both=True, label="tools + digest")
    f.edge("p8", "gem", sides=("e", "w"), dashed=True, both=True, label="citation")

    f.edge("p12", "d12", sides=("s", "n"), both=True)

    legend(f, 16, 800, DFD_LEGEND, title="LEGEND")
    f.front(text(660, 894,
                 "Every process reads and writes through the same tenant-scoped models. Process 10.0 writes the separation back into D3, and process 11.0 reads its ERP-servable features from D3, D4, D6 and D7;",
                 T_SMALL, "middle", fill=MUTED))
    f.front(text(660, 908,
                 "those cross-process flows are decomposed in the Level-2 diagrams (Figures 3.8, 3.15 and 3.16) rather than drawn here, to keep the Level-1 view readable.",
                 T_SMALL, "middle", fill=MUTED))
    return "fig-3-5-dfd-level-1.svg", f


# -- Level-2 module specifications -------------------------------------------

M_RECRUITMENT = dict(
    entities=[("hr", 0, ["HR / Recruiter"]),
              ("cand", 3, ["Job Applicant", "public careers page"]),
              ("head2", 6, ["Department Head", "interview panel"])],
    processes=[
        ("p1", "1.1", ["Manage job posting", "draft / open / closed / filled;", "a closing date is required once open"]),
        ("p2", "1.2", ["Receive application", "rate-limited, honeypot-guarded,", "resume strictly validated"]),
        ("p3", "1.3", ["Compute fit score", "ApplicantScorer - five weighted", "components, normalised (Figure 3.25)"]),
        ("p4", "1.4", ["Generate AI candidate insight", "Gemini reads the resume and documents", "natively; government IDs never sent"]),
        ("p5", "1.5", ["Advance pipeline stage", "applied / screening / interview /", "offer / hired / rejected"]),
        ("p6", "1.6", ["Schedule and record interview", "InterviewScheduling::book();", "scorecards blind until submitted"]),
        ("p7", "1.7", ["Hire applicant", "ApplicantHirer::hire() -", "one transaction (Figure 3.22)"]),
        ("p8", "1.8", ["Auto-close past-due postings", "daily scheduled command"]),
    ],
    stores=[("d1", 0, "D1.1", ["job_postings"]),
            ("d2", 1, "D1.2", ["applicants", "applicant_documents"]),
            ("d3", 2, "D1.3", ["job_applications", "fit_score, ai_insights"]),
            ("d4", 5, "D1.4", ["interviews"]),
            ("d5", 6, "D1.5", ["employees", "onboarding_cases"])],
    externals=[("gem", 3, "entity", ["Gemini API"]),
               ("notif", 4.6, "entity", ["CandidateNotifier", "the only path that", "messages a candidate"])],
    flows=[
        ("hr", "p1", "vacancy details", {}),
        ("cand", "p2", "form + resume", {}),
        ("head2", "p6", "scorecard", {}),
        ("p1", "d1", None, {"both": True}),
        ("p8", "d1", None, {"both": True}),
        ("p2", "d2", None, {"both": True}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("p3", "d3", "score + breakdown", {"both": True}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("p4", "gem", "digest + files", {"dashed": True, "both": True}),
        ("p4", "d3", "persisted insight", {}),
        ("p5", "d3", None, {"both": True}),
        ("p6", "d4", None, {"both": True}),
        ("p7", "d5", "employee + onboarding case", {}),
        ("p3", "p5", "recommended next step", {"sides": ("w", "w"), "via": 276, "dashed": True}),
        ("p6", "p5", "verdict", {"sides": ("w", "w"), "via": 262}),
        ("p5", "p7", None, {"sides": ("w", "w"), "via": 248}),
        ("p5", "notif", "stage message", {"dashed": True}),
    ],
    footer="Ranking is pure arithmetic and runs from the moment a candidate applies; the LLM adds a reading on top of it, never in place of it.",
)

M_ONBOARDING = dict(
    entities=[("hr", 0, ["HR Manager"]),
              ("own", 4, ["Task Owner", "IT / Finance / Head"]),
              ("hire", 6, ["New Hire"])],
    processes=[
        ("p1", "2.1", ["Maintain programme templates", "optionally targeted at a department", "and/or employment type; one default"]),
        ("p2", "2.2", ["Match programme and open case", "department+type > department >", "type > company default"]),
        ("p3", "2.3", ["Instantiate the checklist", "JourneyBuilder turns a relative day", "offset into a real dated task"]),
        ("p4", "2.4", ["Route ownership", "TaskRouter resolves owner ROLES to", "people at seed time; else unassigned"]),
        ("p5", "2.5", ["Complete / skip a task", "evidence enforced in TaskTransitions;", "'skipped' is exempt"]),
        ("p6", "2.6", ["Derive progress and lifecycle", "(done + skipped) / total;", "pending -> in_progress automatically"]),
        ("p7", "2.7", ["Chase outstanding work", "grouped per person - four overdue", "items produce one message, not four"]),
        ("p8", "2.8", ["Complete / cancel / reopen case", "an unfinished count is stated in both", "the confirmation and the audit log"]),
    ],
    stores=[("d1", 0, "D2.1", ["onboarding_programs", "onboarding_program_tasks"]),
            ("d2", 1, "D2.2", ["onboarding_cases"]),
            ("d3", 2, "D2.3", ["onboarding_tasks"]),
            ("d4", 6, "D2.4", ["onboarding_reminders", "send log and once-ness guard"]),
            ("d5", 7, "D2.5", ["activity_logs"])],
    externals=[("rec", 1, "entity", ["Recruitment", "the hire bridge"]),
               ("note", 5.2, "note", ["ONE CASE, EVER", "An employee may have exactly", "one onboarding case, so a", "re-run of the bridge cannot", "duplicate a checklist."])],
    flows=[
        ("hr", "p1", None, {}),
        ("rec", "p2", "new employee", {"sides": ("w", "e")}),
        ("hr", "p2", "manual start", {}),
        ("own", "p5", "tick + evidence", {}),
        ("hire", "p5", None, {}),
        ("p1", "d1", None, {"both": True}),
        ("p2", "d2", None, {"both": True}),
        ("d1", "p3", "blueprint tasks", {"sides": ("w", "e")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("p3", "d3", None, {}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("p4", "d3", "assignee or unassigned", {}),
        ("p5", "d3", None, {"both": True}),
        ("p5", "p6", None, {"sides": ("s", "n")}),
        ("p6", "d2", "derived progress", {}),
        ("d3", "p7", "overdue items", {"sides": ("w", "e")}),
        ("p7", "d4", None, {"both": True}),
        ("p8", "d2", None, {"both": True}),
        ("p8", "d5", None, {}),
    ],
    footer="Statutory deadlines bypass the offset builder entirely - a legal due date is a date, not 'day 3'.",
)

M_EMPLOYEE = dict(
    entities=[("hr", 0, ["HR Manager"]),
              ("emp", 3, ["Employee"]),
              ("head", 7, ["Department Head"])],
    processes=[
        ("p1", "3.1", ["Create / edit the 201 record", "EmployeeNumbers issues EMP-NNNNN;", "max(id)+1 is never used"]),
        ("p2", "3.2", ["Encrypt government identifiers", "TIN, SSS, PhilHealth and Pag-IBIG", "at rest - and so not SQL-searchable"]),
        ("p3", "3.3", ["Store 201 documents", "private disk, routed delivery,", "every access logged"]),
        ("p4", "3.4", ["Track certifications and expiry"]),
        ("p5", "3.5", ["Write career history", "a promotion row is written on any", "position or salary change"]),
        ("p6", "3.6", ["Invite a roster line to the app", "hashed link token + retypeable code;", "possession of the code is authorisation"]),
        ("p7", "3.7", ["Admit a member", "OrganizationProvisioner::admit() -", "the one place somebody becomes staff"]),
        ("p8", "3.8", ["Answer a workforce question", "live tenant-scoped SQL exposed as", "tools, filtered by EmployeeDisclosure"]),
    ],
    stores=[("d1", 0, "D3.1", ["employees"]),
            ("d2", 2, "D3.2", ["employee_documents", "employee_certifications"]),
            ("d3", 4, "D3.3", ["employee_promotions"]),
            ("d4", 5, "D3.4", ["employee_invitations", "organization_join_requests"]),
            ("d5", 6, "D3.5", ["organization_user", "role_user"])],
    externals=[("mail", 5, "entity", ["Mail transport"]),
               ("note", 7, "note", ["WITHHELD MEANS WITHHELD", "Nine fields - tin, sss_no,", "philhealth_no, pagibig_no,", "bank_name, bank_account_no,", "basic_salary, address,", "birth_date - are withheld from", "every assistant read, at every", "permission level."])],
    flows=[
        ("hr", "p1", None, {}),
        ("emp", "p3", "upload", {}),
        ("head", "p8", None, {}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("p1", "d1", None, {"both": True}),
        ("p2", "d1", "ciphertext", {"both": True}),
        ("p3", "d2", None, {"both": True}),
        ("p4", "d2", None, {"both": True}),
        ("p1", "p5", "changed position or salary", {"sides": ("w", "w"), "via": 276}),
        ("p5", "d3", None, {}),
        ("p6", "d4", None, {"both": True}),
        ("p6", "mail", "link + code", {"dashed": True}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "d5", "membership + role", {}),
        ("emp", "p7", "code redemption", {}),
        ("d1", "p8", "rows, nine fields removed", {"sides": ("w", "e")}),
    ],
    footer="The ERP owns employment; the person owns identity. Creating an employee record creates no login, and HR can neither set nor reset a password.",
)

M_ATTENDANCE = dict(
    entities=[("emp", 1, ["Employee", "web /attendance/me or", "the mobile Clock tab"]),
              ("hr", 5, ["HR / Timekeeper"])],
    processes=[
        ("p1", "4.1", ["Validate the punch transition", "AttendanceClock: no double clock-in,", "no clock-out before clock-in"]),
        ("p2", "4.2", ["Capture punch context", "source (web / mobile / kiosk / biometric /", "manual), GPS, optional selfie, recorder"]),
        ("p3", "4.3", ["Recompute the daily record", "AttendanceCalculator walks the punch", "stream as a state machine (Figure 3.28)"]),
        ("p4", "4.4", ["Derive the daily status", "present / late / undertime / incomplete /", "absent / day_off / on_leave"]),
        ("p5", "4.5", ["Build the full roster view", "built from EVERY employee, so a person", "with no punches appears, never vanishes"]),
        ("p6", "4.6", ["Flag anomalies", "late by N, missing time-out, left early,", "unscheduled absence"]),
        ("p7", "4.7", ["Correct and approve", "manual entry, correction, and bulk", "approval of pending records"]),
        ("p8", "4.8", ["Aggregate the period", "rate = present days / scheduled days x 100", "(null when nothing was scheduled)"]),
    ],
    stores=[("d1", 1, "D4.1", ["attendance_punches", "raw, append-only events"]),
            ("d2", 3, "D4.2", ["attendance_records", "one derived row per day"]),
            ("d3", 2, "D4.3", ["work_schedules", "snapshotted onto the record"]),
            ("d4", 4, "D4.4", ["holidays"]),
            ("d5", 5, "D4.5", ["leave_requests", "approved coverage"])],
    externals=[("csv", 6, "entity", ["CSV export", "attendance.export"]),
               ("ml", 7, "entity", ["Predictive Analytics", "90-day overtime sum"])],
    flows=[
        ("emp", "p1", "clock_in / break / clock_out", {}),
        ("hr", "p7", None, {}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("p2", "d1", None, {}),
        ("d1", "p3", "ordered punches", {"sides": ("w", "e")}),
        ("d3", "p3", "schedule + grace", {"sides": ("w", "e")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("d5", "p4", "does an approved leave cover this date?", {"sides": ("w", "e")}),
        ("d4", "p4", None, {"sides": ("w", "e")}),
        ("p4", "d2", "worked / break / late / undertime / overtime", {}),
        ("p5", "d2", None, {"both": True}),
        ("p6", "d2", None, {"both": True}),
        ("p7", "d2", None, {"both": True}),
        ("p8", "d2", None, {"both": True}),
        ("p8", "csv", None, {}),
        ("p8", "ml", None, {"dashed": True}),
    ],
    footer="AttendanceClock is the only path that ever writes a punch, so web, mobile and assistant results cannot drift apart.",
)

M_LEAVE = dict(
    entities=[("emp", 1, ["Employee"]),
              ("appr", 5, ["Approver", "HR Manager or", "Department Head"])],
    processes=[
        ("p1", "5.1", ["Resolve the holiday set", "HolidayCalendar expands yearly-recurring", "entries across the years the range spans"]),
        ("p2", "5.2", ["Compute chargeable days", "LeaveCalculator, server-side only;", "a client-sent count is never trusted"]),
        ("p3", "5.3", ["Validate against the balance", "remaining = entitlement - used"]),
        ("p4", "5.4", ["Persist the request", "pending, or auto-approved when the", "leave type does not require approval"]),
        ("p5", "5.5", ["Route for approval", "Notifier::toRole('hr-manager')"]),
        ("p6", "5.6", ["Approve / reject", "the review drawer shows the balance", "impact for that type and year"]),
        ("p7", "5.7", ["Derive the balance", "used = SUM(approved days);", "only the entitlement is a stored row"]),
        ("p8", "5.8", ["Cancel a request", "employee-initiated withdrawal"]),
    ],
    stores=[("d1", 2, "D5.1", ["leave_types", "policy flags and colour"]),
            ("d2", 3, "D5.2", ["leave_requests"]),
            ("d3", 6, "D5.3", ["leave_balances", "entitlement only"]),
            ("d4", 0, "D5.4", ["holidays"]),
            ("d5", 4, "D5.5", ["notifications"])],
    externals=[("att", 6, "entity", ["Attendance", "leave-aware daily status"]),
               ("mob", 7, "entity", ["Mobile API", "the same calculators"])],
    flows=[
        ("emp", "p2", "type, dates, half-day flag", {}),
        ("appr", "p6", "decision + note", {}),
        ("emp", "p8", None, {}),
        ("d4", "p1", None, {"sides": ("w", "e")}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("d1", "p3", "requires approval?", {"sides": ("w", "e")}),
        ("d3", "p3", "entitlement", {"sides": ("w", "e")}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("p4", "d2", None, {}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "d5", None, {}),
        ("p6", "d2", None, {"both": True}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "d3", None, {"both": True}),
        ("p8", "d2", None, {"both": True}),
        ("p7", "att", None, {"dashed": True}),
        ("p4", "mob", "identical result", {"dashed": True}),
    ],
    footer="Only the entitlement is stored. Everything else is aggregated from the requests, so a balance can never disagree with the requests behind it.",
)

M_PERFORMANCE = dict(
    entities=[("hr", 0, ["HR Manager"]),
              ("head", 5, ["Department Head", "the rater"]),
              ("emp", 7, ["Employee", "acknowledges the result"])],
    processes=[
        ("p1", "6.1", ["Define a rating scale", "numeric with a step, 0-100 percentage,", "or ordered named levels with anchors"]),
        ("p2", "6.2", ["Build an appraisal framework", "weighted sections, criteria, eligibility,", "and the tenant's own rating model"]),
        ("p3", "6.3", ["Resolve the framework", "TemplateResolver takes the narrowest", "match: position > department > type > all"]),
        ("p4", "6.4", ["Open appraisals for a cycle", "EvaluationOpener is idempotent and", "reports who was opened and who skipped"]),
        ("p5", "6.5", ["Snapshot the framework", "name, sections, bands and each line's", "full scale are frozen onto the appraisal"]),
        ("p6", "6.6", ["Rate the criteria", "each value validated against that line's", "OWN snapshot scale on the way in"]),
        ("p7", "6.7", ["Score at two levels", "PerformanceScorer -> overall percent,", "result band, 1-5 projection (Figure 3.26)"]),
        ("p8", "6.8", ["Calibrate the cycle", "band spread and per-department deviation", "from the cycle average"]),
    ],
    stores=[("d1", 0, "D6.1", ["rating_scales"]),
            ("d2", 1, "D6.2", ["review_templates", "review_template_items"]),
            ("d3", 2, "D6.3", ["kpi_criteria"]),
            ("d4", 4, "D6.4", ["performance_evaluations", "with snapshot columns"]),
            ("d5", 6, "D6.5", ["performance_scores", "with section snapshot"])],
    externals=[("gem", 3, "entity", ["Gemini API", "coaching insight"]),
               ("ml", 6.4, "entity", ["ML forecast", "consumes the 1-5 overall"])],
    flows=[
        ("hr", "p1", None, {}),
        ("head", "p6", "ratings + remarks", {}),
        ("emp", "p6", "acknowledge", {}),
        ("hr", "p8", None, {}),
        ("p1", "d1", None, {"both": True}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("p2", "d2", None, {"both": True}),
        ("p2", "d3", None, {"both": True}),
        ("d2", "p3", "narrowest match", {"sides": ("w", "e")}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("p4", "d4", None, {}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "d4", "frozen framework", {}),
        ("p6", "d5", None, {}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "d4", "overall_percent, result_band", {}),
        ("p8", "d4", None, {"both": True}),
        ("p7", "gem", "digest text only, no documents", {"dashed": True, "sides": ("e", "w")}),
        ("p7", "ml", None, {"dashed": True}),
    ],
    footer="Retuning a framework, retiring a criterion or editing a scale changes the NEXT appraisal and never a past one.",
)

M_TRAINING = dict(
    entities=[("hr", 1, ["HR / L&D Officer"]),
              ("emp", 4, ["Employee"])],
    processes=[
        ("p1", "7.1", ["Create a programme", "provider, optional date window,", "seat capacity, description"]),
        ("p2", "7.2", ["Derive programme status", "completed once the end date passes,", "ongoing once the start date arrives"]),
        ("p3", "7.3", ["Enrol, individually or in bulk", "enrolling into a full programme is", "blocked, not silently queued"]),
        ("p4", "7.4", ["Record completion and score", "the completion date is stamped", "automatically and cleared on reversal"]),
        ("p5", "7.5", ["Compute seats taken", "non-dropped enrolments only"]),
        ("p6", "7.6", ["Generate an effectiveness reading", "what is working, concerns, recommendations,", "and who to follow up with"]),
        ("p7", "7.7", ["Emit the growth signal", "completions in the last 12 months feed", "the awards board and the ML features"]),
    ],
    stores=[("d1", 1, "D7.1", ["training_programs", "with ai_insights"]),
            ("d2", 3, "D7.2", ["training_enrollments"]),
            ("d3", 5, "D7.3", ["employees"])],
    externals=[("gem", 4, "entity", ["Gemini API"]),
               ("aw", 6, "entity", ["Awards / Analytics"])],
    flows=[
        ("hr", "p1", None, {}),
        ("hr", "p4", "grade", {}),
        ("emp", "p3", "request a seat", {}),
        ("p1", "d1", None, {"both": True}),
        ("p2", "d1", None, {"both": True}),
        ("p3", "d2", None, {"both": True}),
        ("p4", "d2", None, {"both": True}),
        ("p5", "d2", None, {"both": True}),
        ("p5", "p3", "capacity check", {"sides": ("w", "w"), "via": 276}),
        ("p6", "gem", "roster digest", {"dashed": True, "both": True}),
        ("p6", "d1", "persisted insight", {"sides": ("w", "e")}),
        ("d3", "p7", None, {"sides": ("w", "e")}),
        ("p7", "aw", None, {"dashed": True}),
    ],
    footer="Programme status is never stored - it is read from the date window, so it cannot drift when a date changes.",
)

M_AWARDS = dict(
    entities=[("hr", 1, ["HR Manager"]),
              ("emp", 6, ["Employee", "recipient and viewer"])],
    processes=[
        ("p1", "8.1", ["Maintain award types", "name, description, accent colour,", "active flag"]),
        ("p2", "8.2", ["Classify the focus profile", "keyword match over name + description:", "performance / attendance / tenure / growth"]),
        ("p3", "8.3", ["Gather the six signals", "performance, ML forecast, attendance,", "training, tenure, recognition gap"]),
        ("p4", "8.4", ["Score and rank nominees", "weighted per profile, normalised over the", "signals that can be assessed (Figure 3.27)"]),
        ("p5", "8.5", ["Apply the fairness guard", "a same-type win inside six months zeroes", "the gap signal and flags the nominee"]),
        ("p6", "8.6", ["Draft the citation", "grounded in that nominee's own signal", "breakdown; never persisted on its own"]),
        ("p7", "8.7", ["Grant the recognition", "the granter edits the draft before it", "is saved"]),
        ("p8", "8.8", ["Publish the feed", "chronological, filterable, with KPI cards"]),
    ],
    stores=[("d1", 0, "D8.1", ["award_types"]),
            ("d2", 4, "D8.2", ["employee_awards"]),
            ("d3", 2, "D8.3", ["performance_evaluations", "performance_forecasts"]),
            ("d4", 3, "D8.4", ["attendance_records"]),
            ("d5", 5, "D8.5", ["training_enrollments", "employees (tenure)"])],
    externals=[("gem", 5, "entity", ["Gemini API"]),
               ("note", 1.6, "note", ["ARITHMETIC FIRST", "The entire ranking is", "deterministic. The LLM only", "writes the words, after the", "decision has been made."])],
    flows=[
        ("hr", "p1", None, {}),
        ("hr", "p7", "approved citation", {}),
        ("p1", "d1", None, {"both": True}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("d3", "p3", None, {"sides": ("w", "e")}),
        ("d4", "p3", None, {"sides": ("w", "e")}),
        ("d5", "p3", None, {"sides": ("w", "e")}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("d2", "p4", "months since the last win", {"sides": ("w", "e")}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "p6", None, {"sides": ("s", "n")}),
        ("p6", "gem", None, {"dashed": True, "both": True}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "d2", None, {}),
        ("p8", "d2", None, {"both": True}),
        ("p8", "emp", "recognition feed", {"sides": ("w", "e")}),
    ],
    footer="A signal with nothing to assess drops out and the rest renormalise, so the board ranks sensibly even before every module is populated.",
)

M_EVENTS = dict(
    entities=[("hr", 1, ["HR / Organiser"]),
              ("emp", 4, ["Employee / Attendee"])],
    processes=[
        ("p1", "9.1", ["Create the event", "title, time window, venue, type,", "optional capacity"]),
        ("p2", "9.2", ["Derive the status", "upcoming / ongoing / past, from the", "time window - never stored"]),
        ("p3", "9.3", ["Invite attendees", "only linked, active accounts are notified;", "a delivery hiccup never blocks the invite"]),
        ("p4", "9.4", ["Record the response", "invited -> accepted / declined / tentative"]),
        ("p5", "9.5", ["Count who is going", "accepted + tentative"]),
        ("p6", "9.6", ["Remind everyone still pending", "one-click chase"]),
        ("p7", "9.7", ["Export", "roster, CSV, and an iCalendar (.ics) file;", "an event with attendees can only be archived"]),
    ],
    stores=[("d1", 1, "D9.1", ["events"]),
            ("d2", 3, "D9.2", ["event_attendees"]),
            ("d3", 5, "D9.3", ["notifications"])],
    externals=[("cal", 6, "entity", ["Calendar client", "iCalendar consumer"])],
    flows=[
        ("hr", "p1", None, {}),
        ("hr", "p6", None, {}),
        ("emp", "p4", "RSVP", {}),
        ("p1", "d1", None, {"both": True}),
        ("p2", "d1", None, {"both": True}),
        ("p3", "d2", None, {}),
        ("p3", "d3", "in-app notice", {}),
        ("p4", "d2", None, {"both": True}),
        ("p5", "d2", None, {"both": True}),
        ("p6", "d2", None, {"both": True}),
        ("p6", "d3", None, {}),
        ("p7", "d2", None, {"sides": ("w", "e")}),
        ("p7", "cal", None, {}),
    ],
)

M_OFFBOARDING = dict(
    entities=[("hr", 1, ["HR Manager"]),
              ("dept", 4, ["Clearing Departments", "IT, Finance, HR, and the", "employee's own department"])],
    processes=[
        ("p1", "10.1", ["Maintain clearance templates", "configured under Company Setup"]),
        ("p2", "10.2", ["Open the exit case", "type, notice date, last working day,", "reason"]),
        ("p3", "10.3", ["Seed and route the items", "OffboardingProvisioner routes each item", "to the department that must sign it off"]),
        ("p4", "10.4", ["Sign off or flag an item", "item-level and bulk clearing"]),
        ("p5", "10.5", ["Derive the clearance status", "pending / in_progress / cleared;", "a flagged item keeps a case off 'cleared'"]),
        ("p6", "10.6", ["Advance the lifecycle", "initiated -> clearance -> completed,", "or cancelled"]),
        ("p7", "10.7", ["Separate the employee", "employment_status is transitioned to", "match the exit type - never set by hand"]),
        ("p8", "10.8", ["Export clearance", "CSV, grouped per department"]),
    ],
    stores=[("d1", 0, "D10.1", ["offboarding_programs", "offboarding_program_items"]),
            ("d2", 1, "D10.2", ["offboarding_cases"]),
            ("d3", 3, "D10.3", ["clearance_items"]),
            ("d4", 6, "D10.4", ["employees"]),
            ("d5", 7, "D10.5", ["activity_logs"])],
    externals=[("note", 4, "note", ["THE MIRROR OF ONBOARDING", "Completing an exit case is what", "changes somebody's employment", "status. Reopening or cancelling", "returns them to active."])],
    flows=[
        ("hr", "p1", None, {}),
        ("hr", "p2", None, {}),
        ("dept", "p4", "signed off or flagged", {}),
        ("p1", "d1", None, {"both": True}),
        ("p2", "d2", None, {"both": True}),
        ("d1", "p3", "template items", {"sides": ("w", "e")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("p3", "d3", None, {}),
        ("p4", "d3", None, {"both": True}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "d2", "derived, never stored", {}),
        ("p5", "p6", None, {"sides": ("s", "n")}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "d4", "resigned / terminated / retired", {}),
        ("p7", "d5", None, {}),
        ("p8", "d3", None, {"sides": ("w", "e")}),
    ],
)

M_ANALYTICS = dict(
    entities=[("hr", 1, ["HR Manager", "analytics.*.manage"]),
              ("head", 6, ["Department Head", "analytics.*.view only"])],
    processes=[
        ("p1", "11.1", ["Gather the cohort", "every ACTIVE employee, with 90-day overtime", "and 12-month training as SQL aggregates"]),
        ("p2", "11.2", ["Map to a feature vector", "FeatureMapper; an ungroundable input is", "OMITTED, never sent as a zero"]),
        ("p3", "11.3", ["Check service health", "GET /health precedes any prediction call,", "so the page never hangs on a dead socket"]),
        ("p4", "11.4", ["Score the batch", "POST /predict/{model} with", "{ ref, features } per instance"]),
        ("p5", "11.5", ["Derive tier or band", "probability < 0.33 low, < 0.66 medium,", "else high; 80 / 60 for the forecast"]),
        ("p6", "11.6", ["Derive confidence", "the share of key features grounded in real", "data rather than imputed by the pipeline"]),
        ("p7", "11.7", ["Persist the run", "one header + one score row per employee,", "each with its exact feature snapshot"]),
        ("p8", "11.8", ["Render, or degrade", "the ranked list - or the last stored run", "with an offline notice"]),
    ],
    stores=[("d1", 0, "D11.1", ["employees, departments"]),
            ("d2", 1, "D11.2", ["performance_evaluations", "employee_promotions"]),
            ("d3", 2, "D11.3", ["attendance_records", "training_enrollments"]),
            ("d4", 6, "D11.4", ["attrition / promotion /", "forecast _runs"]),
            ("d5", 7, "D11.5", ["*_scores", "with the features JSON"])],
    externals=[("svc", 3.4, "entity", ["ML Inference Service", "RandomForest,", "HistGradientBoosting,", "LogisticRegression"]),
               ("rep", 6.6, "entity", ["Reports module", "ML chips read stored runs"])],
    flows=[
        ("hr", "p1", "run assessment", {}),
        ("head", "p8", "view only", {}),
        ("d1", "p1", None, {"sides": ("w", "e")}),
        ("d2", "p1", None, {"sides": ("w", "e")}),
        ("d3", "p1", None, {"sides": ("w", "e")}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("p3", "svc", "health", {"dashed": True, "both": True}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("p4", "svc", "batch / results", {"both": True}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "p6", None, {"sides": ("s", "n")}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "d4", None, {}),
        ("p7", "d5", None, {}),
        ("p7", "p8", None, {"sides": ("s", "n")}),
        ("d4", "p8", "last stored run", {"sides": ("w", "e")}),
        ("p8", "rep", None, {"dashed": True}),
    ],
    footer="No prediction is ever written onto the employee record. Each assessment is a run, so an old score stays auditable against the inputs it was given.",
)

M_ASSISTANT = dict(
    entities=[("user", 1, ["Any signed-in user", "HR, Head or Staff"]),
              ("gem", 4, ["Google Gemini", "gemini-2.5-flash,", "temperature 0.2"])],
    processes=[
        ("p1", "12.1", ["Throttle the turn", "12 requests per minute and 240 per day,", "per user"]),
        ("p2", "12.2", ["Filter modules and build tools", "each module's tools(user) is permission-", "scoped: being offered is not being allowed"]),
        ("p3", "12.3", ["Call the model", "conversation + attached files +", "the system instruction"]),
        ("p4", "12.4", ["Dispatch the function call", "to the owning module, which RE-CHECKS", "the permission before doing anything"]),
        ("p5", "12.5", ["Execute the canonical class", "the same class the screen uses, with", "tenancy, validation, audit and notification"]),
        ("p6", "12.6", ["Sanitise the tool result", "strip control characters, cap free text,", "cap lists at 25 rows, no contact details"]),
        ("p7", "12.7", ["Compose the reply", "all calls succeeded -> answer locally;", "only errors go back to the model"]),
        ("p8", "12.8", ["Persist the conversation", "reading a named person's profile is logged", "as a view; searches and counts are not"]),
    ],
    stores=[("d1", 1, "D12.1", ["permissions, role_user"]),
            ("d2", 4, "D12.2", ["every module store", "D1.x - D11.x"]),
            ("d3", 7, "D12.3", ["assistant_conversations", "assistant_messages"]),
            ("d4", 6, "D12.4", ["activity_logs"])],
    externals=[("note", 1.6, "note", ["SEPARATION OF POWERS", "The model only DECIDES which", "action to take and with what", "arguments. The module ENFORCES:", "permission, validation, tenancy,", "audit, notification."]),
               ("note2", 5.4, "note", ["GUARDRAILS", "Tool results and uploaded", "documents are data, never", "instructions. Reject, hire and", "archive are never taken on a", "hint."])],
    flows=[
        ("user", "p1", "message + files", {}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("d1", "p2", "this user's abilities", {"sides": ("w", "e")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("gem", "p3", "text and/or function calls", {"sides": ("e", "w")}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "d2", None, {"both": True}),
        ("p5", "p6", None, {"sides": ("s", "n")}),
        ("p6", "p3", "at most six round-trips", {"sides": ("w", "w"), "via": 268, "dashed": True}),
        ("p6", "p7", None, {"sides": ("s", "n")}),
        ("p7", "p8", None, {"sides": ("s", "n")}),
        ("p8", "d3", None, {"both": True}),
        ("p8", "d4", None, {}),
        ("p7", "user", "reply + action cards", {"sides": ("w", "e")}),
    ],
    footer="Employee retrieval here is not an embedding index: it is live, tenant-scoped, permission-checked SQL exposed as functions, so results are always current and correctly scoped.",
)

M_REPORTS = dict(
    entities=[("hr", 1, ["HR Manager"]),
              ("exec", 5, ["Executive / Auditor"])],
    processes=[
        ("p1", "13.1", ["Resolve the report", "the registry returns only the reports the", "viewer is permitted to see"]),
        ("p2", "13.2", ["Run the report", "one class owns its filters, columns, rows,", "charts, totals and export"]),
        ("p3", "13.3", ["Derive the charts", "computed over the WHOLE result set,", "not the page currently on screen"]),
        ("p4", "13.4", ["Attach the ML signals", "read the latest STORED runs, so a report", "never blocks on a live network call"]),
        ("p5", "13.5", ["Generate the AI insight", "re-resolves, re-authorises and re-runs the", "report server-side; browser numbers are ignored"]),
        ("p6", "13.6", ["Export CSV / XLSX", "exactly the rows the auditor saw,", "with optional PII redaction"]),
        ("p7", "13.7", ["Compose the dashboard", "reuses each module's own statistics class,", "so headline numbers have one source"]),
        ("p8", "13.8", ["Build the attention queue", "only rows the viewer can act on and that", "need action; empty rows are dropped"]),
    ],
    stores=[("d1", 1, "D13.1", ["every module store", "read-only"]),
            ("d2", 3, "D13.2", ["activity_logs", "the audit trail report"]),
            ("d3", 4, "D13.3", ["*_runs, *_scores"])],
    externals=[("gem", 4.6, "entity", ["Gemini API"]),
               ("file", 6, "entity", ["Downloaded file", "CSV / XLSX"])],
    flows=[
        ("hr", "p1", None, {}),
        ("exec", "p1", None, {}),
        ("p1", "p2", None, {"sides": ("s", "n")}),
        ("d1", "p2", "tenant-scoped rows", {"sides": ("w", "e")}),
        ("d2", "p2", None, {"sides": ("w", "e")}),
        ("p2", "p3", None, {"sides": ("s", "n")}),
        ("p3", "p4", None, {"sides": ("s", "n")}),
        ("d3", "p4", "stored run summaries", {"sides": ("w", "e")}),
        ("p4", "p5", None, {"sides": ("s", "n")}),
        ("p5", "gem", "totals, chart aggregates, a row sample", {"dashed": True, "both": True}),
        ("p2", "p6", None, {"sides": ("w", "w"), "via": 274}),
        ("p6", "file", None, {}),
        ("p7", "d1", None, {"both": True}),
        ("p8", "d1", None, {"both": True}),
        ("p7", "p8", None, {"sides": ("s", "n")}),
    ],
    footer="One source feeds the on-screen table, the totals, the charts, the export and the AI digest - so they cannot disagree with one another.",
)

MODULES = [
    ("3.6", "Recruitment", "Applicant tracking, the deterministic fit score, and the hire bridge into the workforce", M_RECRUITMENT),
    ("3.7", "Onboarding", "Template matching, checklist instantiation, ownership routing and chasing", M_ONBOARDING),
    ("3.8", "Employee 201 File", "The record every other module points at, and the two doors into a workspace", M_EMPLOYEE),
    ("3.9", "Attendance", "Raw punch events in, one derived daily record out", M_ATTENDANCE),
    ("3.10", "Leave", "Server-computed working days and a balance that is derived, not stored", M_LEAVE),
    ("3.11", "Performance", "Tenant-defined appraisal frameworks and two-level weighted scoring", M_PERFORMANCE),
    ("3.12", "Training", "Programmes, seats, completions, and the growth signal they emit", M_TRAINING),
    ("3.13", "Awards", "Six weighted signals, a focus profile per award type, and a repeat-winner guard", M_AWARDS),
    ("3.14", "Events", "Date-derived status, invitation, response tracking and calendar export", M_EVENTS),
    ("3.15", "Offboarding", "Department-routed clearance and the separation bridge", M_OFFBOARDING),
    ("3.16", "Predictive Analytics", "Cohort, to feature vector, to a stored and auditable run", M_ANALYTICS),
    ("3.17", "LLM Assistant", "A bounded function-calling loop in which the model decides and the module enforces", M_ASSISTANT),
    ("3.18", "Reports and Dashboard", "One report class as the single source of table, totals, charts, export and insight", M_REPORTS),
]

FIGURES = [fig_context, fig_dfd1] + [
    build_module(num, name, sub, **spec) for num, name, sub, spec in MODULES
]
