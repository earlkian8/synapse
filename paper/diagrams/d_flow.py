"""Figures 3.19-3.24 - system flowchart and the five expanded control-flow charts."""

from svgkit import (DASH, FILL_LIGHT, FILL_MID, INK, MUTED, SW, T_SMALL, WHITE,
                    Fig, arrowhead, legend, path, rect, text)

FLOW_LEGEND = [
    ("stadium" if False else "round", "process / action"),
    ("diamond", "decision"),
    ("box", "terminator / data"),
    ("arrow", "control flow"),
    ("dashed-arrow", "loop / degraded path"),
]


def chain(f, cx, y0, steps, gap=30):
    """steps: (key, kind, lines, width[, height])"""
    y = y0
    prev = None
    for s in steps:
        key, kind, lines = s[0], s[1], s[2]
        w = s[3] if len(s) > 3 else 260
        h = s[4] if len(s) > 4 else (74 if kind == "diamond" else max(36, 20 + 13 * len(lines)))
        f.node(key, cx - w / 2, y, w, h, kind, lines,
               FILL_LIGHT if kind == "stadium" else WHITE)
        if prev:
            f.edge(prev, key, sides=("s", "n"))
        prev = key
        y += h + gap
    return y - gap


# -- Figure 3.19 --------------------------------------------------------------

def fig_system_flowchart():
    f = Fig(1240, 1000, "Figure 3.19  System Flowchart of SYNAPSE",
            "The four flows that leave the dashboard, and where each one ends")

    f.node("start", 540, 78, 160, 36, "stadium", ["START"], FILL_LIGHT)
    f.node("auth", 470, 146, 300, 52, "round",
           ["Authenticate  (Figure 3.20)",
            ("identity -> tenancy -> authorization", 9, "normal", MUTED)])
    f.node("dash", 470, 228, 300, 56, "round",
           ["Render permission-aware dashboard",
            ("blocks the viewer may not see return null", 9, "normal", MUTED)])
    f.node("pick", 520, 316, 200, 74, "diamond", ["What is the", "user doing?"])
    f.edge("start", "auth", sides=("s", "n"))
    f.edge("auth", "dash", sides=("s", "n"))
    f.edge("dash", "pick", sides=("s", "n"))

    cols = [
        ("t", 60, "HR transaction", [
            ("t1", "round", ["Validate (FormRequest)", ("+ TenantRule", 9, "normal", MUTED)]),
            ("t2", "diamond", ["Authorized", "and valid?"]),
            ("t3", "round", ["Persist in a transaction", ("through the canonical class", 9, "normal", MUTED)]),
            ("t4", "round", ["Write activity log", ("+ notify audience", 9, "normal", MUTED)]),
            ("t5", "round", ["Flash toast", ("+ Inertia redirect", 9, "normal", MUTED)]),
        ]),
        ("a", 340, "Predictive assessment", [
            ("a1", "round", ["Gather cohort", ("+ map features", 9, "normal", MUTED)]),
            ("a2", "diamond", ["ML service", "online?"]),
            ("a3", "round", ["Score batch, derive", ("tier / band / confidence", 9, "normal", MUTED)]),
            ("a4", "round", ["Persist run + scores", ("with feature snapshot", 9, "normal", MUTED)]),
            ("a5", "round", ["Render ranked list", ("(Figure 3.23)", 9, "normal", MUTED)]),
        ]),
        ("s", 620, "AI assistant turn", [
            ("s1", "round", ["Build permitted tools", ("for THIS user", 9, "normal", MUTED)]),
            ("s2", "diamond", ["Action", "requested?"]),
            ("s3", "round", ["Module re-checks permission", ("and executes", 9, "normal", MUTED)]),
            ("s4", "round", ["Loop, max 6 round-trips", ("(Figure 3.24)", 9, "normal", MUTED)]),
            ("s5", "round", ["Compose reply", ("+ action cards", 9, "normal", MUTED)]),
        ]),
        ("r", 900, "Reporting / export", [
            ("r1", "round", ["Resolve + authorise report"]),
            ("r2", "diamond", ["Export", "requested?"]),
            ("r3", "round", ["Stream CSV / XLSX", ("same rows as on screen", 9, "normal", MUTED)]),
            ("r4", "round", ["Charts + ML chips", ("from stored runs", 9, "normal", MUTED)]),
            ("r5", "round", ["Optional AI insight", ("on a compact digest", 9, "normal", MUTED)]),
        ]),
    ]
    for key, x, title, steps in cols:
        f.front(text(x + 130, 424, title, 10.5, "middle", "bold", MUTED))
        y = 438
        prev = None
        for k, kind, lines in steps:
            h = 66 if kind == "diamond" else max(40, 22 + 13 * len(lines))
            f.node(k, x, y, 260, h, kind, lines)
            if prev:
                f.edge(prev, k, sides=("s", "n"))
            prev = k
            y += h + 22
        f.edge("pick", steps[0][0], sides=("s", "n"))

    f.node("end", 540, 900, 160, 36, "stadium", ["END"], FILL_LIGHT)
    for k in ("t5", "a5", "s5", "r5"):
        f.edge(k, "end", sides=("s", "n"))

    f.node("degr", 60, 818, 260, 56, "box",
           [("Degraded path", 10, "bold", INK),
            ("stored run + offline notice / disabled AI panel", 8.7, "normal", MUTED)],
           WHITE, DASH)
    f.edge("a2", "degr", sides=("w", "e"), dashed=True, label="No", label_at=0.08)
    f.edge("degr", "end", sides=("s", "w"), dashed=True)

    legend(f, 980, 856, FLOW_LEGEND, title="LEGEND")
    return "fig-3-19-system-flowchart.svg", f


