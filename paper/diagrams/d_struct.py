"""Figures 3.31-3.36 - use case, state machines, sequence, ERD, security, navigation."""

from svgkit import (DASH, DASH_FINE, FILL_LIGHT, FILL_MID, INK, MONO, MUTED, SW,
                    SW_THIN, T_SMALL, T_TINY, WHITE, Fig, arrowhead, circle,
                    legend, line, path, rect, text, text_block)


def actor(f, x, y, label, sub=None):
    """A stick figure with a caption, centred on x."""
    s = []
    s.append(circle(x, y, 11, WHITE, INK, SW))
    s.append(line(x, y + 11, x, y + 40, INK, SW))
    s.append(line(x - 16, y + 22, x + 16, y + 22, INK, SW))
    s.append(line(x, y + 40, x - 14, y + 62, INK, SW))
    s.append(line(x, y + 40, x + 14, y + 62, INK, SW))
    s.append(text(x, y + 80, label, 10.5, "middle", "bold"))
    if sub:
        s.append(text(x, y + 93, sub, 9, "middle", "normal", MUTED))
    f.add("".join(s))


# -- Figure 3.31 --------------------------------------------------------------

def fig_use_case():
    f = Fig(1240, 940, "Figure 3.31  Use Case Diagram of SYNAPSE",
            "Three built-in roles, the public applicant, and two system actors; the boundary is the tenant")

    f.back(rect(300, 80, 640, 800, 8, WHITE, INK, SW))
    f.back(text(620, 104, "SYNAPSE  (within one organisation)", 12, "middle", "bold", MUTED))

    actor(f, 130, 150, "HR Manager", "owner - all permissions")
    actor(f, 130, 400, "Department Head", "supervisor")
    actor(f, 130, 640, "Employee (Staff)", "web + mobile")
    actor(f, 130, 810, "Job Applicant", "unauthenticated")

    actor(f, 1110, 250, "ML Inference", "system actor")
    actor(f, 1110, 520, "Gemini API", "system actor")
    actor(f, 1110, 760, "Mail / Push", "system actor")

    groups = [
        ("Talent acquisition", 124, [
            "Publish job posting", "Submit application (public)",
            "Review ranked candidates", "Schedule and score interview",
            "Hire applicant (bridge)"]),
        ("Workforce operations", 268, [
            "Maintain 201 file", "Invite employee to the app",
            "Run onboarding checklist", "Complete offboarding clearance"]),
        ("Time and attendance", 412, [
            "Clock in / out (GPS + selfie)", "Correct and approve DTR",
            "File leave request", "Approve / reject leave"]),
        ("Development and recognition", 556, [
            "Conduct appraisal", "Calibrate a review cycle",
            "Enrol in training", "Nominate and grant an award"]),
        ("Intelligence", 700, [
            "Run predictive assessment", "Read AI insight panel",
            "Converse with the HR assistant", "Generate report and export"]),
    ]
    y = 0
    ell = {}
    for title, gy, cases in groups:
        f.back(text(322, gy + 4, title.upper(), 9.5, "start", "bold", MUTED))
        for i, c in enumerate(cases):
            cx = 460 if i % 2 == 0 else 790
            cy = gy + 30 + (i // 2) * 46
            w, h = 300, 38
            f.add(rect(cx - w / 2, cy - h / 2, w, h, h / 2, WHITE, INK, SW_THIN))
            f.add(text(cx, cy + 4, c, 9.8, "middle"))
            ell[c] = (cx - w / 2, cy, w)

    def link(ax, ay, case, dashed=False, label=None):
        x, cy, w = ell[case]
        tx = x if ax < x else x + w
        f.add(path("M %.1f %.1f L %.1f %.1f" % (ax, ay, tx, cy), "none", INK,
                   SW_THIN, DASH_FINE if dashed else None))

    for c in ["Publish job posting", "Review ranked candidates", "Hire applicant (bridge)",
              "Maintain 201 file", "Invite employee to the app", "Run onboarding checklist",
              "Complete offboarding clearance", "Correct and approve DTR",
              "Approve / reject leave", "Calibrate a review cycle",
              "Nominate and grant an award", "Run predictive assessment",
              "Generate report and export", "Enrol in training"]:
        link(160, 220, c)
    for c in ["Schedule and score interview", "Approve / reject leave", "Conduct appraisal",
              "Read AI insight panel", "Correct and approve DTR"]:
        link(160, 470, c)
    for c in ["Clock in / out (GPS + selfie)", "File leave request",
              "Converse with the HR assistant"]:
        link(160, 710, c)
    link(160, 880, "Submit application (public)")

    for c in ["Run predictive assessment"]:
        link(1080, 320, c)
    for c in ["Read AI insight panel", "Converse with the HR assistant",
              "Review ranked candidates"]:
        link(1080, 590, c)
    for c in ["Approve / reject leave", "Invite employee to the app"]:
        link(1080, 830, c)

    f.node("note", 300, 894, 640, 34, "note",
           [("Every use case is additionally gated by one of the 73 named permissions; the three roles are default bundles, and a tenant may compose its own.", 8.8, "normal", MUTED)],
           FILL_LIGHT)
    return "fig-3-31-use-case-diagram.svg", f


# -- Figure 3.32 --------------------------------------------------------------

def fig_states():
    f = Fig(1240, 900, "Figure 3.32  Lifecycle (State) Diagrams of the Principal Records",
            "Where status is STORED it is drawn as a state; where it is DERIVED it is boxed and labelled as such")

    def machine(x, y, title, states, transitions, note=None, derived=False, w=560):
        f.back(rect(x, y, w, 168, 6, WHITE, INK, SW_THIN, DASH))
        f.front(text(x + 14, y + 20, title, 10.5, "start", "bold"))
        if derived:
            f.front(text(x + w - 14, y + 20, "DERIVED - never stored", 8.8, "end", "bold", MUTED))
        pos = {}
        for i, (k, label) in enumerate(states):
            col, row = i % 4, i // 4
            sx = x + 22 + col * 134
            sy = y + 42 + row * 62
            pos[k] = (sx, sy, 118, 40)
            f.add(rect(sx, sy, 118, 40, 20, FILL_LIGHT if row == 0 and col == 0 else WHITE,
                       INK, SW_THIN))
            f.add(text(sx + 59, sy + 24, label, 9.6, "middle"))
        for a, b, lbl in transitions:
            ax, ay, aw, ah = pos[a]
            bx, by, bw, bh = pos[b]
            if abs(ay - by) < 2 and bx > ax:
                f.add(path("M %.1f %.1f L %.1f %.1f" % (ax + aw, ay + ah / 2, bx, by + bh / 2),
                           "none", INK, SW_THIN))
                f.add(arrowhead(bx, by + bh / 2, 1, 0, 7))
                if lbl:
                    f.front(text((ax + aw + bx) / 2, ay + ah / 2 - 6, lbl, 8.2, "middle",
                                 "normal", MUTED))
            else:
                midy = max(ay, by) + ah + 14
                f.add(path("M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f"
                           % (ax + aw / 2, ay + ah, ax + aw / 2, midy, bx + bw / 2, midy,
                              bx + bw / 2, by + bh), "none", INK, SW_THIN))
                f.add(arrowhead(bx + bw / 2, by + bh, 0, -1, 7))
                if lbl:
                    f.front(text((ax + bx + aw) / 2, midy - 4, lbl, 8.2, "middle",
                                 "normal", MUTED))
        if note:
            f.front(text(x + 14, y + 158, note, 8.6, "start", "normal", MUTED))

    machine(40, 76, "Job application (recruitment)",
            [("a", "applied"), ("b", "screening"), ("c", "interview"), ("d", "offer"),
             ("e", "hired"), ("r", "rejected")],
            [("a", "b", ""), ("b", "c", ""), ("c", "d", ""), ("d", "e", "hire bridge"),
             ("b", "r", "reject"), ("r", "b", "reinstate")],
            "A rejected candidate may be reinstated; a hired one can never be un-hired.")

    machine(640, 76, "Job posting",
            [("d", "draft"), ("o", "open"), ("c", "closed"), ("f", "filled")],
            [("d", "o", "publish"), ("o", "c", "close / auto-close"),
             ("o", "f", "openings met")],
            "Once open a posting must carry a closing date; a daily command closes past-due postings.")

    machine(40, 264, "Onboarding case",
            [("p", "pending"), ("i", "in_progress"), ("c", "completed"), ("x", "cancelled")],
            [("p", "i", "first task activity"), ("i", "c", "deliberate"), ("i", "x", "")],
            "The move to in_progress is automatic; completion is always a deliberate act.")

    machine(640, 264, "Offboarding case + clearance",
            [("i", "initiated"), ("c", "clearance"), ("k", "completed"), ("x", "cancelled")],
            [("i", "c", "first sign-off"), ("c", "k", "complete"), ("c", "x", "")],
            "clearance_status (pending / in_progress / cleared) is DERIVED; a flagged item blocks 'cleared'.",
            derived=False)

    machine(40, 452, "Performance appraisal",
            [("d", "draft"), ("s", "submitted"), ("g", "signed off"), ("a", "acknowledged")],
            [("d", "s", "all criteria rated"), ("s", "g", ""), ("g", "a", "employee")],
            "Submit is blocked until every criterion is rated; the result is recomputed on every save.")

    machine(640, 452, "Leave request",
            [("p", "pending"), ("a", "approved"), ("r", "rejected"), ("c", "cancelled")],
            [("p", "a", "approve"), ("p", "r", "reject"), ("p", "c", "withdraw"),
             ("a", "c", "withdraw")],
            "Types that do not require approval are auto-approved on submission.")

    machine(40, 640, "Daily attendance status  (derived)",
            [("pr", "present"), ("la", "late"), ("un", "undertime"), ("ic", "incomplete"),
             ("ab", "absent"), ("do", "day_off"), ("ol", "on_leave")],
            [], "Recomputed from the punch stream on every write; nothing here is set by hand.",
            derived=True)

    machine(640, 640, "Training programme / event status  (derived)",
            [("u", "upcoming"), ("o", "ongoing"), ("c", "completed / past")],
            [("u", "o", "start date arrives"), ("o", "c", "end date passes")],
            "Derived from the date window on read - a stored copy would drift the moment a date changed.",
            derived=True)

    f.node("k", 40, 828, 1160, 46, "note",
           [("Derive, do not store. If a value can be computed from other rows it usually is not saved: a training programme's status from its dates, an", 9, "normal", MUTED),
            ("onboarding case's progress from its tasks, a leave balance's 'used' from its approved requests, a clearance status from its items. Stored duplicates drift; derived ones cannot.", 9, "normal", MUTED)],
           FILL_LIGHT)
    return "fig-3-32-state-diagrams.svg", f


# -- Figure 3.33 --------------------------------------------------------------

def fig_sequence():
    f = Fig(1220, 820, "Figure 3.33  Sequence Diagram - Mobile Clock-In with GPS and Selfie",
            "The mobile client and the web screen traverse the identical canonical path")

    lanes = [
        ("Employee\n(handset)", 110), ("Expo client\napp/(tabs)/clock", 290),
        ("Sanctum +\nSetCurrentOrganization", 500), ("AttendanceClock\n(canonical writer)", 720),
        ("AttendanceCalculator", 930), ("PostgreSQL", 1120),
    ]
    for label, x in lanes:
        parts = label.split("\n")
        f.add(rect(x - 90, 78, 180, 46, 3, FILL_LIGHT, INK, SW_THIN))
        for i, p in enumerate(parts):
            f.add(text(x, 96 + i * 13, p, 9.6 if i == 0 else 8.8, "middle",
                       "bold" if i == 0 else "normal", INK if i == 0 else MUTED))
        f.add(line(x, 124, x, 748, INK, SW_THIN, DASH_FINE))

    msgs = [
        (110, 290, 168, "tap the primary button (label reflects TODAY'S SERVER STATE)", False),
        (290, 290, 206, "capture GPS fix + optional selfie; build multipart body", True),
        (290, 500, 244, "POST /api/attendance/punch   Authorization: Bearer <token>", False),
        (500, 500, 282, "resolve user; bind the token's organisation; validate membership", True),
        (500, 720, 320, "can:attendance.clock  ->  punch(type, source='mobile', gps, photo)", False),
        (720, 720, 358, "find or create today's attendance_record", True),
        (720, 720, 396, "validate the transition (no double clock-in, no out before in,", True),
        (720, 720, 414, "breaks only while on the clock)", True),
        (720, 1120, 452, "INSERT attendance_punches (+ store selfie on the private disk)", False),
        (720, 930, 490, "recompute(record, schedule, onApprovedLeave)", False),
        (930, 930, 528, "walk the punch stream; derive worked / break / late /", True),
        (930, 930, 546, "undertime / overtime minutes and the daily status", True),
        (930, 720, 584, "mutated record attributes", False),
        (720, 1120, 622, "UPDATE attendance_records", False),
        (720, 500, 660, "today's state + running worked-hours counter", False),
        (500, 290, 698, "200 OK  { status, next_action, worked_minutes }", False),
        (290, 110, 736, "button flips to the next legal action", False),
    ]
    for x1, x2, y, label, selfmsg in msgs:
        if selfmsg:
            f.add(path("M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f"
                       % (x1, y - 8, x1 + 34, y - 8, x1 + 34, y + 6, x1 + 4, y + 6),
                       "none", INK, SW_THIN))
            f.add(arrowhead(x1 + 4, y + 6, -1, 0, 7))
            f.add(text(x1 + 44, y + 2, label, 9, "start", "normal", MUTED))
        else:
            f.add(line(x1, y, x2, y, INK, SW_THIN))
            f.add(arrowhead(x2, y, 1 if x2 > x1 else -1, 0, 8))
            f.add(text((x1 + x2) / 2, y - 6, label, 9, "middle", "normal", INK))

    f.node("note", 60, 764, 1100, 40, "note",
           [("The button's state comes from the SERVER, not the client: the label is computed from today's record, so a lost connection or a reinstalled app can never desynchronise the day.", 9, "normal", MUTED),
            ("Both this path and the web screen call AttendanceClock, so a punch made on a phone computes identically to one made at a desktop.", 9, "normal", MUTED)],
           FILL_LIGHT)
    return "fig-3-33-sequence-mobile-clock-in.svg", f


# -- Figure 3.34 --------------------------------------------------------------

def fig_erd():
    f = Fig(1400, 880, "Figure 3.34  Entity Relationship Diagram of SYNAPSE (Structural Level)",
            "69 tables in twelve functional clusters; organizations anchors tenancy and employees is the hub of the HR domain")

    clusters = [
        ("IDENTITY & TENANCY", 40, 76, [
            "organizations", "users", "organization_user",
            "organization_join_requests", "employee_invitations", "passkeys",
            "personal_access_tokens", "sessions"]),
        ("ACCESS CONTROL & AUDIT", 40, 306, [
            "roles", "permissions", "role_user", "permission_role",
            "activity_logs", "notifications", "push_subscriptions"]),
        ("COMPANY SETUP", 40, 514, [
            "departments", "positions", "work_schedules", "holidays",
            "leave_types", "award_types"]),
        ("RECRUITMENT", 380, 76, [
            "job_postings", "applicants", "applicant_documents",
            "job_applications", "interviews"]),
        ("EMPLOYEE 201 FILE", 380, 246, [
            "employees", "employee_documents", "employee_certifications",
            "employee_promotions", "employee_awards"]),
        ("ONBOARDING / OFFBOARDING", 380, 416, [
            "onboarding_programs", "onboarding_program_tasks", "onboarding_cases",
            "onboarding_tasks", "offboarding_programs", "offboarding_program_items",
            "offboarding_cases", "clearance_items"]),
        ("TIME, LEAVE & EVENTS", 720, 76, [
            "attendance_punches", "attendance_records", "leave_requests",
            "leave_balances", "events", "event_attendees"]),
        ("PERFORMANCE", 720, 268, [
            "rating_scales", "review_templates", "review_template_items",
            "kpi_criteria", "evaluation_periods", "performance_evaluations",
            "performance_scores"]),
        ("TRAINING", 720, 482, [
            "training_programs", "training_enrollments"]),
        ("PREDICTIVE ANALYTICS", 1060, 76, [
            "attrition_risk_runs", "attrition_risk_scores",
            "promotion_readiness_runs", "promotion_readiness_scores",
            "performance_forecast_runs", "performance_forecasts"]),
        ("ASSISTANT", 1060, 268, [
            "assistant_conversations", "assistant_messages"]),
        ("FRAMEWORK / SYSTEM", 1060, 372, [
            "cache", "cache_locks", "jobs", "job_batches", "failed_jobs",
            "password_reset_tokens", "migrations"]),
    ]
    anchor = {}
    for title, x, y, tables in clusters:
        w = 300
        h = 36 + len(tables) * 22
        f.back(rect(x, y, w, h, 6, WHITE, INK, SW, DASH))
        f.back(text(x + 12, y + 20, title, 9.6, "start", "bold", MUTED))
        yy = y + 30
        for t in tables:
            hub = t in ("organizations", "employees", "users")
            f.add(rect(x + 12, yy, w - 24, 20, 2, FILL_LIGHT if hub else WHITE, INK, SW_THIN))
            f.add(text(x + 20, yy + 14, t, 9, "start", "bold" if hub else "normal",
                       INK, MONO))
            anchor[t] = (x + 12, yy, w - 24, 20)
            yy += 22

    def rel(a, b, card="1 : N", corridor=None, gutter=None):
        ax, ay, aw, ah = anchor[a]
        bx, by, bw, bh = anchor[b]
        y1, y2 = ay + ah / 2, by + bh / 2
        if abs(ax - bx) < 4:                       # same column: route down the left gutter
            gx = ax - 7
            f.add(path("M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f"
                       % (ax, y1, gx, y1, gx, y2, bx, y2), "none", INK, SW_THIN))
            f.add(arrowhead(bx, y2, 1, 0, 7))
            f.front(text(gx - 4, (y1 + y2) / 2, card, 8, "end", "normal", MUTED))
            return
        x1 = ax + aw if bx > ax else ax
        x2 = bx if bx > ax else bx + bw
        mx = gutter if gutter is not None else (x1 + x2) / 2
        if corridor is not None:
            f.add(path("M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f"
                       % (x1, y1, x1, corridor, mx, corridor, mx, y2, x2, y2),
                       "none", INK, SW_THIN))
            f.add(arrowhead(x2, y2, 1 if x2 > x1 else -1, 0, 7))
            f.front(text(mx - 6, corridor - 5, card, 8, "end", "normal", MUTED))
            return
        f.add(path("M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f"
                   % (x1, y1, mx, y1, mx, y2, x2, y2), "none", INK, SW_THIN))
        f.add(arrowhead(x2, y2, 1 if x2 > x1 else -1, 0, 7))
        f.front(text(mx, (y1 + y2) / 2 - 4, card, 8, "middle", "normal", MUTED))

    rel("organizations", "employees")
    rel("organizations", "roles")
    rel("users", "organization_user")
    rel("employees", "attendance_records")
    rel("employees", "performance_evaluations")
    rel("employees", "training_enrollments")
    rel("employees", "attrition_risk_scores", corridor=236, gutter=1040)
    rel("employees", "onboarding_cases")
    rel("job_applications", "employees", "1 : 1")
    rel("review_templates", "performance_evaluations")

    f.node("nb", 1060, 574, 300, 196, "note",
           [("HOW TO READ THIS DIAGRAM", 9.5, "bold", INK),
            ("* organizations is referenced by nearly", 9, "normal", MUTED),
            ("  every domain table through an", 9, "normal", MUTED),
            ("  organization_id column, enforced by a", 9, "normal", MUTED),
            ("  global query scope, not by hand.", 9, "normal", MUTED),
            ("* employees is the hub: the 201-file", 9, "normal", MUTED),
            ("  satellites, every operational module", 9, "normal", MUTED),
            ("  and all three prediction score tables", 9, "normal", MUTED),
            ("  point at it.", 9, "normal", MUTED),
            ("* Recruitment flows postings to", 9, "normal", MUTED),
            ("  applications to interviews and, on", 9, "normal", MUTED),
            ("  hire, into employees.", 9, "normal", MUTED),
            ("* Template-driven modules always pair", 9, "normal", MUTED),
            ("  a programme table with its", 9, "normal", MUTED),
            ("  instantiated per-employee records.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("nb2", 40, 700, 640, 110, "note",
           [("PREDICTION STORAGE IS A RUN, NOT A COLUMN", 9.5, "bold", INK),
            ("No score is ever written onto the employee row. Each assessment is a run header plus one score", 9, "normal", MUTED),
            ("row per employee carrying the probability, the score, the tier or band, the confidence or factors,", 9, "normal", MUTED),
            ("and a JSON snapshot of the exact features that were sent. Past runs stay selectable, so an old", 9, "normal", MUTED),
            ("prediction can be audited on what the model was TOLD, not only on what it said.", 9, "normal", MUTED)],
           FILL_LIGHT)

    f.node("nb3", 720, 596, 300, 130, "note",
           [("SNAPSHOTS ARE DELIBERATE REDUNDANCY", 9.5, "bold", INK),
            ("performance_evaluations and", 9, "normal", MUTED),
            ("performance_scores each carry copies of", 9, "normal", MUTED),
            ("the framework, section and scale that", 9, "normal", MUTED),
            ("applied when they were written. That is", 9, "normal", MUTED),
            ("what makes a historical appraisal", 9, "normal", MUTED),
            ("immutable against later configuration", 9, "normal", MUTED),
            ("changes.", 9, "normal", MUTED)], FILL_LIGHT)

    f.front(text(700, 852,
                 "The field-level structure of every table - data type, length or precision, key type and nullability, as designed for PostgreSQL - is given in the data dictionary, Section 3.8.2.",
                 T_SMALL, "middle", fill=MUTED))
    return "fig-3-34-erd.svg", f


# -- Figure 3.35 --------------------------------------------------------------

def fig_security():
    f = Fig(1180, 860, "Figure 3.35  Security and Privacy Enforcement Layers",
            "Seven concentric controls; a request must satisfy every one of them, in this order")

    layers = [
        ("TRANSPORT & BROWSER", "TLS; SecurityHeaders sets a nonce-based CSP, X-Frame-Options and X-Content-Type-Options, and strips X-Powered-By on every response including error pages"),
        ("RATE LIMITING & BOT CONTROL", "login throttled per e-mail + IP; registration 6/min; workspace and invitation lookups 10/min; assistant 12/min and 240/day per user; public application form carries a honeypot field"),
        ("IDENTITY", "Fortify e-mail + password with verification and reset; optional TOTP two-factor and WebAuthn passkeys; Sanctum bearer tokens for mobile, each minted bound to ONE organisation"),
        ("TENANCY (ROW LEVEL)", "SetCurrentOrganization binds the active org, always validated against organization_user; OrganizationScope filters every read and stamps every write BELOW the query layer - so isolation holds regardless of permission"),
        ("AUTHORIZATION", "73 named permissions in 16 groups, defined in code and synced to the database, never the reverse; can:<ability> on every route; bulk endpoints re-authorise each item; assistant tools are permission-scoped before they are offered AND re-checked on execution"),
        ("DATA PROTECTION AT REST", "TIN, SSS, PhilHealth and Pag-IBIG encrypted (and therefore no longer SQL-searchable); documents stored on a private disk, served only through a route, every access logged; government IDs and bank details render masked with an explicit reveal"),
        ("DISCLOSURE & AI BOUNDARY", "EmployeeDisclosure withholds nine fields from every assistant read at every permission level; government-ID documents are never sent to the model; PROTECTED_FEATURES are never surfaced as decision factors; retrieved text is data, never instruction"),
    ]
    y = 78
    for i, (title, body) in enumerate(layers):
        h = 92
        inset = i * 8
        f.add(rect(40 + inset, y, 1100 - inset * 2, h, 5,
                   FILL_LIGHT if i % 2 == 0 else WHITE, INK, SW))
        f.add(text(58 + inset, y + 24, "%d.  %s" % (i + 1, title), 11, "start", "bold"))
        words = body.split()
        lines, cur = [], ""
        limit = 118 - i * 2
        for w in words:
            if len(cur) + len(w) + 1 > limit:
                lines.append(cur)
                cur = w
            else:
                cur = (cur + " " + w).strip()
        lines.append(cur)
        for j, ln in enumerate(lines[:4]):
            f.add(text(58 + inset, y + 44 + j * 14, ln, 9.2, "start", "normal", MUTED))
        y += h + 10

    f.front(text(590, 840,
                 "All live employee data is handled under Republic Act 10173 (Data Privacy Act of 2012): access control, tenant isolation, append-only audit logging and data minimisation.",
                 T_SMALL, "middle", fill=MUTED))
    return "fig-3-35-security-layers.svg", f


# -- Figure 3.36 --------------------------------------------------------------

def fig_navigation():
    f = Fig(1220, 880, "Figure 3.36  Information Architecture and Navigation Map",
            "Six sidebar groups on the web, five tabs on the phone; every item is hidden unless the viewer holds its permission")

    groups = [
        ("TALENT ACQUISITION", 40, 96, [
            "/recruitment  postings + pipeline", "/recruitment/desk  my work",
            "/recruitment/candidates", "/recruitment/interviews",
            "/recruitment/analytics", "/onboarding  cases",
            "/onboarding/my-tasks"]),
        ("WORKFORCE", 340, 96, [
            "/employees  directory + 201", "/employees/access  invitations",
            "/attendance  DTR board", "/attendance/me  self-service",
            "/leave  approvals inbox", "/leave/balances",
            "/performance  cycles", "/training", "/awards", "/events"]),
        ("OFFBOARDING", 640, 96, [
            "/offboarding  exits", "/offboarding/{case}  clearance"]),
        ("ANALYTICS & AI", 640, 220, [
            "/analytics/attrition", "/analytics/performance-forecast",
            "/analytics/promotion-readiness", "/reports  seven reports",
            "assistant  (floating, every page)"]),
        ("COMPANY SETUP", 940, 96, [
            "/setup/company  profile + join code", "/setup/departments",
            "/setup/schedule  shifts + holidays", "/setup/leave-types",
            "/setup/award-types", "/setup/performance  frameworks",
            "/setup/onboarding  programmes", "/setup/offboarding  templates",
            "/setup/notifications"]),
        ("SYSTEM", 940, 356, [
            "/system/users", "/system/roles", "/system/activity-logs",
            "/system/trash"]),
    ]
    for title, x, y, items in groups:
        h = 34 + len(items) * 22
        f.back(rect(x, y, 260, h, 6, WHITE, INK, SW))
        f.back(text(x + 12, y + 22, title, 9.6, "start", "bold", MUTED))
        for i, it in enumerate(items):
            f.add(text(x + 16, y + 44 + i * 22, "- " + it, 9, "start", "normal", INK))

    f.node("dash", 40, 340, 260, 56, "box",
           [("/dashboard", 10.5, "bold", INK),
            ("the landing surface once a workspace is chosen", 8.6, "normal", MUTED)], FILL_LIGHT)
    f.node("pick", 40, 412, 260, 56, "box",
           [("/workspaces  picker + switcher", 10.5, "bold", INK),
            ("skipped when the user belongs to exactly one", 8.6, "normal", MUTED)], FILL_LIGHT)
    f.node("careers", 40, 484, 260, 56, "box",
           [("/careers/{org-slug}", 10.5, "bold", INK),
            ("public, outside the login entirely", 8.6, "normal", MUTED)], WHITE, DASH)

    f.back(rect(40, 574, 560, 250, 6, WHITE, INK, SW, DASH))
    f.front(text(56, 596, "MOBILE COMPANION  (Expo / expo-router)", 9.6, "start", "bold", MUTED))
    tabs = [
        ("Home", "greeting, today's clock state, quick actions, leave-balance mini-cards, latest award, workspace chip"),
        ("Clock  (the hero)", "one primary button whose label flips with the day's state; real GPS + optional selfie; live worked-hours counter"),
        ("Attendance", "month calendar with status dots, metrics card, list view, per-day punch timeline"),
        ("Requests / Leave", "balances per type, file-leave form with server-computed days, history, cancel"),
        ("Profile & Awards", "own 201 profile with government IDs masked and salary omitted entirely, plus recognitions"),
    ]
    yy = 616
    for name, desc in tabs:
        f.add(rect(56, yy, 528, 36, 3, WHITE, INK, SW_THIN))
        f.add(text(68, yy + 15, name, 9.6, "start", "bold"))
        f.add(text(68, yy + 29, desc, 8.5, "start", "normal", MUTED))
        yy += 40

    f.node("gate", 640, 574, 560, 116, "note",
           [("PERMISSION-AWARE NAVIGATION", 9.6, "bold", INK),
            ("A sidebar item is not merely disabled when the viewer lacks its permission - it is not rendered, and its route is", 9, "normal", MUTED),
            ("gated independently, so hiding the link is a convenience rather than the control. Dashboard blocks follow the same", 9, "normal", MUTED),
            ("rule: each is computed only when the viewer holds the matching *.view permission and is otherwise returned as null,", 9, "normal", MUTED),
            ("so a Staff user receives an empty overview rather than figures they are not allowed to see.", 9, "normal", MUTED)],
           FILL_LIGHT)

    f.node("out", 640, 706, 560, 118, "note",
           [("WHAT DELIBERATELY IS NOT ON THE PHONE", 9.6, "bold", INK),
            ("Creating a company, managing anyone else's record, running an assessment, or reading another employee's data.", 9, "normal", MUTED),
            ("The mobile surface is self-scoped: every endpoint returns the caller's own data and needs no permission beyond", 9, "normal", MUTED),
            ("the two the Staff role carries (attendance.clock and leave.request). It is a self-service companion with one hero", 9, "normal", MUTED),
            ("feature, not a small copy of the ERP.", 9, "normal", MUTED)], FILL_LIGHT)
    return "fig-3-36-navigation-ia.svg", f


FIGURES = [fig_use_case, fig_states, fig_sequence, fig_erd, fig_security,
           fig_navigation]
