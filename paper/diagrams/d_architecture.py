"""Figures 3.1-3.3 - architecture, deployment, request lifecycle."""

from svgkit import (DASH, DASH_FINE, FILL_LIGHT, FILL_MID, INK, MUTED, SW,
                    SW_THIN, T_SMALL, T_TINY, WHITE, Fig, arrowhead, legend,
                    line, rect, text)


# -- Figure 3.1 --------------------------------------------------------------

def fig_architecture():
    f = Fig(1180, 850, "Figure 3.1  Layered System Architecture of SYNAPSE",
            "Presentation - Application - Data & Intelligence; trust flows downward, never sideways")

    # Institution perimeter
    f.back(rect(30, 66, 880, 748, 8, WHITE, INK, SW, DASH))
    f.back(text(44, 86, "INSTITUTION INFRASTRUCTURE  (self-hosted / single VPS)",
                T_SMALL, "start", "bold", MUTED))

    # Band 1 - presentation
    f.node("band1", 52, 100, 838, 132, "band", ["PRESENTATION LAYER"], FILL_LIGHT)
    f.node("web", 80, 132, 240, 82, "box",
           ["Web Client", ("React 19 + TypeScript", 9.5, "normal", MUTED),
            ("Inertia.js SPA - 53 pages", 9.5, "normal", MUTED)])
    f.node("mobile", 350, 132, 230, 82, "box",
           ["Mobile Companion", ("Expo / React Native", 9.5, "normal", MUTED),
            ("Employee self-service", 9.5, "normal", MUTED)])
    f.node("public", 610, 132, 250, 82, "box",
           ["Public Careers Page", ("Unauthenticated route", 9.5, "normal", MUTED),
            ("/careers/{org-slug}", 9.5, "normal", MUTED)])

    # Band 2 - application
    f.node("band2", 52, 262, 838, 300, "band", ["APPLICATION LAYER  -  Laravel 13 / PHP 8.3  (single process, single trust context)"], FILL_LIGHT)
    f.node("gate", 80, 296, 780, 62, "box",
           ["SECURITY & TENANCY GATE",
            ("Fortify + 2FA/passkeys  |  Sanctum tokens  |  SetCurrentOrganization  |  "
             "OrganizationScope  |  73 permission gates  |  ActivityLogger", 9.5, "normal", MUTED)],
           FILL_MID)

    f.node("mod", 80, 382, 470, 160, "box",
           ["HR MODULES",
            ("Recruitment - Onboarding - Employees (201)", 9.5, "normal", MUTED),
            ("Attendance - Leave - Performance", 9.5, "normal", MUTED),
            ("Training - Awards - Events - Offboarding", 9.5, "normal", MUTED),
            ("Company Setup - System Administration", 9.5, "normal", MUTED),
            ("Dashboard - Reports", 9.5, "normal", MUTED)])
    f.node("dss", 578, 382, 282, 160, "box",
           ["DECISION-SUPPORT SERVICES",
            ("ApplicantScorer - PerformanceScorer", 9.5, "normal", MUTED),
            ("AwardNominator - PipelineInsights", 9.5, "normal", MUTED),
            ("AttendanceCalculator - LeaveCalculator", 9.5, "normal", MUTED),
            ("MlClient - Assessors - GeminiClient", 9.5, "normal", MUTED),
            ("Assistant (56 permission-scoped tools)", 9.5, "normal", MUTED)])

    # Band 3 - data & intelligence
    f.node("band3", 52, 592, 838, 200, "band", ["DATA & INTELLIGENCE LAYER"], FILL_LIGHT)
    f.node("db", 90, 630, 250, 138, "cyl",
           ["PostgreSQL 16",
            ("69 tables, row-level", 9.5, "normal", MUTED),
            ("multi-tenancy", 9.5, "normal", MUTED),
            ("encrypted gov IDs", 9.5, "normal", MUTED)], FILL_LIGHT)
    f.node("files", 366, 630, 200, 138, "box",
           ["Private File Store",
            ("resumes - 201 documents", 9.5, "normal", MUTED),
            ("certifications - selfies", 9.5, "normal", MUTED),
            ("routed + access-logged", 9.5, "normal", MUTED)])
    f.node("ml", 592, 630, 268, 138, "box",
           ["ML Inference Service",
            ("FastAPI + Uvicorn (Python)", 9.5, "normal", MUTED),
            ("3 scikit-learn pipelines", 9.5, "normal", MUTED),
            ("GET /health", 9.5, "normal", MUTED),
            ("POST /predict/{model}", 9.5, "normal", MUTED)])

    # External
    f.node("gemini", 950, 382, 200, 160, "box",
           ["Google Gemini API",
            ("gemini-2.5-flash", 9.5, "normal", MUTED),
            ("temperature 0.2", 9.5, "normal", MUTED),
            ("function calling +", 9.5, "normal", MUTED),
            ("native document reading", 9.5, "normal", MUTED)], WHITE, DASH)
    f.node("smtp", 950, 630, 200, 90, "box",
           ["SMTP / Web Push (VAPID)",
            ("outbound notification", 9.5, "normal", MUTED),
            ("transports", 9.5, "normal", MUTED)], WHITE, DASH)

    f.back(text(1050, 90, "EXTERNAL", T_SMALL, "middle", "bold", MUTED))

    # Edges
    f.edge("web", "gate", ["HTTPS - session cookie", "Inertia page visits"],
           sides=("s", "n"), offset=-80, both=True, label_dy=-16)
    f.edge("mobile", "gate", ["HTTPS - Bearer token", "(Sanctum)"],
           sides=("s", "n"), offset=0, both=True, label_dy=-16)
    f.edge("public", "gate", ["HTTPS - throttled,", "honeypot-guarded"],
           sides=("s", "n"), offset=120, both=True, label_dy=-16)

    f.edge("gate", "mod", sides=("s", "n"), offset=-160, label="authorized request")
    f.edge("gate", "dss", sides=("s", "n"), offset=140, label="authorized request")

    f.edge("mod", "db", sides=("s", "n"), offset=-60, both=True,
           label=["Eloquent ORM", "(tenant-scoped)"], label_dy=-14)
    f.edge("mod", "files", sides=("s", "n"), offset=140, both=True, label="private disk")
    f.edge("dss", "ml", sides=("s", "n"), offset=0, both=True,
           label=["internal HTTP", "bounded timeout"], label_dy=-14)
    f.edge("dss", "gemini", sides=("e", "w"), dashed=True, both=True,
           label=["HTTPS, server-side only", "(API key never leaves the server)"],
           label_dy=-16)
    f.edge("mod", "smtp", sides=("e", "s"), dashed=True, via=910,
           label="in-app / email / push")

    legend(f, 950, 780, [
        ("arrow", "request / response"),
        ("dashed-arrow", "external dependency"),
        ("dashed", "outside the perimeter"),
    ], title="LEGEND")

    f.front(text(600, 828,
                 "Graceful degradation: if the ML service is unreachable the analytics screens render the last stored run; "
                 "if Gemini is unset every AI panel reports itself unconfigured. No core module blocks.",
                 T_SMALL, "middle", fill=MUTED))
    return "fig-3-1-system-architecture.svg", f