# -- Figure 3.20 --------------------------------------------------------------

def fig_auth():
    f = Fig(1060, 940, "Figure 3.20  Flowchart - Authentication and Session Establishment",
            "Three gates in a fixed order: identity, then tenancy, then authorization")

    cx = 440
    y = chain(f, cx, 84, [
        ("s", "stadium", ["START"], 150, 36),
        ("cred", "round", ["User submits e-mail + password", ("web: Fortify  |  mobile: POST /api/auth/login", 9, "normal", MUTED)], 340),
        ("d1", "diamond", ["Credentials valid?", ("throttled per e-mail + IP", 8.7, "normal", MUTED)], 260, 84),
        ("d2", "diamond", ["Second factor enrolled?", ("TOTP or passkey - optional", 8.7, "normal", MUTED)], 300, 84),
    ], gap=34)

    f.node("verify", cx - 150, y + 34, 300, 52, "round",
           ["Verify second factor", ("TOTP code / WebAuthn assertion", 9, "normal", MUTED)])
    f.node("d3", cx - 130, y + 120, 260, 74, "diamond", ["Factor verified?"])
    f.edge("d2", "verify", sides=("s", "n"), label="Yes")
    f.edge("verify", "d3", sides=("s", "n"))

    f.node("resolve", cx - 190, y + 228, 380, 60, "round",
           ["Resolve active organisation",
            ("web: session active_organization_id  |  mobile: token binding", 9, "normal", MUTED)])
    f.edge("d3", "resolve", sides=("s", "n"), label="Yes")
    f.edge("d2", "resolve", sides=("e", "e"), via=690, dashed=True,
           label=["No - bypass", "verification"], label_dy=-14)

    f.node("d4", cx - 150, y + 316, 300, 78, "diamond",
           ["Member of that organisation?", ("checked against organization_user", 8.5, "normal", MUTED)])
    f.edge("resolve", "d4", sides=("s", "n"))

    f.node("d5", cx - 150, y + 424, 300, 78, "diamond",
           ["Belongs to more than one?"])
    f.edge("d4", "d5", sides=("s", "n"), label="Yes")

    f.node("picker", cx + 220, y + 424, 250, 60, "round",
           ["Show workspace picker", ("user chooses; session persists it", 9, "normal", MUTED)])
    f.edge("d5", "picker", sides=("e", "w"), label="Yes")
    f.node("perm", cx - 190, y + 534, 380, 60, "round",
           ["Load role permissions and establish session",
            ("gates defined from PermissionRegistry at boot", 9, "normal", MUTED)])
    f.edge("d5", "perm", sides=("s", "n"), label="No - forward through")
    f.edge("picker", "perm", sides=("s", "e"))

    f.node("end", cx - 80, y + 626, 160, 36, "stadium", ["DASHBOARD"], FILL_LIGHT)
    f.edge("perm", "end", sides=("s", "n"))

    f.node("retry", 70, 200, 230, 56, "box",
           ["Return to sign-in with error", ("attempt counted by the limiter", 9, "normal", MUTED)], WHITE, DASH)
    f.edge("d1", "retry", sides=("w", "e"), dashed=True, label="No")
    f.edge("d3", "retry", sides=("w", "w"), via=54, dashed=True, label="No")

    f.node("deny", 70, y + 316, 250, 78, "box",
           [("Deny / prompt to switch", 10, "bold", INK),
            ("a correctly authenticated user still", 8.7, "normal", MUTED),
            ("cannot enter a workspace they are", 8.7, "normal", MUTED),
            ("not a member of", 8.7, "normal", MUTED)], WHITE, DASH)
    f.edge("d4", "deny", sides=("w", "e"), dashed=True, label="No")

    f.node("noorg", 730, y + 228, 260, 76, "box",
           [("needs_workspace = true", 10, "bold", INK),
            ("a self-registered identity that has", 8.7, "normal", MUTED),
            ("joined no company is sent to the", 8.7, "normal", MUTED),
            ("invitation / join-code screen", 8.7, "normal", MUTED)], WHITE, DASH)
    f.edge("resolve", "noorg", sides=("e", "w"), dashed=True, label="no membership yet")

    legend(f, 730, 820, FLOW_LEGEND, title="LEGEND")
    return "fig-3-20-flowchart-authentication.svg", f


