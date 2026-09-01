"""Figures 3.25-3.30 - the decision-support algorithms and the ML pipelines."""

from svgkit import (DASH, FILL_LIGHT, FILL_MID, INK, MONO, MUTED, SW, SW_THIN,
                    T_SMALL, T_TINY, WHITE, Fig, legend, line, rect, text,
                    text_block)


def formula(f, x, y, w, lines, h=None, fill=FILL_LIGHT):
    h = h or (18 + 15 * len(lines))
    f.add(rect(x, y, w, h, 4, fill, INK, SW_THIN))
    for i, ln in enumerate(lines):
        f.add(text(x + 14, y + 22 + i * 15, ln, 10, "start", "normal", INK, MONO))
    return y + h


def bar_row(f, x, y, w, label, detail, pts, maxpts, scale=3.0):
    f.add(text(x, y, label, 10.5, "start", "bold"))
    f.add(text(x, y + 14, detail, 9, "start", "normal", MUTED))
    bx = x + 250
    f.add(rect(bx, y - 11, maxpts * scale, 16, 2, WHITE, INK, SW_THIN))
    f.add(rect(bx, y - 11, pts * scale, 16, 2, FILL_MID, INK, SW_THIN))
    f.add(text(bx + maxpts * scale + 10, y + 2, "%d pts max" % maxpts, 9.5, "start",
               "normal", MUTED))


# -- Figure 3.25 --------------------------------------------------------------