# -- Figure 3.2 --------------------------------------------------------------

def fig_deployment():
    f = Fig(1120, 690, "Figure 3.2  Deployment Diagram",
            "Physical nodes, protocols and ports of a single-institution deployment")

    f.node("browser", 60, 90, 240, 100, "box",
           ["<<device>> HR Workstation",
            ("Chromium / Firefox / Edge", 9.5, "normal", MUTED),
            ("service worker for web push", 9.5, "normal", MUTED)], FILL_LIGHT)
    f.node("phone", 60, 230, 240, 100, "box",
           ["<<device>> Employee Handset",
            ("Android / iOS - Expo runtime", 9.5, "normal", MUTED),
            ("GPS + camera permissions", 9.5, "normal", MUTED)], FILL_LIGHT)
    f.node("guest", 60, 370, 240, 90, "box",
           ["<<device>> Applicant Device",
            ("public careers page only", 9.5, "normal", MUTED)], FILL_LIGHT)

    f.back(rect(400, 78, 430, 500, 8, WHITE, INK, SW, DASH))
    f.back(text(414, 98, "<<node>> APPLICATION SERVER  (Linux VPS)", T_SMALL, "start", "bold", MUTED))

    f.node("nginx", 424, 112, 382, 56, "box",
           ["Nginx  -  TLS termination, :443",
            ("static assets built by Vite", 9.5, "normal", MUTED)])
    f.node("php", 424, 190, 382, 84, "box",
           ["<<artifact>> Laravel 13 (PHP-FPM 8.3)",
            ("all HR modules, RBAC, tenancy,", 9.5, "normal", MUTED),
            ("activity log, notifications, assistant", 9.5, "normal", MUTED)])
    f.node("sched", 424, 296, 382, 52, "box",
           ["<<artifact>> Scheduler (cron)",
            ("daily job-posting auto-close", 9.5, "normal", MUTED)])
    f.node("uvicorn", 424, 370, 382, 84, "box",
           ["<<artifact>> FastAPI + Uvicorn  :8002",
            ("loads 3 joblib pipelines at start-up", 9.5, "normal", MUTED),
            ("bound to 127.0.0.1 - never public", 9.5, "normal", MUTED)])
    f.node("storage", 424, 476, 382, 74, "box",
           ["<<artifact>> Private storage disk",
            ("resumes, 201 documents, selfies", 9.5, "normal", MUTED),
            ("served only through a signed route", 9.5, "normal", MUTED)])

    f.node("pg", 880, 190, 200, 110, "cyl",
           ["<<node>> PostgreSQL",
            (":5432", 9.5, "normal", MUTED),
            ("synapse (69 tables)", 9.5, "normal", MUTED)], FILL_LIGHT)
    f.node("gem", 880, 350, 200, 96, "box",
           ["<<external>> Gemini API",
            ("generativelanguage", 9.5, "normal", MUTED),
            (".googleapis.com :443", 9.5, "normal", MUTED)], WHITE, DASH)
    f.node("mail", 880, 480, 200, 76, "box",
           ["<<external>> SMTP relay",
            ("+ Web Push endpoints", 9.5, "normal", MUTED)], WHITE, DASH)

    f.edge("browser", "nginx", "HTTPS / Inertia", sides=("e", "w"))
    f.edge("phone", "nginx", "HTTPS / JSON + Bearer", sides=("e", "w"))
    f.edge("guest", "nginx", "HTTPS / public form", sides=("e", "w"))
    f.edge("nginx", "php", "FastCGI", sides=("s", "n"))
    f.edge("php", "sched", sides=("s", "n"), head=False, dashed=True)
    f.edge("php", "uvicorn", "HTTP 127.0.0.1:8002", sides=("w", "w"), offset=0,
           via=406, both=True)
    f.edge("php", "pg", "TCP 5432", sides=("e", "w"), both=True)
    f.edge("php", "storage", sides=("e", "e"), via=822, both=True, label="file I/O")
    f.edge("php", "gem", "HTTPS (server-side only)", sides=("e", "w"), dashed=True, both=True)
    f.edge("php", "mail", "SMTP / VAPID", sides=("e", "w"), dashed=True)

    f.front(text(560, 610,
                 "Everything inside the dashed perimeter is institution-owned and can run on one machine. "
                 "The only hard external dependency is the Gemini API,",
                 T_SMALL, "middle", fill=MUTED))
    f.front(text(560, 626,
                 "and it is optional: with no API key configured every AI surface degrades to a disabled panel.",
                 T_SMALL, "middle", fill=MUTED))
    legend(f, 60, 520, [("box", "node / artifact"), ("dashed", "external service"),
                        ("arrow", "protocol binding")], title="LEGEND")
    return "fig-3-2-deployment-diagram.svg", f