# -- Figure 3.21 --------------------------------------------------------------

def fig_leave_flow():
    f = Fig(1060, 960, "Figure 3.21  Flowchart - Leave Request with Approval",
            "The validate - persist - route - decide - notify pattern shared by every approval workflow")

    cx = 430
    y = chain(f, cx, 84, [
        ("s", "stadium", ["START  -  employee files leave"], 300, 36),
        ("in", "round", ["Submit type, date range, half-day flag, reason", ("web form or mobile Leave tab", 9, "normal", MUTED)], 400),
        ("hol", "round", ["Resolve non-working holidays for the range", ("HolidayCalendar expands yearly-recurring rows", 9, "normal", MUTED)], 400),
        ("calc", "round", ["Compute chargeable days on the SERVER", ("LeaveCalculator; a client-sent count is never trusted", 9, "normal", MUTED)], 400),
        ("d1", "diamond", ["Dates valid and balance sufficient?"], 340, 78),
    ], gap=28)

    f.node("err", 60, 300, 240, 60, "box",
           ["Return to the form with errors", ("invalid requests never enter the workflow", 8.7, "normal", MUTED)],
           WHITE, DASH)
    f.edge("d1", "err", sides=("w", "e"), dashed=True, label="No")

    f.node("d2", cx - 170, y + 30, 340, 78, "diamond",
           ["Does the type require approval?"])
    f.edge("d1", "d2", sides=("s", "n"), label="Yes")

    f.node("auto", 790, y + 40, 230, 58, "round",
           ["Auto-approve on submission", ("status = approved", 9, "normal", MUTED)])
    f.edge("d2", "auto", sides=("e", "w"), label="No")

    f.node("pend", cx - 190, y + 140, 380, 58, "round",
           ["Persist as PENDING (tenant-scoped)",
            ("organization_id stamped by the model layer", 9, "normal", MUTED)])
    f.edge("d2", "pend", sides=("s", "n"), label="Yes")

    f.node("notify1", cx - 190, y + 228, 380, 58, "round",
           ["Notify the approver audience",
            ("Notifier::toRole('hr-manager') - in-app + e-mail + push", 9, "normal", MUTED)])
    f.edge("pend", "notify1", sides=("s", "n"))

    f.node("d3", cx - 150, y + 316, 300, 78, "diamond", ["Approved?"])
    f.edge("notify1", "d3", sides=("s", "n"))

    f.node("rej", 760, y + 320, 260, 70, "round",
           ["Mark REJECTED + reason", ("balance untouched", 9, "normal", MUTED)])
    f.edge("d3", "rej", sides=("e", "w"), label="No")

    f.node("app", cx - 190, y + 424, 380, 58, "round",
           ["Mark APPROVED",
            ("used = SUM(approved days) is re-derived, not stored", 9, "normal", MUTED)])
    f.edge("d3", "app", sides=("s", "n"), label="Yes")

    f.node("att", cx - 190, y + 512, 380, 58, "round",
           ["Attendance becomes leave-aware for those dates",
            ("a covered day is on_leave, never absent", 9, "normal", MUTED)])
    f.edge("app", "att", sides=("s", "n"))

    f.node("log", cx - 190, y + 600, 380, 58, "round",
           ["Write activity log + notify the requester",
            ("both terminal outcomes notify and are auditable", 9, "normal", MUTED)])
    f.edge("att", "log", sides=("s", "n"))
    f.edge("rej", "log", sides=("s", "e"))
    f.edge("auto", "log", sides=("s", "e"), via=1030)

    f.node("end", cx - 80, y + 690, 160, 36, "stadium", ["END"], FILL_LIGHT)
    f.edge("log", "end", sides=("s", "n"))

    f.node("cancel", 60, y + 512, 240, 74, "box",
           [("Employee cancellation", 10, "bold", INK),
            ("a pending or upcoming request may be", 8.7, "normal", MUTED),
            ("withdrawn; the derived balance", 8.7, "normal", MUTED),
            ("recovers automatically", 8.7, "normal", MUTED)], WHITE, DASH)
    f.edge("cancel", "app", sides=("e", "w"), dashed=True)

    legend(f, 780, 830, FLOW_LEGEND, title="LEGEND")
    return "fig-3-21-flowchart-leave-approval.svg", f