def fig_fit_score():
    f = Fig(1100, 760, "Figure 3.25  Decision-Support Algorithm - Recruitment Fit Score",
            "ApplicantScorer: deterministic, explainable, and usable from the moment a candidate applies")

    f.node("in", 50, 90, 250, 150, "box",
           [("INPUTS", 10.5, "bold", INK),
            ("recruiter star rating (0-5)", 9.5, "normal", MUTED),
            ("applicant years of experience", 9.5, "normal", MUTED),
            ("posting min_years_experience", 9.5, "normal", MUTED),
            ("posting required skills[]", 9.5, "normal", MUTED),
            ("headline + notes + cover note", 9.5, "normal", MUTED),
            ("interview results", 9.5, "normal", MUTED),
            ("resume + supporting documents", 9.5, "normal", MUTED)], FILL_LIGHT)

    f.front(text(370, 106, "FIVE WEIGHTED COMPONENTS", 11, "start", "bold"))
    rows = [
        ("Recruiter rating", "points = round(rating / 5 x 30)", 30, 30),
        ("Experience", "ratio = min(years / required, 1); no minimum set -> years / 8", 25, 25),
        ("Skill keyword match", "matched / required, by substring over headline+notes+cover", 20, 20),
        ("Interview outcome", "passed = full, pending = half, failed = 0", 15, 15),
        ("Documents", "resume = 6 pts, then +1 per supporting document, capped at 4", 10, 10),
    ]
    yy = 140
    for label, detail, pts, mx in rows:
        bar_row(f, 370, yy, 600, label, detail, pts, mx, scale=6.0)
        yy += 46

    y = formula(f, 370, 382, 620, [
        "components that cannot be assessed DROP OUT of the calculation",
        "",
        "earned    = SUM(points of assessable components)",
        "available = SUM(max of assessable components)",
        "fit       = round(earned / available x 100)          -> 0 .. 100",
    ])

    f.node("band", 370, y + 22, 620, 62, "box",
           [("BANDS", 10, "bold", INK),
            ("fit >= 75  strong        fit >= 55  promising        fit >= 35  fair        otherwise  weak", 10, "normal", INK)])

    f.node("rec", 370, y + 104, 620, 96, "box",
           [("RECOMMENDED NEXT STEP  (stage x fit x interview verdict)", 10, "bold", INK),
            ("applied     fit >= 55 -> Advance to screening       else -> Screen this candidate", 9.5, "normal", MUTED),
            ("screening   fit >= 55 -> Schedule an interview      else -> Consider rejecting", 9.5, "normal", MUTED),
            ("interview   passed -> Move to offer  |  failed -> Consider rejecting  |  none -> Awaiting result", 9.5, "normal", MUTED),
            ("offer       -> Hire candidate", 9.5, "normal", MUTED)], FILL_LIGHT)

    f.node("why", 50, 270, 250, 200, "note",
           [("WHY NORMALISE OVER WHAT", 9.5, "bold", INK),
            ("IS KNOWN", 9.5, "bold", INK),
            ("", 9, "normal", MUTED),
            ("A candidate who has not been", 9, "normal", MUTED),
            ("interviewed yet is not penalised", 9, "normal", MUTED),
            ("for it. A posting that lists no", 9, "normal", MUTED),
            ("skills simply drops that", 9, "normal", MUTED),
            ("component. Ranking therefore", 9, "normal", MUTED),
            ("works on day one and sharpens", 9, "normal", MUTED),
            ("as recruiters rate and interview.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("comp", 50, 500, 250, 150, "note",
           [("COMPLEXITY", 9.5, "bold", INK),
            ("O(k) per application, where k is", 9, "normal", MUTED),
            ("the number of required skills.", 9, "normal", MUTED),
            ("The score is persisted and", 9, "normal", MUTED),
            ("invalidated by an INPUT HASH,", 9, "normal", MUTED),
            ("not by a list of trigger events,", 9, "normal", MUTED),
            ("so no call site can forget to", 9, "normal", MUTED),
            ("invalidate it.", 9, "normal", MUTED)], FILL_LIGHT)

    f.edge("in", "why", sides=("s", "n"))
    return "fig-3-25-algorithm-fit-score.svg", f


# -- Figure 3.26 --------------------------------------------------------------

def fig_appraisal():
    f = Fig(1140, 800, "Figure 3.26  Decision-Support Algorithm - Two-Level Appraisal Scoring",
            "PerformanceScorer: every line is rated on its own scale, weighted in its section, and sections are weighted against each other")

    f.front(text(60, 92, "LEVEL 1  -  each line's position on ITS OWN scale", 11, "start", "bold"))
    y = formula(f, 60, 104, 500, [
        "fraction = (score - scale_min) / (scale_max - scale_min)   -> 0 .. 1",
        "",
        "numeric      1-5, 1-4, 1-10 with a declared step",
        "percentage   0-100 goal attainment",
        "levels       ordered named levels with behavioural anchors",
    ])

    f.front(text(60, y + 34, "LEVEL 2  -  section attainment, then the overall", 11, "start", "bold"))
    y2 = formula(f, 60, y + 46, 500, [
        "section% = SUM(fraction_i x weight_i) / SUM(weight_i) x 100",
        "                 (only RATED lines contribute)",
        "",
        "overall% = SUM(section%_s x weight_s) / SUM(weight_s)",
        "                 (only sections with something rated)",
        "",
        "result_band   = highest band whose min_percent overall% reaches",
        "overall_score = 1 + (overall% / 100) x 4       <- the 1-5 projection",
    ])

    # Worked example
    f.node("ex", 610, 96, 480, 300, "box",
           [("WORKED EXAMPLE", 10.5, "bold", INK)], WHITE)
    rows = [
        ("Goals  (section weight 60)", "", True),
        ("   Revenue target      0-100 scale, 82        w 60", "0.82", False),
        ("   Delivery on time    1-5 scale, 4           w 40", "0.75", False),
        ("   -> section% = (0.82x60 + 0.75x40)/100 = 79.20", "", True),
        ("Competencies  (section weight 30)", "", True),
        ("   Collaboration       levels L3 of L4        w 100", "0.67", False),
        ("   -> section% = 66.67", "", True),
        ("Values  (section weight 10)  - nothing rated", "", True),
        ("   -> excluded entirely, not scored as zero", "", False),
        ("", "", False),
        ("overall% = (79.20x60 + 66.67x30) / 90 = 75.02", "", True),
        ("result_band = 'Exceeds' (min_percent 75)", "", True),
        ("overall_score = 1 + 0.7502 x 4 = 4.00", "", True),
    ]
    yy = 128
    for txt, frac, bold in rows:
        f.add(text(624, yy, txt, 9.6, "start", "bold" if bold else "normal",
                   INK if bold else MUTED, MONO))
        if frac:
            f.add(text(1076, yy, frac, 9.6, "end", "normal", INK, MONO))
        yy += 20

    f.node("snap", 610, 420, 480, 130, "note",
           [("SNAPSHOTTING - WHY OLD APPRAISALS NEVER CHANGE", 9.5, "bold", INK),
            ("An appraisal freezes the framework it was opened under: name,", 9, "normal", MUTED),
            ("sections and rating model. Each score line freezes its section", 9, "normal", MUTED),
            ("(key, name, weight), its own weight, its description and its full", 9, "normal", MUTED),
            ("rating scale. Retuning a framework, retiring a criterion or editing", 9, "normal", MUTED),
            ("a scale changes the NEXT appraisal and never a past one - and the", 9, "normal", MUTED),
            ("historical result can be rebuilt from the lines alone.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("fall", 60, y2 + 34, 500, 110, "note",
           [("TWO DELIBERATE FALLBACKS", 9.5, "bold", INK),
            ("1. A section that declares no weight falls back to the sum of the", 9, "normal", MUTED),
            ("   weights of the lines inside it - so a flat, unsectioned scorecard", 9, "normal", MUTED),
            ("   scores exactly as a plain weighted average.", 9, "normal", MUTED),
            ("2. The 1-5 overall is an AFFINE PROJECTION of the same 0-100 figure,", 9, "normal", MUTED),
            ("   kept only so the ML pipelines and the awards nominator have one", 9, "normal", MUTED),
            ("   stable meaning of 'overall'. It is a compatibility layer, not a", 9, "normal", MUTED),
            ("   second opinion.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("cal", 60, 610, 1030, 130, "box",
           [("CALIBRATION  -  PerformanceCalibration, computed per review cycle", 10.5, "bold", INK),
            ("coverage against active headcount   |   in-progress and awaiting-sign-off counts   |   average attainment", 9.5, "normal", MUTED),
            ("band spread across the tenant's OWN bands (grouped by the words they were actually reported in, because a", 9.5, "normal", MUTED),
            ("cycle may run several frameworks at once)   |   per-department deviation from the cycle average", 9.5, "normal", MUTED),
            ("", 9.5, "normal", MUTED),
            ("Rating inflation is invisible one scorecard at a time and obvious the moment the distribution is on screen.", 9.5, "normal", INK)], WHITE)
    return "fig-3-26-algorithm-appraisal-scoring.svg", f


# -- Figure 3.27 --------------------------------------------------------------

def fig_award():
    f = Fig(1180, 800, "Figure 3.27  Decision-Support Algorithm - Award Nomination",
            "AwardNominator: a focus profile per award type, six signals, and a fairness guard")

    f.node("cls", 50, 92, 300, 176, "box",
           [("STEP 1  -  CLASSIFY THE AWARD TYPE", 10, "bold", INK),
            ("keyword search over name + description", 9, "normal", MUTED),
            ("", 9, "normal", MUTED),
            ("attendance  attendance, punctual, tardi,", 9, "normal", MUTED),
            ("            presence, reliab", 9, "normal", MUTED),
            ("tenure      service, tenure, loyal, anniversar,", 9, "normal", MUTED),
            ("            milestone, veteran", 9, "normal", MUTED),
            ("growth      training, learn, development, certif", 9, "normal", MUTED),
            ("performance performance, excellen, outstand, mvp", 9, "normal", MUTED),
            ("first match wins; unmatched -> all-round", 9, "normal", INK)], FILL_LIGHT)

    # weights table
    f.front(text(390, 106, "STEP 2  -  WEIGHTS PER FOCUS PROFILE  (each row sums to 100 before normalisation)",
                 10.5, "start", "bold"))
    cols = ["PROFILE", "PERF", "FCST", "ATT", "TRAIN", "TENURE", "GAP"]
    rows = [
        ["Performance", "40", "10", "15", "10", "-", "25"],
        ["Attendance", "15", "-", "55", "-", "10", "20"],
        ["Tenure", "15", "-", "10", "-", "55", "20"],
        ["Growth", "20", "-", "10", "50", "-", "20"],
        ["All-round", "30", "10", "15", "10", "10", "25"],
    ]
    tx, ty, cw = 390, 118, [150, 70, 70, 70, 80, 90, 70]
    f.add(rect(tx, ty, sum(cw), 26, 0, FILL_MID, INK, SW_THIN))
    cx = tx
    for i, c in enumerate(cols):
        f.add(text(cx + cw[i] / 2, ty + 17, c, 9.5, "middle", "bold"))
        cx += cw[i]
    for r, row in enumerate(rows):
        yy = ty + 26 + r * 24
        f.add(rect(tx, yy, sum(cw), 24, 0, WHITE, INK, SW_THIN))
        cx = tx
        for i, v in enumerate(row):
            f.add(text(cx + cw[i] / 2, yy + 16, v, 9.5, "middle",
                       "bold" if i == 0 else "normal"))
            cx += cw[i]

    f.front(text(390, 300, "STEP 3  -  THE SIX SIGNALS, EACH NORMALISED TO 0 .. 1", 10.5, "start", "bold"))
    y = formula(f, 390, 312, 740, [
        "Performance   (latest submitted appraisal overall - 1) / 4",
        "Forecast      ML predicted rating / 100                     (only when a forecast run exists)",
        "Attendance    over the last 90 days:  (present + 0.75 x (late + undertime)) / workdays",
        "              leave days, days off and holidays are excused entirely",
        "Training      completions in the last 12 months / 3, capped at 1",
        "              refined as  base x 0.7 + (average score / 100) x 0.3",
        "Tenure        years of service / 10, capped at 1",
        "Gap           months since last recognised / 12, capped at 1;  never recognised = full marks",
    ])

    y = formula(f, 390, y + 16, 740, [
        "score = SUM(signal x weight) over ASSESSABLE signals / SUM(weight of those signals) x 100",
        "        a signal with nothing to assess drops out and the rest renormalise",
        "bands:  >= 75 strong   >= 55 promising   >= 35 fair   otherwise weak      shortlist = top 5",
    ], fill=FILL_MID)

    f.node("fair", 50, 300, 300, 190, "note",
           [("STEP 4  -  THE FAIRNESS GUARD", 9.5, "bold", INK),
            ("", 9, "normal", MUTED),
            ("Winning the SAME award within the", 9, "normal", MUTED),
            ("last six months zeroes the", 9, "normal", MUTED),
            ("recognition-gap signal outright and", 9, "normal", MUTED),
            ("flags the nominee on the board.", 9, "normal", MUTED),
            ("", 9, "normal", MUTED),
            ("The board therefore spreads", 9, "normal", MUTED),
            ("recognition rather than crowning the", 9, "normal", MUTED),
            ("same person twice - which is what a", 9, "normal", MUTED),
            ("purely score-ranked list would do.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("cit", 50, 520, 300, 180, "note",
           [("STEP 5  -  CITATION DRAFT (LLM)", 9.5, "bold", INK),
            ("", 9, "normal", MUTED),
            ("Gemini receives the employee, the", 9, "normal", MUTED),
            ("award type and THAT NOMINEE'S REAL", 9, "normal", MUTED),
            ("SIGNAL BREAKDOWN, and returns a warm,", 9, "normal", MUTED),
            ("specific one-to-two sentence citation.", 9, "normal", MUTED),
            ("", 9, "normal", MUTED),
            ("It is a draft the granter edits. It is", 9, "normal", MUTED),
            ("never persisted on its own, and it", 9, "normal", MUTED),
            ("never changes the ranking.", 9, "normal", MUTED)], FILL_LIGHT)

    f.front(text(760, 748,
                 "The whole ranking is arithmetic. The only LLM involvement is writing the words, after the decision is already made.",
                 T_SMALL, "middle", fill=MUTED))
    return "fig-3-27-algorithm-award-nomination.svg", f


# -- Figure 3.28 --------------------------------------------------------------

def fig_attendance_algo():
    f = Fig(1120, 780, "Figure 3.28  Decision-Support Algorithm - Attendance Derivation",
            "AttendanceCalculator: two tables, one calculator, and a state machine over the punch stream")

    f.node("t1", 50, 92, 300, 120, "box",
           [("attendance_punches  (raw, append-only)", 10, "bold", INK),
            ("clock_in - clock_out - break_start - break_end", 9, "normal", MUTED),
            ("each with timestamp, source (web / mobile /", 9, "normal", MUTED),
            ("kiosk / biometric / manual), GPS, optional", 9, "normal", MUTED),
            ("selfie, and who recorded it", 9, "normal", MUTED)], FILL_LIGHT)
    f.node("t2", 50, 236, 300, 108, "box",
           [("attendance_records  (derived, one per day)", 10, "bold", INK),
            ("worked / break / late / undertime / overtime", 9, "normal", MUTED),
            ("minutes, status, and a SNAPSHOT of the", 9, "normal", MUTED),
            ("schedule that applied that day", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("t1", "t2", sides=("s", "n"), label="recompute()")

    f.front(text(400, 108, "THE STATE MACHINE  (walk the punches in chronological order)", 10.5, "start", "bold"))
    y = formula(f, 400, 120, 670, [
        "on_clock = false ; on_break = false ; worked = 0 ; break = 0",
        "for each punch, in order:",
        "    delta = minutes since the previous punch",
        "    if on_clock and on_break :  break  += delta",
        "    elif on_clock            :  worked += delta",
        "    clock_in    -> on_clock = true",
        "    clock_out   -> on_clock = false ; on_break = false",
        "    break_start -> on_break = true",
        "    break_end   -> on_break = false",
    ])

    y = formula(f, 400, y + 16, 670, [
        "late_minutes      = max(0, first_in - (scheduled_start + grace_minutes))",
        "undertime_minutes = max(0, scheduled_end - last_out)      when they left early",
        "overtime_minutes  = max(0, worked - required_hours x 60)",
    ])

    f.front(text(400, y + 40, "STATUS RESOLUTION  (first match wins)", 10.5, "start", "bold"))
    y = formula(f, 400, y + 52, 670, [
        "no punches and an approved leave covers the day  ->  on_leave",
        "no punches and the weekday is not in work_days   ->  day_off",
        "no punches otherwise                             ->  absent",
        "clocked in but never out                         ->  incomplete",
        "late_minutes > 0                                 ->  late",
        "undertime_minutes > 0                            ->  undertime",
        "otherwise                                        ->  present",
    ], fill=FILL_MID)

    formula(f, 400, y + 16, 670, [
        "attendance_rate = present days / scheduled days x 100     (null when nothing was scheduled)",
    ])

    f.node("n1", 50, 380, 300, 160, "note",
           [("THREE DESIGN DECISIONS", 9.5, "bold", INK),
            ("1. Arithmetic runs on UNIX timestamps,", 9, "normal", MUTED),
            ("   so it is agnostic to mutable vs", 9, "normal", MUTED),
            ("   immutable date objects.", 9, "normal", MUTED),
            ("2. Leave awareness reuses the leave", 9, "normal", MUTED),
            ("   module's own coverage check, so an", 9, "normal", MUTED),
            ("   approved leave day is never absent.", 9, "normal", MUTED),
            ("3. The roster is built from EVERY", 9, "normal", MUTED),
            ("   employee, so a person with no", 9, "normal", MUTED),
            ("   punches appears, never vanishes.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("n2", 50, 566, 300, 130, "note",
           [("ONE CANONICAL WRITER", 9.5, "bold", INK),
            ("AttendanceClock is the only path that", 9, "normal", MUTED),
            ("ever writes a punch. The web screen,", 9, "normal", MUTED),
            ("the mobile API and the AI assistant", 9, "normal", MUTED),
            ("all call it, so a punch made on a", 9, "normal", MUTED),
            ("phone computes identically to one made", 9, "normal", MUTED),
            ("at a desktop.", 9, "normal", MUTED)], FILL_LIGHT)
    return "fig-3-28-algorithm-attendance.svg", f


# -- Figure 3.29 --------------------------------------------------------------

def fig_ml_training():
    f = Fig(1200, 820, "Figure 3.29  Machine Learning Training Pipeline",
            "One shared preprocessing recipe, three estimators, and a recall-oriented threshold sweep")

    steps = [
        ("s1", "Acquire and freeze dataset", ["read-only copy; public corpus or", "schema-native partner extract"]),
        ("s2", "Profile", ["shape, nulls, duplicates, outliers,", "target distribution, class balance"]),
        ("s3", "Feature selection", ["restrict to ERP-SERVABLE columns;", "drop protected attributes; drop leaks"]),
        ("s4", "Partition", ["80 / 20 stratified on the target;", "the test split is withheld until the end"]),
    ]
    x = 50
    for k, t, sub in steps:
        f.node(k, x, 92, 262, 96, "box",
               [(t, 10.5, "bold", INK)] + [(s, 9, "normal", MUTED) for s in sub])
        x += 278
    for a, b in zip([s[0] for s in steps], [s[0] for s in steps][1:]):
        f.edge(a, b, sides=("e", "w"))

    # Pipeline block
    f.back(rect(50, 224, 640, 250, 6, WHITE, INK, SW, DASH))
    f.front(text(64, 244, "THE SHARED scikit-learn PIPELINE  (fitted on TRAIN only)",
                 T_SMALL, "start", "bold", MUTED))

    f.node("num", 70, 260, 280, 76, "box",
           [("numeric branch", 10, "bold", INK),
            ("SimpleImputer(strategy='median')", 9, "normal", MUTED),
            ("-> StandardScaler()", 9, "normal", MUTED)])
    f.node("cat", 370, 260, 300, 76, "box",
           [("categorical branch", 10, "bold", INK),
            ("SimpleImputer(strategy='most_frequent')", 9, "normal", MUTED),
            ("-> OneHotEncoder(handle_unknown='ignore')", 8.6, "normal", MUTED)])
    f.node("ct", 70, 358, 600, 42, "box",
           [("ColumnTransformer", 10, "bold", INK)], FILL_LIGHT)
    f.node("est", 70, 414, 600, 46, "box",
           [("estimator: RandomForestClassifier | HistGradientBoostingRegressor | LogisticRegression", 10, "bold", INK)],
           FILL_MID)
    f.edge("num", "ct", sides=("s", "n"))
    f.edge("cat", "ct", sides=("s", "n"))
    f.edge("ct", "est", sides=("s", "n"))
    f.edge("s4", "num", sides=("s", "n"))

    right = [
        ("r1", "Cross-validate", ["stratified 5-fold on the training split;", "class imbalance handled by cost-sensitive", "learning (balanced class weights)"]),
        ("r2", "Tune", ["grid search inside the CV loop;", "then a RECALL-ORIENTED decision-threshold", "sweep instead of defaulting to 0.5"]),
        ("r3", "Evaluate once", ["on the untouched 20% test split;", "ROC-AUC / PR-AUC / recall / F1 for", "classifiers, MAE / RMSE / R2 for the regressor"]),
        ("r4", "Persist", ["joblib pipeline + metrics.json +", "feature_contract.json + evaluation plots,", "all timestamped and versioned"]),
    ]
    yy = 240
    for k, t, sub in right:
        f.node(k, 730, yy, 420, 82, "box",
               [(t, 10.5, "bold", INK)] + [(s, 9, "normal", MUTED) for s in sub])
        yy += 98
    f.edge("est", "r1", sides=("e", "w"))
    for a, b in zip([r[0] for r in right], [r[0] for r in right][1:]):
        f.edge(a, b, sides=("s", "n"))

    formula(f, 50, 500, 640, [
        "Why the pipeline is the deployment artefact, not just a training convenience:",
        "",
        "  * the IMPUTERS ARE FITTED INSIDE it, so the service can accept a feature",
        "    dictionary with holes and the pipeline fills them itself;",
        "  * handle_unknown='ignore' NEUTRALISES an unseen category (a department name",
        "    the model never met) rather than raising - a safe failure, but a silent one;",
        "  * the exact column order is preserved, so a partial input is aligned, not",
        "    positionally misread.",
    ])

    f.node("thr", 50, 664, 640, 100, "box",
           [("TUNED THRESHOLDS PERSISTED WITH THE ARTEFACTS", 10.5, "bold", INK),
            ("In HR the costly error is the false negative, so the threshold is chosen for recall on the minority class,", 9, "normal", MUTED),
            ("not left at 0.5. Attrition lands at 0.2887 (recall 0.617 on leavers at 0.403 precision);", 9, "normal", MUTED),
            ("promotion lands at 0.8347 (recall 0.635 at 0.615 precision). Both thresholds ship inside metrics.json.", 9, "normal", MUTED)], FILL_LIGHT)

    f.node("fair", 730, 664, 420, 100, "note",
           [("FAIRNESS IS IN THE DATA CONTRACT", 9.5, "bold", INK),
            ("Gender, marital status, business travel and all survey-only", 9, "normal", MUTED),
            ("satisfaction scores are excluded at TRAINING time. That cost about", 9, "normal", MUTED),
            ("0.06 ROC-AUC and bought an input contract that matches live ERP data", 9, "normal", MUTED),
            ("and is defensible. The serving side reinforces it with a", 9, "normal", MUTED),
            ("PROTECTED_FEATURES set that is never surfaced as a decision factor.", 9, "normal", MUTED)], FILL_LIGHT)
    return "fig-3-29-ml-training-pipeline.svg", f


# -- Figure 3.30 --------------------------------------------------------------

def fig_ml_serving():
    f = Fig(1200, 800, "Figure 3.30  Machine Learning Serving Path and the Division of Labour",
            "What the Python service returns, and what the application server derives on top of it")

    lanes = [("Laravel (application server)", 84, 300), ("FastAPI (inference service)", 396, 190),
             ("PostgreSQL", 606, 150)]
    for label, y, h in lanes:
        f.back(rect(40, y, 1120, h, 4, WHITE, INK, SW_THIN, DASH))
        f.back(text(52, y + 16, label, T_SMALL, "start", "bold", MUTED))

    f.node("a1", 60, 112, 200, 74, "box",
           [("Assessor", 10, "bold", INK), ("gathers the active cohort", 9, "normal", MUTED),
            ("with SQL aggregates", 9, "normal", MUTED)])
    f.node("a2", 286, 112, 200, 74, "box",
           [("FeatureMapper", 10, "bold", INK), ("ERP vocabulary ->", 9, "normal", MUTED),
            ("model vocabulary", 9, "normal", MUTED)])
    f.node("a3", 512, 112, 200, 74, "box",
           [("MlClient", 10, "bold", INK), ("POST /predict/{model}", 9, "normal", MUTED),
            ("bounded timeout", 9, "normal", MUTED)])
    f.node("a4", 738, 112, 200, 74, "box",
           [("Derivation", 10, "bold", INK), ("tier / band / confidence", 9, "normal", MUTED),
            ("/ trajectory", 9, "normal", MUTED)], FILL_LIGHT)
    f.node("a5", 964, 112, 180, 74, "box",
           [("Run writer", 10, "bold", INK), ("one transaction", 9, "normal", MUTED)])
    for a, b in [("a1", "a2"), ("a2", "a3"), ("a3", "a4"), ("a4", "a5")]:
        f.edge(a, b, sides=("e", "w"))

    f.node("a6", 60, 216, 426, 60, "box",
           [("FEATURE MAPPING - notable translations", 10, "bold", INK),
            ("never promoted -> years-in-role and years-since-promotion fall back to tenure", 8.6, "normal", MUTED),
            ("overtime -> a Yes/No flag: 90-day approved overtime sum thresholded at 120 minutes", 8.6, "normal", MUTED)], FILL_LIGHT)
    f.node("a7", 512, 216, 632, 60, "box",
           [("", 10, "bold", INK),
            ("performance rating clamped into the range the training data contained (1-4)", 8.6, "normal", MUTED),
            ("employment type mapped regular/probationary/part-time/contractual -> Full-time/Part-time/Contract", 8.6, "normal", MUTED),
            ("anything ungroundable is OMITTED, never sent as a zero - a zero is a claim, an absence is honest", 8.6, "normal", MUTED)], FILL_LIGHT)

    f.node("b1", 60, 424, 260, 66, "box",
           [("Align", 10, "bold", INK),
            ("full-width DataFrame in the", 9, "normal", MUTED),
            ("pipeline's exact column order", 9, "normal", MUTED)])
    f.node("b2", 346, 424, 260, 66, "box",
           [("Impute + transform", 10, "bold", INK),
            ("the fitted imputers fill the holes", 9, "normal", MUTED)])
    f.node("b3", 632, 424, 240, 66, "box",
           [("Score", 10, "bold", INK),
            ("predict_proba | predict", 9, "normal", MUTED)])
    f.node("b4", 898, 424, 246, 66, "box",
           [("Decompose (linear only)", 10, "bold", INK),
            ("signed per-feature logit", 9, "normal", MUTED),
            ("contributions", 9, "normal", MUTED)], FILL_LIGHT)
    for a, b in [("b1", "b2"), ("b2", "b3"), ("b3", "b4")]:
        f.edge(a, b, sides=("e", "w"))
    f.edge("a3", "b1", sides=("s", "n"), label="{ instances: [ { ref, features } ] }")
    f.edge("b4", "a4", sides=("n", "s"), label="{ probability, score, tier, factors }")

    f.node("c1", 60, 636, 520, 100, "box",
           [("*_runs   (header)", 10, "bold", INK),
            ("who ran it, when, model_version, tier counts, average score,", 9, "normal", MUTED),
            ("average confidence", 9, "normal", MUTED)], FILL_LIGHT)
    f.node("c2", 620, 636, 524, 100, "box",
           [("*_scores   (one row per employee)", 10, "bold", INK),
            ("probability, score, tier or band, confidence or factors, and the", 9, "normal", MUTED),
            ("EXACT FEATURE SNAPSHOT that was sent", 9, "normal", MUTED)], FILL_LIGHT)
    f.edge("a5", "c2", sides=("s", "n"))
    f.edge("a5", "c1", sides=("s", "n"))

    # Division of labour table
    f.front(text(40, 780,
                 "Promotion returns probability, score, tier AND real per-feature factors (the model is linear, so the 'why' is genuine).  "
                 "Attrition returns probability, score, tier - confidence is derived.  Performance returns a predicted value only - band and confidence are both derived.",
                 T_TINY, "start", fill=MUTED))
    return "fig-3-30-ml-serving-path.svg", f


FIGURES = [fig_fit_score, fig_appraisal, fig_award, fig_attendance_algo,
           fig_ml_training, fig_ml_serving]