# -- Figure 3.3 --------------------------------------------------------------

def fig_request_lifecycle():
    f = Fig(1120, 700, "Figure 3.3  Request Lifecycle and the Enforcement Pipeline",
            "The fixed order of the four gates: identity, tenancy, authorization, then audit")

    xs = 70
    w = 200
    ys = 110
    steps = [
        ("g0", "Request", ["HTTP request", "(web session or", "Bearer token)"], WHITE),
        ("g1", "1  IDENTITY", ["Fortify / Sanctum", "resolves the User;", "2FA + passkey optional"], FILL_LIGHT),
        ("g2", "2  TENANCY", ["SetCurrentOrganization", "resolves the active org", "and validates membership"], FILL_MID),
        ("g3", "3  AUTHORIZATION", ["can:<ability> route gate", "+ policy checks against", "the 73-permission registry"], FILL_LIGHT),
        ("g4", "4  VALIDATION", ["FormRequest rules;", "TenantRule re-scopes", "exists / unique"], WHITE),
    ]
    for i, (k, title, body, fill) in enumerate(steps):
        x = xs + i * (w + 12)
        f.node(k, x, ys, w, 118, "box",
               [(title, 11, "bold", INK)] + [(b, 9.5, "normal", MUTED) for b in body], fill)
        if i:
            f.edge(steps[i - 1][0], k, sides=("e", "w"))

    f.node("action", xs + 100, 300, 420, 74, "box",
           [("MODULE ACTION", 11, "bold", INK),
            ("the canonical support class performs the operation", 9.5, "normal", MUTED),
            ("inside a database transaction", 9.5, "normal", MUTED)], FILL_MID)
    f.edge("g4", "action", sides=("s", "n"), label="permitted")

    f.node("scope", 70, 300, 200, 74, "note",
           [("OrganizationScope", 10, "bold", INK),
            ("global scope filters every", 9, "normal", MUTED),
            ("read; creating() stamps", 9, "normal", MUTED),
            ("organization_id on write", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("scope", "action", sides=("e", "w"), dashed=True, label="applied below the query")

    f.node("log", 620, 300, 200, 74, "box",
           [("ActivityLogger::log()", 10, "bold", INK),
            ("actor, event, subject,", 9, "normal", MUTED),
            ("description, log name", 9, "normal", MUTED)], WHITE)
    f.edge("action", "log", sides=("e", "w"))

    f.node("notify", 860, 300, 200, 74, "box",
           [("Notifier", 10, "bold", INK),
            ("in-app + email + web push", 9, "normal", MUTED),
            ("honouring preferences", 9, "normal", MUTED)], WHITE)
    f.edge("log", "notify", sides=("e", "w"))

    f.node("toast", 380, 430, 300, 66, "box",
           [("Inertia::flash('toast')", 10, "bold", INK),
            ("one of four levels; 202 of the 208", 9, "normal", MUTED),
            ("mutating actions emit one", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("action", "toast", sides=("s", "n"))

    f.node("resp", 380, 528, 300, 56, "box",
           [("Redirect / Inertia response", 10, "bold", INK),
            ("+ SecurityHeaders (CSP w/ nonce, nosniff, X-Frame-Options)", 8.7, "normal", MUTED)])
    f.edge("toast", "resp", sides=("s", "n"))

    f.node("deny", 780, 130, 260, 78, "box",
           [("403 / redirect to switcher", 10, "bold", INK),
            ("a correctly authenticated user still", 9, "normal", MUTED),
            ("cannot enter an organisation they", 9, "normal", MUTED),
            ("are not a member of", 9, "normal", MUTED)], WHITE, DASH)
    f.edge("g3", "deny", sides=("n", "w"), dashed=True, label="denied", label_dy=-8)

    f.front(text(560, 630,
                 "Isolation holds regardless of permission: the tenant filter is applied one level below the query, "
                 "before authorization is ever consulted,",
                 T_SMALL, "middle", fill=MUTED))
    f.front(text(560, 646,
                 "so even a company's most privileged user cannot read another company's rows.",
                 T_SMALL, "middle", fill=MUTED))
    return "fig-3-3-request-lifecycle.svg", f


FIGURES = [fig_architecture, fig_deployment, fig_request_lifecycle]