# -- Figure 3.22 --------------------------------------------------------------

def fig_hire_bridge():
    f = Fig(1120, 880, "Figure 3.22  Flowchart - The Hire Bridge (Recruitment to Workforce)",
            "One database transaction converts a candidate into an employee; identity is issued separately")

    cx = 470
    y = chain(f, cx, 84, [
        ("s", "stadium", ["START  -  HR presses Hire on an application at the OFFER stage"], 470, 36),
        ("d1", "diamond", ["Already hired?", ("hired_employee_id is not null", 8.7, "normal", MUTED)], 300, 78),
    ], gap=30)

    f.node("stop", 830, 152, 250, 58, "box",
           ["Reject the action", ("a hired candidate can never be un-hired", 8.7, "normal", MUTED)],
           WHITE, DASH)
    f.edge("d1", "stop", sides=("e", "w"), dashed=True, label="Yes")

    f.back(rect(180, y + 34, 580, 320, 8, WHITE, INK, SW, DASH))
    f.front(text(196, y + 54, "ONE DATABASE TRANSACTION  (ApplicantHirer::hire)",
                 T_SMALL, "start", "bold", MUTED))

    steps = [
        ("h1", ["Create the employee record", ("EmployeeNumbers::next() issues EMP-NNNNN - never max(id)+1", 8.7, "normal", MUTED)]),
        ("h2", ["Copy the resume into the new 201 file", ("private disk, access-logged from the first read", 8.7, "normal", MUTED)]),
        ("h3", ["Seed onboarding from the best-matching programme", ("OnboardingProvisioner::start() - dept+type > dept > type > default", 8.7, "normal", MUTED)]),
        ("h4", ["Mark the application HIRED and link it to the employee"]),
        ("h5", ["Fill the posting when its openings are met", ("status open -> filled", 8.7, "normal", MUTED)]),
    ]
    yy = y + 66
    prev = "d1"
    for k, lines in steps:
        f.node(k, 200, yy, 540, 52, "round", lines)
        f.edge(prev, k, sides=("s", "n"))
        prev = k
        yy += 62
    f.edge("d1", "h1", sides=("s", "n"), label="No")

    f.node("commit", cx - 170, y + 380, 340, 52, "round",
           ["Commit; queue side effects AFTER commit",
            ("nothing fires on a rolled-back hire", 9, "normal", MUTED)])
    f.edge("h5", "commit", sides=("s", "n"))

    f.node("inv", cx - 190, y + 462, 380, 68, "round",
           ["Issue an app invitation to the new hire",
            ("hashed link token + retypeable 8-character code;", 9, "normal", MUTED),
            ("hiring creates EMPLOYMENT, never a login (ADR 0026)", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("commit", "inv", sides=("s", "n"))

    f.node("claim", 830, y + 462, 260, 68, "box",
           [("The person claims the seat", 10, "bold", INK),
            ("registers their own identity, then", 8.7, "normal", MUTED),
            ("redeems the code; both paths converge", 8.7, "normal", MUTED),
            ("on OrganizationProvisioner::admit()", 8.7, "normal", MUTED)], WHITE, DASH)
    f.edge("inv", "claim", sides=("e", "w"), dashed=True)

    f.node("end", cx - 80, y + 566, 160, 36, "stadium", ["END"], FILL_LIGHT)
    f.edge("inv", "end", sides=("s", "n"))

    f.node("note", 70, y + 120, 240, 118, "note",
           [("WHY ONE TRANSACTION", 9.5, "bold", INK),
            ("The five writes are mutually", 9, "normal", MUTED),
            ("dependent: an employee with no", 9, "normal", MUTED),
            ("onboarding case, or an application", 9, "normal", MUTED),
            ("marked hired with no employee,", 9, "normal", MUTED),
            ("would each be a silent data fault.", 9, "normal", MUTED)], FILL_LIGHT)

    legend(f, 830, y + 620, FLOW_LEGEND, title="LEGEND")
    return "fig-3-22-flowchart-hire-bridge.svg", f


# -- Figure 3.23 --------------------------------------------------------------

def fig_predictive():
    f = Fig(1100, 900, "Figure 3.23  Flowchart - Predictive Assessment Run",
            "The health check precedes the prediction call, so the system decides whether it can predict before it tries")

    cx = 450
    y = chain(f, cx, 84, [
        ("s", "stadium", ["START  -  HR presses Run assessment"], 340, 36),
        ("coh", "round", ["Select the cohort: every ACTIVE employee", ("eager-loads department, scored appraisals, promotions,", 9, "normal", MUTED), ("90-day overtime sum and 12-month training count", 9, "normal", MUTED)], 460),
        ("feat", "round", ["Map each employee to a feature dictionary", ("ungroundable inputs are OMITTED, never sent as zero", 9, "normal", MUTED)], 460),
        ("snap", "round", ["Keep the exact feature snapshot per employee"], 460),
        ("d1", "diamond", ["ML service reachable?", ("GET /health with a short timeout", 8.7, "normal", MUTED)], 320, 82),
    ], gap=26)

    f.node("off", 790, y - 40, 270, 96, "box",
           [("DEGRADED PATH", 10, "bold", INK),
            ("show the LAST STORED run with an", 8.7, "normal", MUTED),
            ("offline banner; record no new run;", 8.7, "normal", MUTED),
            ("surface the exact command that starts", 8.7, "normal", MUTED),
            ("the service", 8.7, "normal", MUTED)], WHITE, DASH)
    f.edge("d1", "off", sides=("e", "w"), dashed=True, label="No")

    f.node("post", cx - 230, y + 42, 460, 58, "round",
           ["POST /predict/{model} with the whole batch",
            ("{ instances: [ { ref, features }, ... ] }", 9, "normal", MUTED)])
    f.edge("d1", "post", sides=("s", "n"), label="Yes")

    f.node("align", cx - 230, y + 130, 460, 70, "round",
           ["Service aligns to a full-width DataFrame",
            ("the fitted imputers fill the gaps; handle_unknown='ignore'", 9, "normal", MUTED),
            ("neutralises a category the model never saw", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("post", "align", sides=("s", "n"))

    f.node("score", cx - 230, y + 230, 460, 58, "round",
           ["Score; decompose contributions for the linear model only",
            ("a forest and a point regressor expose no per-instance 'why'", 9, "normal", MUTED)])
    f.edge("align", "score", sides=("s", "n"))

    f.node("derive", cx - 230, y + 318, 460, 72, "round",
           ["Laravel derives the human-facing quantities",
            ("tier from probability  |  band from predicted rating", 9, "normal", MUTED),
            ("confidence = grounded key features / total key features", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("score", "derive", sides=("s", "n"))

    f.node("persist", cx - 230, y + 420, 460, 70, "round",
           ["Persist inside one transaction",
            ("ONE run header + ONE score row per employee,", 9, "normal", MUTED),
            ("each carrying its feature snapshot; log the activity", 9, "normal", MUTED)])
    f.edge("derive", "persist", sides=("s", "n"))

    f.node("render", cx - 230, y + 520, 460, 58, "round",
           ["Render the ranked list; past runs stay selectable",
            ("predictions are NEVER written onto the employee record", 9, "normal", MUTED)])
    f.edge("persist", "render", sides=("s", "n"))
    f.edge("off", "render", sides=("s", "e"), dashed=True)

    f.node("d2", cx - 130, y + 608, 260, 74, "diamond", ["Export report?"])
    f.edge("render", "d2", sides=("s", "n"))
    f.node("exp", 790, y + 616, 250, 56, "round", ["Generate report / CSV"])
    f.edge("d2", "exp", sides=("e", "w"), label="Yes")
    f.node("end", cx - 80, y + 708, 160, 36, "stadium", ["END"], FILL_LIGHT)
    f.edge("d2", "end", sides=("s", "n"), label="No")
    f.edge("exp", "end", sides=("s", "e"))

    f.node("note", 60, y + 300, 250, 128, "note",
           [("WHY STORE THE SNAPSHOT", 9.5, "bold", INK),
            ("A stored score alone says what the", 9, "normal", MUTED),
            ("model answered. The snapshot says", 9, "normal", MUTED),
            ("what it was ASKED. That is what", 9, "normal", MUTED),
            ("makes an old prediction auditable,", 9, "normal", MUTED),
            ("and what lets a low-confidence", 9, "normal", MUTED),
            ("score be explained rather than", 9, "normal", MUTED),
            ("defended.", 9, "normal", MUTED)], FILL_LIGHT)

    legend(f, 790, y + 700, FLOW_LEGEND, title="LEGEND")
    return "fig-3-23-flowchart-predictive-assessment.svg", f


# -- Figure 3.24 --------------------------------------------------------------

def fig_assistant_loop():
    f = Fig(1240, 900, "Figure 3.24  Flowchart - LLM Assistant Function-Calling Loop",
            "Permission filtering precedes the model call; the loop has a hard ceiling of six round-trips")

    cx = 460
    y = chain(f, cx, 84, [
        ("s", "stadium", ["START  -  user sends a message (optionally with files)"], 400, 36),
        ("thr", "round", ["Throttle: 12 requests / minute, 240 / day per user"], 420),
        ("mods", "round", ["Filter capability modules to those available to this user"], 420),
        ("tools", "round", ["Build PERMISSION-SCOPED tool schemas", ("a view-only recruiter is offered 8 tools where a full recruiter is offered 25", 8.7, "normal", MUTED)], 460),
        ("call", "round", ["Call Gemini with history + files + system instruction"], 460),
        ("d1", "diamond", ["Function call requested?"], 300, 74),
    ], gap=26)

    f.node("txt", 800, y - 62, 250, 56, "round",
           ["Return the prose answer", ("the common look-something-up turn", 8.7, "normal", MUTED)])
    f.edge("d1", "txt", sides=("e", "w"), label="No")

    f.node("d2", cx - 190, y + 40, 380, 84, "diamond",
           ["Permitted, known tool and valid arguments?"])
    f.edge("d1", "d2", sides=("s", "n"), label="Yes")

    f.node("refuse", 800, y + 50, 250, 66, "box",
           ["Return a refusal or a request to clarify",
            ("only errors and unknown tools are fed", 8.7, "normal", MUTED),
            ("back to the model to recover", 8.7, "normal", MUTED)], WHITE, DASH)
    f.edge("d2", "refuse", sides=("e", "w"), dashed=True, label="No")

    f.node("exec", cx - 230, y + 146, 460, 72, "round",
           ["Execute through the canonical module class",
            ("permission re-checked, tenancy applied, input validated,", 9, "normal", MUTED),
            ("activity logged, notifications sent - in one box on purpose", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("d2", "exec", sides=("s", "n"), label="Yes")

    f.node("guard", cx - 230, y + 246, 460, 72, "round",
           ["Apply outcome guards",
            ("advance_application stops at reject or hire;", 9, "normal", MUTED),
            ("negative and irreversible outcomes stay human decisions", 9, "normal", MUTED)])
    f.edge("exec", "guard", sides=("s", "n"))

    f.node("san", cx - 230, y + 346, 460, 72, "round",
           ["Sanitise the tool result",
            ("strip control characters, cap free text, cap lists at 25 rows,", 9, "normal", MUTED),
            ("withhold the nine disclosure-restricted fields", 9, "normal", MUTED)])
    f.edge("guard", "san", sides=("s", "n"))

    f.node("d3", cx - 190, y + 446, 380, 84, "diamond",
           ["Did EVERY call in this step succeed?"])
    f.edge("san", "d3", sides=("s", "n"))

    f.node("synth", cx - 230, y + 556, 460, 66, "round",
           ["Compose the reply LOCALLY from the results",
            ("saves a second Gemini request on the common turn", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("d3", "synth", sides=("s", "n"), label="Yes")

    f.node("d4", 770, y + 450, 280, 78, "diamond", ["Round-trip < 6?"])
    f.edge("d3", "d4", sides=("e", "w"), label="No")
    f.edge("d4", "call", sides=("e", "e"), via=1200, dashed=True,
           label=["Yes - feed results back", "as functionResponse parts"], label_dy=-14)

    f.node("bail", 800, y + 560, 250, 60, "box",
           ["Stop and report what was done", ("bounded latency and bounded API cost", 8.7, "normal", MUTED)],
           WHITE, DASH)
    f.edge("d4", "bail", sides=("s", "n"), label="No")

    f.node("persist", cx - 230, y + 654, 460, 56, "round",
           ["Persist the conversation; log named-person reads",
            ("searches and headcounts are not logged as views", 9, "normal", MUTED)])
    f.edge("synth", "persist", sides=("s", "n"))
    for key, col, dashed in (("txt", 1088, False), ("refuse", 1104, True),
                             ("bail", 1120, False)):
        src, dst = f.n(key), f.n("persist")
        sx, sy = src.port("e")
        dx, dy = dst.port("e")
        f.add(path("M %.1f %.1f L %.1f %.1f L %.1f %.1f L %.1f %.1f"
                   % (sx, sy, col, sy, col, dy, dx, dy), "none", INK, SW,
                   DASH if dashed else None))
        f.add(arrowhead(dx, dy, -1, 0))

    f.node("end", cx - 80, y + 742, 160, 36, "stadium", ["END"], FILL_LIGHT)
    f.edge("persist", "end", sides=("s", "n"))

    f.node("note", 20, y + 250, 200, 158, "note",
           [("THE GOVERNING RULE", 9.5, "bold", INK),
            ("The model only DECIDES which", 9, "normal", MUTED),
            ("action to take and with what", 9, "normal", MUTED),
            ("arguments. The module ENFORCES.", 9, "normal", MUTED),
            ("Tool results and uploaded documents", 9, "normal", MUTED),
            ("are treated as data, never as", 9, "normal", MUTED),
            ("instructions - retrieved text can", 9, "normal", MUTED),
            ("never grant a permission or trigger", 9, "normal", MUTED),
            ("an action.", 9, "normal", MUTED)], FILL_LIGHT)

    legend(f, 30, y + 620, FLOW_LEGEND, title="LEGEND")
    return "fig-3-24-flowchart-assistant-loop.svg", f


FIGURES = [fig_system_flowchart, fig_auth, fig_leave_flow, fig_hire_bridge,
           fig_predictive, fig_assistant_loop]
