# CHAPTER III
# METHODOLOGY

> **About this revision.** This is a rewritten Chapter III for *SYNAPSE: A Multi-Model ML and
> LLM HR ERP for Predictive Analytics and Recruitment*. It preserves every section the original
> chapter contained and adds the design sections it was missing — a context diagram, a Level-1
> data flow diagram, thirteen module-level Level-2 data flow diagrams, six process flowcharts,
> explicit algorithm specifications for every decision-support surface, a use case model, record
> lifecycle diagrams, an interaction diagram, a security design, and an interface design. It also
> corrects the factual mismatches between the written chapter and the implemented system. The
> corrections are itemised in *Appendix 3-A — Revision Log*.
>
> **Tense convention.** Research procedures that are still to be carried out are written in the
> future tense ("the questionnaire will be administered"). The design of the artefact is written
> in the present tense, which is the normal convention for describing a designed system
> ("the architecture separates…"). The original chapter mixed the two inside single paragraphs;
> this revision applies the rule consistently.

---

## 3.1 Research Design

This study adopts a **developmental research design**, determined directly by the nature of the
research problem: Philippine organizations lack a unified HR system that integrates transactional
operations, predictive workforce analytics, and intelligent document handling into a single
platform. Because the solution must be *built* rather than observed or experimentally manipulated,
developmental research is the appropriate design. It supplies the blueprint for how the study's
components are collected, measured, and analyzed: requirements are gathered from documented HR
practice and expert consultation with an HR practitioner from the pilot organization, Mega Plywood
Corporation; the system is built iteratively against those requirements; the machine learning
models are trained and evaluated on HR datasets; and the finished system is assessed for
acceptability by domain-qualified evaluators. Every activity traces to a specific objective in
Chapter 1, and that tracing is made explicit in the traceability matrix in Section 3.12.4, so that
all deliverables — the nine HR modules and their supporting subsystems, the three predictive
models, the LLM assistant, and the evaluation results — demonstrably address the stated problem.

The evaluation phase is complemented by a **descriptive quantitative approach**, because the
quality of the finished system and the predictive accuracy of its models are both measurable
numerically. Model performance is reported through metric values compared against acceptance
thresholds defined in advance (Section 3.12.2), and system acceptability is computed as weighted
means across ISO/IEC 25010 quality characteristics and interpreted against a defined Likert scale
(Section 3.12.3), so that findings are objective, reproducible, and comparable against related work.

Three design decisions distinguish this study's methodology from the related work reviewed in
Chapter 2 and are therefore treated as methodological contributions rather than implementation
detail:

1. **A schema-native feature contract.** Each predictive model is restricted, at *training* time,
   to only those attributes the deploying ERP can actually supply at *inference* time
   (Section 3.6.5). This eliminates the train–serve feature mismatch that arises when a model is
   trained on an external dataset whose structure does not align with the deploying system's data
   model, and it is the mechanism through which fairness constraints are enforced.
2. **Separation of decision from enforcement in the LLM layer.** The language model decides which
   action to take; the application module enforces permission, tenancy, validation, audit, and
   notification (Section 3.7.11). No capability reaches the model that the requesting user does
   not already hold.
3. **Prediction as an auditable run, not a column.** No predictive score is ever written onto an
   employee record. Each assessment is persisted as a run header plus one score row per employee,
   each row carrying a snapshot of the exact feature vector that produced it (Section 3.7.10), so
   that an old prediction can be audited on what the model was *told*, not only on what it said.

---

## 3.2 Data Sources

This study draws data from both primary and secondary sources, corresponding to its three data
needs: **requirements**, **modeling**, and **evaluation**.

### 3.2.1 Primary sources

**Requirements.** At least one HR practitioner from the pilot organization, Mega Plywood
Corporation, will be consulted through a semi-structured interview. The participant is selected
through purposive sampling, as the study requires a respondent with direct working knowledge of
institutional HR processes rather than a statistically representative sample. Inclusion criteria:
currently employed as an HR manager or HR officer, with at least one year of active experience
managing HR operations in a Philippine institution. This source supplies the process validation
data needed to confirm that the system's module design reflects actual HR practice.

**Modeling.** The preferred source is real operational HR records contributed by one or more
collaborating companies under a signed data-sharing agreement, collected according to the
**schema-native approach** described in Section 3.1. Under this approach the feature contract for
each predictive model is defined directly by SYNAPSE's own database schema — specifically, the
fields the system actively populates for every employee across the attendance, leave, performance,
training, and employment tables. Partner companies contribute HR records mapped to these exact
schema fields, so that the attributes used during training are identical to the attributes the
system supplies at inference. All contributed records are anonymized, de-identified, and cleaned
by the researchers before use. This arrangement is negotiated independently of the pilot-testing
arrangement with Mega Plywood Corporation, so modeling data does not depend on any single
company's participation.

**Evaluation.** A panel of five expert evaluators will be drawn from Mega Plywood Corporation
through purposive sampling. The panel is composed of HR managers or officers with direct process
knowledge, department heads with supervisory experience, and IT professionals with relevant
technical background. Inclusion criteria: familiarity with HR operations or information systems,
and ability to perform scripted task scenarios on the developed system. A small, expert-selected
sample is appropriate because the study seeks domain-qualified feedback rather than statistically
generalizable results; the panel size follows the convention, established for expert evaluation of
interactive systems, that a small number of domain experts surfaces the majority of usability and
quality issues. Because that convention was established for *heuristic usability* evaluation and
not for general software-quality judgement, the findings from this panel are reported
descriptively per quality characteristic and are explicitly **not** generalised to the population
of Philippine HR practitioners (Section 3.12.3).

### 3.2.2 Secondary sources

Two publicly available, fully anonymized HR datasets serve as the generic benchmark and the
fallback training corpus should no schema-native partner data be secured.

| Dataset | Records | Role in this study | Class balance |
|---|---|---|---|
| IBM HR Analytics Employee Attrition and Performance | 1,470 employees, 35 attributes | Attrition classification; **only the 17 attributes that overlap the ERP feature contract are used** | ≈16.1 % positive (Attrition = Yes) |
| Employees Evaluation for Promotion | 100,000 employees | Performance regression (continuous score target) and promotion classification (binary target) | ≈10.0 % positive (promoted) |

Protected attributes — gender and marital status — are excluded from the attrition feature set as
a fairness measure, together with business-travel and survey-only satisfaction attributes that the
ERP does not collect (Section 3.6.5). Both datasets are publicly available under open licenses and
contain no personally identifiable information.

These datasets provide sufficient volume for stable initial training and allow the models to be
benchmarked against comparable literature. They were, however, **not collected from Philippine
institutions**, and their feature structures do not perfectly align with SYNAPSE's schema. They
are therefore treated as a fallback and benchmark rather than the primary modeling source, and the
external-validity limitation this creates is addressed explicitly in the evaluation protocol
(Section 3.12.2.3) rather than left implicit.

---

## 3.3 Data Gathering Instruments

Four instruments correspond to the types of data collected.

**3.3.1 Semi-structured interview guide.** A researcher-developed guide used during the
consultation with the HR practitioner. It covers current HR processes, pain points of manual or
fragmented practice, validation of the proposed modules, and expectations for predictive
analytics. The guide will be reviewed and approved by the research adviser before use. Responses
will be audio-recorded with the participant's consent to ensure accuracy during thematic analysis.

**3.3.2 Document analysis matrix.** A structured matrix organizing findings extracted from
documented Philippine HR practice — 201-file management procedures, daily time record formats,
leave administration guidelines, performance appraisal forms, training records, and separation
clearance checklists. The matrix maps each documented process to a corresponding system module and
functional requirement, ensuring traceability between the collected requirements and the
deliverables.

**3.3.3 Experiment logging harness and Jupyter notebooks.** For modeling data, the instruments are
the project's notebooks paired with a shared experiment logging harness. The harness captures
library versions, dataset shapes, null counts, feature distributions, cross-validation scores,
test metrics, tuned decision thresholds, and model artifact paths for every training run. The
notebooks are themselves generated from a single reviewable Python source file, so any run can be
rebuilt exactly. Each completed run emits three persisted artifacts — the fitted pipeline, a
`metrics.json`, and a `feature_contract.json` naming the exact columns, numeric/categorical split,
and category levels the model was trained on — which together make every experiment reproducible
and auditable.

**3.3.4 ISO/IEC 25010-based evaluation questionnaire.** A researcher-adapted instrument based on
the ISO/IEC 25010 product quality model. It covers five of the model's quality characteristics —
functional suitability, performance efficiency, usability, reliability, and security — each
measured through multiple indicator statements rated on a five-point Likert scale where 5 means
Strongly Agree and 1 means Strongly Disagree. **The subset is deliberate and is justified rather
than assumed:** compatibility and portability are not evaluated because the deployment target is a
single institution-owned server with a fixed technology stack, and maintainability is not
evaluated by the expert panel because it is not observable through scripted end-user task
scenarios; it is instead evidenced by the automated test suite and static-analysis gate reported
in Section 3.12.1. The questionnaire will be validated for content by IT experts and the research
adviser prior to administration.

---

## 3.4 Data Gathering Technique and Procedures

The researchers collect and prepare data through the following sequence.

| Step | Activity | Output |
|---|---|---|
| 1 | **Document analysis.** Analyze documented Philippine HR processes and the related systems from Chapter 2; draft the module list and functional requirements. | Draft requirement specification |
| 2 | **Expert consultation.** Conduct the scheduled semi-structured interview with the HR practitioner to validate, refine, or supplement the drafted requirements before the design is finalized. | Validated requirement specification |
| 3 | **Dataset acquisition and partner-data cleaning.** Subject to a signed data-sharing agreement, export anonymized operational HR records, transfer them through a secure channel, and store them read-only. Clean by standardizing formats, resolving missing entries, de-duplicating, and stripping all identifiers. Download each public dataset once and treat it as read-only fallback. | Frozen, read-only corpora |
| 4 | **Quality assessment.** Profile each dataset for shape, missing values, duplicates, outliers, target distribution, and class imbalance. | Profiling report + plots |
| 5 | **Feature selection and leakage control.** Restrict each model to ERP-servable features only; exclude protected attributes; remove leaky predictors (the salary-increase attribute is dropped from the promotion model, and the promotion outcome is dropped from the performance model). | `feature_contract.json` per model |
| 6 | **Preprocessing.** Bundle imputation, scaling, and encoding into a single scikit-learn `Pipeline` fitted exclusively on training data, so no transformation learns from the test partition. | Fitted preprocessing pipeline |
| 7 | **Partitioning.** Split 80 % training / 20 % held-out test, stratified on the target for classifiers; the test partition is withheld until final evaluation. | Train and test partitions |
| 8 | **Cross-validation and imbalance handling.** Stratified 5-fold cross-validation on the training partition; class imbalance addressed through cost-sensitive learning with balanced class weights. | CV scores |
| 9 | **Hyperparameter and threshold tuning.** Tune key hyperparameters by grid search *inside* the CV loop; tune each classifier's decision threshold with a recall-oriented sweep rather than defaulting to 0.5; persist the final thresholds with the artifacts. | Tuned model + threshold |
| 10 | **Persistence and versioning.** Persist the fitted pipeline, metrics file, evaluation plots, and feature contract for every run with timestamped logs. | Versioned artifacts |
| 11 | **System evaluation.** Administer the ISO/IEC 25010-based questionnaire to the expert panel upon system completion and analyze responses through descriptive statistics. | Acceptability results |

Ethical considerations observed throughout this sequence are stated in Section 3.13.

---

## 3.5 Data Analysis

Analysis proceeds on four strands.

**Strand 1 — Requirements (qualitative).** Responses from the expert consultation are analyzed
thematically; the resulting themes are used to refine the requirement specification, and each
refinement is recorded in the document analysis matrix so the change is traceable to its source.

**Strand 2 — Exploratory data analysis.** Each modeling dataset is characterized in terms of
distributions, correlations, missing values, and class balance. Findings feed directly into the
modeling decisions of Section 3.6.

**Strand 3 — Model performance.** Classifiers are evaluated on **ROC-AUC** and **PR-AUC** as
headline metrics, because plain accuracy is misleading at 16 % and 10 % positive rates,
supplemented by precision, recall, F1, and the confusion matrix *at the tuned threshold*. The
regressor is evaluated with MAE, RMSE, and R². Each model is compared against a naive baseline —
the majority-class predictor for classifiers, the mean predictor for the regressor — to
demonstrate genuine learned signal. Explainability is analyzed through permutation feature
importance for the tree ensembles and through signed per-feature contribution decomposition for
the promotion model, so that an individual score can be explained to the person it concerns.

**Strand 4 — Acceptability.** The weighted mean of each ISO/IEC 25010 characteristic is computed
and interpreted against the Likert ranges defined in Section 3.12.3, supplemented by the
evaluators' written comments. Because the panel is small and expert-selected, findings are
reported descriptively rather than validated through inferential reliability statistics.

---

## 3.6 Software Development

### 3.6.1 Development approach

SYNAPSE is developed using an **Agile iterative and incremental** methodology, selected for three
reasons. First, the system decomposes naturally into cohesive, independently deliverable modules,
so development proceeds as a sequence of working increments rather than one large release.
Second, the predictive and LLM capabilities require continuous feedback and refinement as models
and prompts mature — an iterative cycle supports this and a linear model such as Waterfall does
not. Third, delivering a working increment at the end of every iteration reduces the risk of scope
overrun, because the system remains usable even if later increments are trimmed.

Each iteration passes through planning, design, implementation, automated testing, review, and
refinement. Every increment is additionally required to leave the system **green**: the automated
suite must pass before the increment is considered done (Section 3.12.1). Architecturally
significant decisions are recorded as numbered **Architecture Decision Records (ADRs)**, each
stating the context, the decision, and its consequences, and each superseding decision explicitly
names the record it replaces. Twenty-eight such records govern the design described in Section 3.7;
they are the audit trail of *why* the system is shaped the way it is, and they are what allows a
later reader to distinguish a deliberate constraint from an accident.

### 3.6.2 Increment backlog and dependency order

The backlog is ordered by dependency, not by visibility.

| Phase | Increment | Depends on |
|---|---|---|
| 0 | Foundation: authentication, roles and permissions, row-level multi-tenancy, activity logging, notifications | — |
| 1 | Company Setup: departments and positions, work schedules, holidays, leave types, award types | Phase 0 |
| 2 | Employee 201 file, documents, certifications, career history | Phase 1 |
| 3 | Recruitment and applicant tracking; the hire bridge | Phase 2 |
| 4 | Onboarding programs and cases | Phase 3 |
| 5 | Attendance (web + mobile API) and Leave | Phase 1, 2 |
| 6 | Performance frameworks and appraisals; Training; Awards; Events | Phase 2, 5 |
| 7 | Offboarding and clearance | Phase 2 |
| 8 | ML inference service and the three analytics surfaces | Phase 5, 6 |
| 9 | LLM assistant and the AI insight writers | Phase 3–8 |
| 10 | Dashboard, Reports, exports | all |
| 11 | Mobile companion application | Phase 5 |
| 12 | Self-served identity, invitations, workspace join | Phase 0, 2 |

### 3.6.3 Engineering conventions treated as design invariants

Five conventions are applied uniformly across every module. They are stated here because they are
*design decisions with consequences*, not coding style, and because several later design sections
are only intelligible in their light.

1. **Derive, do not store.** If a value is computable from other rows it is generally not
   persisted. A training programme's status comes from its dates; an event's status from its time
   window; an onboarding case's progress from its tasks; a leave balance's *used* figure from its
   approved requests; an offboarding case's clearance status from its items. Stored duplicates
   drift; derived values cannot.
2. **One canonical writer per operation.** Booking an interview always goes through one class;
   punching a clock always goes through one class; generating an employee number always goes
   through one class. The web controller, the mobile API, and the AI assistant all call the same
   class, so the three surfaces cannot diverge. Wherever a diagram in Section 3.7 names a class,
   it is naming the sole writer of that operation.
3. **Every mutation is logged.** A single audit-logging call records the actor, event, subject
   record, and description. The Activity Logs screen and the Audit Trail report are views over
   that one table.
4. **Every mutation gives feedback.** A flash message is attached to the redirect and rendered by
   the front end at one of four levels. Of 208 mutating actions, 202 emit one; the six that stay
   silent do so deliberately.
5. **Archive before delete.** Most tables soft-delete. A Trash Bin screen restores or permanently
   removes them, and permanent deletion is blocked whenever dependent records exist.

### 3.6.4 Machine learning model selection

SYNAPSE embeds three predictive models, each selected on the shape of its target variable and the
decision context it supports. The selection is a *design* decision and is defended as such.

| Module | Task | Target | Algorithm | Why this algorithm |
|---|---|---|---|---|
| Attrition Risk | Binary classification | Will this person leave? | **Random Forest** (`RandomForestClassifier`) | Attrition drivers are a mix of numeric and categorical features with non-linear, interaction-heavy relationships and weak individual correlations. Bagged trees model those interactions without manual feature engineering, are insensitive to scale and outliers, resist overfitting on a ~1.5 k-row dataset, and handle the ≈16 % positive class natively through `class_weight="balanced_subsample"`. |
| Performance Forecast | Regression | Next cycle's score, 40–100 | **Histogram-based Gradient Boosting** (`HistGradientBoostingRegressor`) | Sequential trees, each correcting the previous residuals, capture the interaction between KPI history, attendance consistency, and training participation more tightly than bagging or a linear model. The histogram variant bins features so it scales to the 100 k-row table and supports early stopping, and it is native to scikit-learn, so no external gradient-boosting dependency is introduced. |
| Promotion Readiness | Binary classification | Is this person ready to advance? | **Logistic Regression** (`LogisticRegression`) | This is the deliberate trade. A promotion recommendation can be contested, so the model must be defensible. Logistic regression exposes a signed coefficient per feature, which means an **individual** prediction can be decomposed into what pushed the person up and what pulled them down — something a tree ensemble cannot do per instance. Predictive accuracy was traded for auditability on purpose, and `class_weight="balanced"` addresses the ≈10 % positive rate. |

The choice of Random Forest for attrition is consistent with the attrition modeling demonstrated by
Iparraguirre-Villanueva et al. [3] and validated by Almheiri [4]; the choice of gradient boosting
for performance follows Bijalwan et al. [5] and Roseline [6], who report lower error than
single-model regressors on comparable high-variance HR productivity data; the choice of an
interpretable linear classifier for promotion follows the transparent, auditable-coefficient
approach validated by Li [7] and Ibrir and Çavur [8].

**Acknowledged limitation.** No empirical model comparison across a common candidate set has been
performed; the selections above are justified on task fit and decision context, not on a
benchmark. A comparison benchmarking each task across logistic/ridge, random forest, and gradient
boosting on identical splits — reporting the metrics of Section 3.12.2 plus calibration and
training cost — is stated as future work rather than claimed as completed.

### 3.6.5 Training protocol and the schema-native feature contract

All three models are built inside a single scikit-learn `Pipeline` that bundles preprocessing and
the estimator together, so transformations are learned only from training data and applied
identically at inference. The shared recipe and the full protocol are shown in **Figure 3.29**.

Three properties of that pipeline are load-bearing at serving time and are therefore design
requirements, not incidental:

* **The imputers are fitted inside the pipeline**, so the inference service can accept a feature
  dictionary with holes in it and the pipeline fills them itself. This is what makes partial input
  safe — an employee with a thin record still scores, rather than erroring or being dropped.
* **`OneHotEncoder(handle_unknown="ignore")` neutralises unseen categories.** A department name the
  model has never met is zeroed out rather than raising. This is a *safe* failure but a *silent*
  one, and it is called out in the limitations of Section 3.12.2.3.
* **The exact column order is preserved**, so a partial input is aligned by name rather than read
  positionally.

**The fairness constraint lives in the data contract.** The attrition model is trained on only the
17 columns the ERP can actually supply. Excluded on purpose: pay rates and equity the ERP does not
track (daily/monthly/hourly rate, salary hike percentage, stock options); business travel;
all survey-only satisfaction scores, because SYNAPSE runs no engagement survey and feeding them
would be a fabrication at inference time; and **gender and marital status**, because protected
attributes must not drive a flight-risk flag. This exclusion costs roughly 0.06 ROC-AUC and buys an
input contract that matches live data and is fairness-defensible. The serving side reinforces it:
the inference service maintains a `PROTECTED_FEATURES` set — gender, marital status, age, education
level, city tier — and never surfaces any of them as a decision factor, both for fairness and
because the ERP does not feed them, so they would otherwise appear as spurious "drivers" of an
imputed constant.

**Threshold tuning is recall-oriented.** In HR the costly error is usually the false negative — a
leaver who was never flagged — so each classifier's decision threshold is chosen by a sweep on the
training folds rather than left at 0.5, and the chosen value ships inside the metrics artifact so
that serving and evaluation cannot disagree about it.

---

## 3.7 System Design

### 3.7.1 Design overview and guiding principles

SYNAPSE is designed as a **multi-tenant, multi-tier HR ERP** in which three runtime processes
cooperate: a Laravel application server that owns all data and enforces every rule, a Python
inference service that holds three trained models and no data at all, and a mobile companion that
is a self-scoped view of one employee. A fourth participant — the Google Gemini API — is external
to the institution's perimeter and is reached only from the server.

Five principles govern the design and recur in every diagram that follows.

| Principle | What it means in the design | Where it is visible |
|---|---|---|
| **Isolation below authorization** | The tenant filter is applied one level beneath the query layer, before permissions are ever consulted, so isolation holds regardless of role. | Figures 3.3, 3.35 |
| **One canonical writer** | Each operation has exactly one implementing class, reused by the web screen, the mobile API, and the AI assistant. | Figures 3.6–3.18, 3.33 |
| **Derive, do not store** | Statuses, progress, balances, and clearance states are computed on read. | Figure 3.32 |
| **Decide vs. enforce** | The language model decides; the module enforces permission, tenancy, validation, audit, notification. | Figures 3.17, 3.24 |
| **Degrade, do not fail** | Every intelligent capability has a defined behaviour when its dependency is absent. | Figures 3.1, 3.19, 3.23 |

### 3.7.2 System architecture

**Figure 3.1 — Layered System Architecture of SYNAPSE**
*[placeholder — insert `diagrams/fig-3-1-system-architecture.svg`]*

The architecture separates presentation, application logic, and data-and-intelligence into three
horizontal bands, so that the direction of every arrow also reads as the direction of trust:
requests flow downward from clients into the server and never sideways from a client into the
database or into an external service.

**Presentation layer.** Three clients, drawn separately because they authenticate differently.
The **web client** is a React 19 single-page application served by the Laravel backend through
Inertia.js over HTTPS with session-based authentication, so every page is rendered from
server-validated data and no business rule executes in the browser. The **mobile companion** is an
Expo (React Native) application that exchanges credentials for a Laravel Sanctum bearer token and
consumes a token-authenticated REST API. The **public careers page** is unauthenticated by design,
rate-limited, and honeypot-guarded. Both authenticated clients resolve the user's active
organization on every request, and all queries are constrained automatically to that organization.

**Application layer.** A single Laravel 13 process on PHP 8.3, drawn as one enclosing box with
three compartments — a **security and tenancy gate**, the **HR modules**, and the
**decision-support services** — to assert that these concerns share one process and one trust
context rather than being separately deployable services. Placing the gate *between* the modules
and the outside world is intentional: no request reaches a module without passing it, and this is
the single place where authorization and tenant isolation live. The decision-support services sit
on the same tier as the HR modules to convey that the assistant and the ML client are ordinary
server-side collaborators subject to the same authorization, not privileged side doors.

**Data and intelligence layer.** One PostgreSQL database holds all 69 tables. A private file store
holds résumés, 201-file documents, certifications, and clock-in selfies; nothing in it is
web-reachable, and every read is routed and logged. The **ML inference service** is a FastAPI
process that loads the three trained pipelines at start-up and exposes a health endpoint and one
batch prediction endpoint per model; a thin client class inside Laravel calls it over internal
HTTP with a bounded timeout.

**The perimeter.** A dashed rectangle wraps the web client, the mobile companion, the application
server, the database, the file store, and the inference service, and deliberately excludes the
Gemini box. That boundary is the visual statement that everything inside can be self-hosted on one
institution-owned machine, and that exactly one dependency lives outside it. The Gemini box is
connected only to the application server, never to a client, which is the diagram's guarantee that
the API key never reaches a user device.

**Graceful degradation.** Every intelligent capability has a defined behaviour when its dependency
is absent, and this is an architectural requirement rather than error handling. If the inference
service is unreachable, the analytics pages present previously stored results read-only with an
offline notice and record no new run. If the Gemini API is unconfigured or busy, the assistant and
the insight panels report themselves unavailable without blocking any core module. This is what
makes the modular split safe: the Python service can be scaled, retrained, or taken down
independently, and each tier evolves behind its interface while authorization and tenant isolation
stay in exactly one place.

### 3.7.3 Deployment view

**Figure 3.2 — Deployment Diagram**
*[placeholder — insert `diagrams/fig-3-2-deployment-diagram.svg`]*

Figure 3.2 shows the physical nodes, the artifacts each hosts, and the protocol on every binding.
Three points are asserted by the drawing. The inference service is bound to `127.0.0.1` and is
never published, so it is reachable only from the application server on the same host — it needs
no authentication of its own because it is not addressable from outside. The scheduler is drawn as
a separate artifact because one daily job (auto-closing past-due job postings) is a real system
actor and appears as process 1.8 in Figure 3.6. The private storage disk is drawn as an artifact
rather than folded into the application box, because "served only through a route" is a deployment
property, not a code property.

### 3.7.4 Request lifecycle and the enforcement pipeline

**Figure 3.3 — Request Lifecycle and the Enforcement Pipeline**
*[placeholder — insert `diagrams/fig-3-3-request-lifecycle.svg`]*

Every request traverses four gates in a fixed order — **identity, tenancy, authorization,
validation** — before any module code runs, and then leaves a fixed trail behind it: a database
transaction, an audit entry, any notifications, a flash message, and a hardened response.

The order matters and is the chapter's central security claim. Tenancy is resolved *before*
authorization, and the tenant filter is installed as a global query scope one level beneath the
query layer, together with a creation hook that stamps the owning organization on every write.
The consequence is that **isolation holds regardless of permission**: even a tenant's most
privileged user cannot read another tenant's rows, because the filter is applied before
permissions are ever consulted. Two classes are permitted to read past the filter — the ones that
redeem an invitation code and a workspace join code — because such a code is issued inside a
company but answered by somebody standing outside it; both are documented as the deliberate
exception and no other code is allowed to imitate them.

### 3.7.5 Context diagram (Data Flow Diagram, Level 0)

**Figure 3.4 — Context Diagram (Data Flow Diagram Level 0)**
*[placeholder — insert `diagrams/fig-3-4-context-diagram.svg`]*

Figure 3.4 fixes the system boundary. Seven external entities exchange data with SYNAPSE: four
human classes of user (HR manager/officer, department head, employee, and the unauthenticated job
applicant), one reporting consumer (executive leadership), and three system actors (the ML
inference service, the Gemini API, and the mail/push transports). The applicant is drawn as an
external entity rather than as a user because they interact only with the public careers page and
hold no account. The two AI dependencies are drawn with dashed connectors to mark them as
optional: the arrows describe what flows *when they are available*, and Section 3.7.2 defines what
happens when they are not.

### 3.7.6 Data flow diagram, Level 1

**Figure 3.5 — Data Flow Diagram Level 1**
*[placeholder — insert `diagrams/fig-3-5-dfd-level-1.svg`]*

Figure 3.5 decomposes process 0 into twelve top-level processes and the twelve data stores they
share. The decomposition follows the operational sequence of the employee lifecycle rather than
the sidebar layout, so the diagram reads as one long track: recruitment produces a hire, the hire
seeds onboarding and a 201 record, the 201 record is the hub that time, leave, performance,
training, recognition, and events all attach to, and offboarding writes the separation back onto
it. Two processes stand apart from the track — predictive analytics (11.0), which *reads* the
operational stores and writes only into its own run stores, and the assistant/dashboard/reports
process (12.0), which owns no store beyond the audit and conversation store and re-reads the
module stores it reports on. That is the diagram's way of stating that no analytical surface is
allowed to become a second source of truth.

Because an ERP has too many flows for one readable diagram, the cross-process flows that Figure
3.5 elides — the separation write-back from 10.0 into D3, and the ERP-servable feature reads by
11.0 from D3, D4, D6 and D7 — are decomposed in the Level-2 diagrams rather than crowded into the
Level-1 view.

### 3.7.7 Module-level data flow diagrams (Level 2)

Thirteen Level-2 diagrams decompose the processes of Figure 3.5. All thirteen share one layout —
external entities on the left, numbered processes down the centre, data stores on the right, and
external systems or design notes on the far right — so that they can be read as a set and
compared with one another.

#### 3.7.7.1 Recruitment (process 1.0)

**Figure 3.6 — Level-2 DFD: Recruitment**
*[placeholder — insert `diagrams/fig-3-6-dfd2-recruitment.svg`]*

An applicant tracking system: post a vacancy, rank applicants, interview, hire. A posting moves
`draft → open → closed | filled`, carries a number of openings, and — once open — must carry a
closing date that cannot be back-dated; a daily scheduled command (1.8) flips past-due open
postings to closed. Applications arrive either through the pipeline board or through the public
careers page; the applicant pool is reused by e-mail address, so a repeat applicant updates the
existing profile rather than creating a duplicate. Process 1.3 computes the deterministic fit score
specified in Section 3.7.9.1; process 1.4 optionally adds an LLM reading on top of it. Process 1.7
is the hire bridge, expanded as a flowchart in Figure 3.22.

Two design notes are asserted on the diagram. First, the recommended next step flows from 1.3 back
into 1.5 as *advice*, not as an instruction — the stage never advances on a score. Second, all
outbound candidate communication funnels through one notifier, so no code path can message a
candidate outside the template and channel rules.

#### 3.7.7.2 Onboarding (process 2.0)

**Figure 3.7 — Level-2 DFD: Onboarding**
*[placeholder — insert `diagrams/fig-3-7-dfd2-onboarding.svg`]*

A programme is a reusable template, optionally targeted at a department and/or an employment type,
with one marked as the company default; its blueprint tasks carry a **relative** day offset rather
than a fixed date. Process 2.2 selects the best-matching active programme by a fixed precedence —
department + type, then department, then type, then the default — and 2.3 instantiates the
blueprint into real dated tasks. Statutory deadlines deliberately bypass the offset builder,
because a legal due date is a date and not "day 3".

Process 2.4 is worth singling out: task ownership is resolved from owner *roles* to actual people
**at seed time**, and anything unresolvable is left explicitly unassigned rather than pointed at a
guess. Process 2.7 chases outstanding work grouped per person, so someone with four overdue items
receives one message listing four items rather than four separate pings, and the reminder store
doubles as the send log and the once-ness guard.

#### 3.7.7.3 Employee 201 file (process 3.0)

**Figure 3.8 — Level-2 DFD: Employee 201 File**
*[placeholder — insert `diagrams/fig-3-8-dfd2-employee-201-file.svg`]*

The record every other module points at. Beyond ordinary maintenance, three processes carry design
weight. Process 3.2 encrypts the four Philippine government identifiers at rest, with the accepted
consequence that they are no longer SQL-searchable. Process 3.5 writes career history
automatically: a promotion row is created whenever a position or salary changes on save, so the
timeline builds itself rather than depending on somebody remembering to record it. Processes 3.6
and 3.7 implement the identity model described below.

**The identity model.** The ERP owns *employment*; the person owns *identity*. Creating an employee
record creates no login, and HR can neither set nor reset anybody's password — being on the roster
and being able to sign in are two separate facts. There are exactly two ways a person becomes
connected to a company: an **invitation** issued against a specific roster line (a hashed link
token plus a retypeable code, where possession of a valid code *is* the authorisation), or the
**organization join code**, where an exact match on a registered e-mail admits the person
immediately and anything else queues for HR to approve and point at the right roster line. Both
paths converge on one function, which is the single place in the system where somebody becomes
staff of a company.

**The disclosure rule.** Process 3.8 answers workforce questions for the assistant. Nine fields —
`tin`, `sss_no`, `philhealth_no`, `pagibig_no`, `bank_name`, `bank_account_no`, `basic_salary`,
`address`, `birth_date` — are withheld from every such read, for every user, at every permission
level, because a tool result travels to an external model, into a transcript, and onto a
possibly-shared screen. They remain writable through the assistant and readable in the 201 file
itself: it is a *disclosure* rule, not a storage rule.

#### 3.7.7.4 Attendance (process 4.0)

**Figure 3.9 — Level-2 DFD: Attendance**
*[placeholder — insert `diagrams/fig-3-9-dfd2-attendance.svg`]*

Two tables and one calculator. `attendance_punches` holds the raw, append-only events — clock-in,
clock-out, break-start, break-end — each with a timestamp, a source (web, mobile, kiosk, biometric,
manual), GPS coordinates, an optional selfie, and who recorded it. `attendance_records` holds one
*derived* summary row per employee per day, including a snapshot of the schedule that applied.
Process 4.1 is the only path that ever writes a punch; it validates the transition (no double
clock-in, no clock-out before clock-in, breaks only while on the clock) and then triggers the
recomputation specified in Section 3.7.9.4.

Process 4.5 is a deliberate design choice: the roster view is built from **every** employee, so a
person with no punches appears as Absent, Day off, or On leave rather than silently vanishing from
the day's report.

#### 3.7.7.5 Leave (process 5.0)

**Figure 3.10 — Level-2 DFD: Leave**
*[placeholder — insert `diagrams/fig-3-10-dfd2-leave.svg`]*

Leave is an approvals queue, not a data table, and the design reflects that. Process 5.1 resolves
the non-working holidays in the requested range, expanding yearly-recurring entries onto whichever
years the range spans, so one "New Year's Day, recurring" row is honoured every year; holidays of
type *special working* remain ordinary working days. Process 5.2 computes chargeable days on the
server, and a day count sent by a browser or a phone is never trusted. Process 5.7 derives the
balance: only the entitlement is a stored row, while *used*, *pending*, and *remaining* are
aggregated from the requests, so a balance can never disagree with the requests behind it.

#### 3.7.7.6 Performance (process 6.0)

**Figure 3.11 — Level-2 DFD: Performance**
*[placeholder — insert `diagrams/fig-3-11-dfd2-performance.svg`]*

The most configurable module in the system, and the one whose design departs furthest from
conventional HRIS practice. Rather than hard-coding a 1–5 rating, four concepts stack:

| Concept | Decides |
|---|---|
| **Rating scale** | *How* something is measured: a numeric range with a step, a 0–100 percentage, or ordered named levels with behavioural anchors. |
| **Criterion** | *What* can be measured: a catalogue entry naming a scale and a default weight. |
| **Framework** | *Who* is reviewed, on which weighted sections and criteria, and how the result is reported. |
| **Rating model** | The ordered outcome bands the result is reported in — "Outstanding / Exceeds / Meets / Needs Improvement", or "A/B/C/D/F", or three bands against a target. |

Process 6.3 resolves the framework by taking the narrowest eligibility match — position beats
department beats employment type beats everyone, with the tenant default breaking ties — and the
resolved framework is a *suggestion* that HR may override. Process 6.5 is the design's keystone:
an appraisal **freezes** the framework it was opened under (name, sections, rating model) and each
score line freezes its section, its own weight, its description, and its full rating scale.
Retuning a framework, retiring a criterion, or editing a scale therefore changes the next appraisal
and never a past one, and a historical result can be rebuilt from the lines alone. Scoring (6.7) is
specified in Section 3.7.9.2 and calibration (6.8) surfaces rating inflation that is invisible one
scorecard at a time.

#### 3.7.7.7 Training and development (process 7.0)

**Figure 3.12 — Level-2 DFD: Training**
*[placeholder — insert `diagrams/fig-3-12-dfd2-training.svg`]*

Programmes carry a provider, an optional date window, a seat capacity, and a description. Status
is not stored (process 7.2): a programme is *completed* once the end date has passed, *ongoing*
once the start date has arrived, and *upcoming* otherwise. Seats taken counts non-dropped
enrolments, and enrolling into a full programme is blocked rather than silently queued. Process 7.7
emits the growth signal consumed by the awards nomination board and by the ML feature mappers,
which is why Training appears as an input to Figures 3.13 and 3.16.

#### 3.7.7.8 Awards and recognition (process 8.0)

**Figure 3.13 — Level-2 DFD: Awards**
*[placeholder — insert `diagrams/fig-3-13-dfd2-awards.svg`]*

Beneath a recognition feed sits the most interesting piece of deterministic decision support in the
system: for every active award type, who deserves it most right now. Process 8.2 classifies each
award type into a *focus profile* from keywords in its name and description, which sets the weight
of each signal; 8.3 gathers six signals; 8.4 scores and ranks; and 8.5 applies a fairness guard.
The algorithm is specified in Section 3.7.9.3. The LLM's only role (8.6) is to draft the citation
*after* the ranking exists, grounded in that nominee's own signal breakdown, and the draft is never
persisted on its own.

#### 3.7.7.9 Events and meetings (process 9.0)

**Figure 3.14 — Level-2 DFD: Events**
*[placeholder — insert `diagrams/fig-3-14-dfd2-events.svg`]*

Status is derived from the time window. Inviting somebody whose account is linked and active sends
an in-app notification and stamps when it was sent; a delivery failure never blocks the invitation,
and employees with no login are still invited, simply not notified. Responses run
`invited → accepted | declined | tentative`, and the "going" headcount counts accepted plus
tentative. An event with attendees cannot be permanently deleted, only archived.

#### 3.7.7.10 Offboarding and clearance (process 10.0)

**Figure 3.15 — Level-2 DFD: Offboarding**
*[placeholder — insert `diagrams/fig-3-15-dfd2-offboarding.svg`]*

The mirror image of onboarding. A case carries a type (resignation, termination, retirement,
end of contract), notice and last-working-day dates, a reason, and a lifecycle
`initiated → clearance → completed | cancelled`. Process 10.3 instantiates the clearance checklist
and routes each item to the department that must sign it off — IT, Finance, HR by code, or the
employee's own department. Clearance status (10.5) is derived and never stored, and a *flagged*
item keeps a case off "cleared" even when every other item is signed. Process 10.7 is the bridge
that transitions the employee's employment status to match the exit type; that status is not meant
to be edited by hand anywhere else in the system.

#### 3.7.7.11 Predictive analytics (process 11.0)

**Figure 3.16 — Level-2 DFD: Predictive Analytics**
*[placeholder — insert `diagrams/fig-3-16-dfd2-predictive-analytics.svg`]*

The same eight-step shape serves all three analytics surfaces. Process 11.1 gathers every active
employee with the required aggregates computed in SQL rather than in PHP loops (90-day overtime
totals, 12-month training counts). Process 11.2 maps each employee to a feature dictionary and
**omits** anything it cannot ground in real data — a zero would be a claim, whereas an absence is
honest and the pipeline's imputer handles it. Process 11.3 checks service health *before*
attempting a prediction, which is what makes the degraded path in Figure 3.23 clean rather than a
timeout. Processes 11.5 and 11.6 derive the human-facing quantities on the application side, and
11.7 persists the run. The full serving contract is in Section 3.7.10.

#### 3.7.7.12 LLM assistant (process 12.0)

**Figure 3.17 — Level-2 DFD: LLM Assistant**
*[placeholder — insert `diagrams/fig-3-17-dfd2-llm-assistant.svg`]*

Five capability modules expose tools to the assistant: Recruitment (25 tools), Onboarding (16),
Employees (9), Leave (4), and Attendance (2) — 56 in total. Process 12.2 builds the tool schemas
**scoped to the requesting user's permissions**, so a view-only recruiter is offered 8 tools where
a full recruiter is offered 25; and process 12.4 re-checks the permission on execution, because
*being offered a tool is not the same as being allowed to use it*. Process 12.5 executes through
the same canonical class the corresponding screen uses, so an action taken through conversation is
indistinguishable in its effects — including audit and notification — from the same action taken
through the interface. The turn structure is expanded in Figure 3.24 and the guardrails are
specified in Section 3.7.11.

#### 3.7.7.13 Reports and dashboard (process 13.0)

**Figure 3.18 — Level-2 DFD: Reports and Dashboard**
*[placeholder — insert `diagrams/fig-3-18-dfd2-reports-and-dashboard.svg`]*

Each of the seven reports is one class implementing a small contract, and that class owns its
filters, columns, rows, charts, summary, and export. One source therefore feeds the on-screen
table, the totals, the charts, the CSV/XLSX export, and the AI digest, so they cannot disagree with
one another — which is the property that makes an exported report usable as evidence. Charts (13.3)
are derived from the *whole* result set rather than the page on screen. ML signals (13.4) read the
latest **stored** runs rather than calling the live service, so a report never blocks on a network
call and the signals survive the inference service being offline. The insight endpoint (13.5)
re-resolves, re-authorises, and re-runs the report server-side from the same filters; it never
trusts numbers sent by the browser.

The dashboard (13.7, 13.8) adds no new data: it reuses each module's own statistics class so the
headline numbers have one source of truth, then adds the cross-cutting shape and a consolidated
**attention queue** of items the viewer can act on and that currently need action. Each block is
computed only if the viewer holds the matching view permission and is otherwise returned as null,
so a regular employee receives an empty overview rather than figures they are not allowed to see.

### 3.7.8 System and process flowcharts

Six control-flow diagrams complement the data-flow view. Where a data flow diagram answers *what
data moves*, these answer *in what order, and under what condition*.

**Figure 3.19 — System Flowchart of SYNAPSE**
*[placeholder — insert `diagrams/fig-3-19-system-flowchart.svg`]*

The top-level chart: authenticate, land on a permission-aware dashboard, then follow one of four
flows — an HR transaction, a predictive assessment, an assistant turn, or a report/export. Each
flow terminates in a defined end state, and the degraded path is drawn explicitly rather than left
as an implied error branch.

**Figure 3.20 — Flowchart: Authentication and Session Establishment**
*[placeholder — insert `diagrams/fig-3-20-flowchart-authentication.svg`]*

The login path is charted separately because security-critical control flow deserves its own
explicit diagram. Two-factor authentication is modelled as a *conditional* rather than a mandatory
step, reflecting that it is optional per user: if a factor is enrolled the flow proceeds to
verification with its own failure loop; if not, it bypasses verification and rejoins the main path.
The chart then reaches the tenancy gate, whose "No" branch is an explicit dead end —
a correctly authenticated user still cannot enter an organization they do not belong to. Only after
membership is confirmed does the flow load role permissions and establish the session. The
ordering of the three gates — identity, then tenancy, then authorization — is thereby made
unmistakable. A fourth outcome is drawn honestly: a self-registered identity that has joined no
company at all is routed to the invitation / join-code screen rather than to a dashboard.

**Figure 3.21 — Flowchart: Leave Request with Approval**
*[placeholder — insert `diagrams/fig-3-21-flowchart-leave-approval.svg`]*

Leave is charted as the representative example of the many approval-driven transactions the system
supports, because the same *validate → persist → route → decide → notify* pattern recurs across
leave, attendance corrections, and offboarding clearance. The invalid branch loops back to the form
with errors shown, modelling that invalid requests never enter the workflow at all. Both exits of
the approval decision are drawn as complete paths, and the balance is shown changing only on the
approved path, which makes the chart an accurate model of *when state actually changes* rather than
a sketch of the happy path. Both terminal outcomes end in a notification and an auditable record.

**Figure 3.22 — Flowchart: The Hire Bridge**
*[placeholder — insert `diagrams/fig-3-22-flowchart-hire-bridge.svg`]*

Pressing "Hire" performs five writes inside one database transaction: create the employee record,
copy the résumé into the new 201 file, seed onboarding from the best-matching programme, mark the
application hired and link it to the new employee, and fill the posting if all its openings are now
taken. The transaction boundary is drawn explicitly because the five writes are mutually
dependent — an employee with no onboarding case, or an application marked hired with no employee,
would each be a silent data fault. Side effects are queued *after* commit, so nothing fires on a
rolled-back hire. The chart also shows what hiring deliberately does **not** do: it issues an app
invitation rather than a login, because employment and identity are separate.

**Figure 3.23 — Flowchart: Predictive Assessment Run**
*[placeholder — insert `diagrams/fig-3-23-flowchart-predictive-assessment.svg`]*

Organised around a single decision — "is the ML service reachable?" — placed *early*, immediately
after the cohort is selected and the feature vectors assembled. Positioning the health check before
the prediction call is the chart's way of showing that the system decides whether it *can* predict
before it tries to, so the "No" branch diverts cleanly to the last stored run with an offline
notice without ever attempting a call that would hang the page. On the "Yes" path, deriving the
tier, band, and confidence is drawn as a step separate from receiving the scores, reinforcing at
the control-flow level the same division of labour shown in Figure 3.30: the model returns raw
numbers and the server assigns the human-facing categories.

**Figure 3.24 — Flowchart: LLM Assistant Function-Calling Loop**
*[placeholder — insert `diagrams/fig-3-24-flowchart-assistant-loop.svg`]*

Building the permitted tool schemas precedes the model call, so permission filtering happens before
the model is ever consulted. The first decision has a "No" exit straight to a prose reply, modelling
the common case in which the assistant simply answers. When an action *is* requested, a second
decision guards execution and its "No" exit leads to an explicit refusal or clarification path.
Execution and audit logging are drawn in one box to convey that no tool runs without an audit
entry. The loop back to the model is capped at six round-trips, drawn directly on the loop, which
is what distinguishes a controlled agent from an open-ended one and is the visual guarantee that
latency and API cost per turn stay finite. One cost optimisation is charted because it changes the
system's economics: when every call in a step succeeded, the reply is composed locally from the
results rather than spending a second model request, so the common "look something up" or "do one
thing" turn costs a single API call.

### 3.7.9 Decision-support algorithm design (deterministic)

Most of the intelligence in SYNAPSE is careful arithmetic rather than machine learning, and that is
a design position rather than a shortfall. Deterministic scoring is free, instant, fully
explainable, and reproducible — the same input always yields the same number — which is exactly
what is wanted where a score will be shown to the person it concerns.

| Class | Produces | Specified in |
|---|---|---|
| `ApplicantScorer` | Candidate fit 0–100 with a five-part breakdown, plus the recommended next pipeline step | §3.7.9.1 |
| `PerformanceScorer` | Appraisal attainment 0–100 through weighted sections and per-criterion scales | §3.7.9.2 |
| `PerformanceCalibration` | Band distribution across a review cycle and per-department deviation | §3.7.9.2 |
| `AwardNominator` | Ranked shortlists per award type from six weighted signals, with a repeat-winner guard | §3.7.9.3 |
| `AttendanceCalculator` | Worked, break, late, undertime, overtime minutes and the daily status | §3.7.9.4 |
| `LeaveCalculator` | Chargeable working days, weekend- and holiday-aware | §3.7.9.5 |
| `PipelineInsights` | Per-stage pipeline read: average fit, strong matches, ready-to-advance, stalled cards | §3.7.9.6 |
| `DashboardOverview` | Cross-module statistics and the permission-gated attention queue | §3.7.9.6 |

All of them share one property that makes them usable from the first day of deployment rather than
after months of data accumulation: **components that cannot be assessed drop out, and the score is
normalised over whatever remains.** A candidate with no interview yet is not penalised for it; an
award type with no forecast data still ranks. Every score is "out of what we actually know".

#### 3.7.9.1 Recruitment fit score

**Figure 3.25 — Decision-Support Algorithm: Recruitment Fit Score**
*[placeholder — insert `diagrams/fig-3-25-algorithm-fit-score.svg`]*

Five components each carry a maximum number of points:

| Component | Max | Rule |
|---|---|---|
| Recruiter rating | 30 | `round(rating / 5 × 30)` |
| Experience | 25 | `min(years / required, 1)`; when the posting sets no minimum, `min(years / 8, 1)` |
| Skill keyword match | 20 | `matched / required`, by case-insensitive substring over the headline, notes, and cover note; **dropped entirely when the posting lists no skills** |
| Interview outcome | 15 | passed = full, pending = half, failed = 0; **dropped when no interview exists and the stage has not reached interview** |
| Documents | 10 | résumé = 6 points, then +1 per supporting document up to 4 |

```
earned    = Σ points over assessable components
available = Σ max    over assessable components
fit       = round(earned / available × 100)              ∈ [0, 100]

bands:  fit ≥ 75 strong · fit ≥ 55 promising · fit ≥ 35 fair · otherwise weak
```

The same class derives the **recommended next step** from the current stage, the fit, and the
interview verdict (the mapping is tabulated in Figure 3.25). Two design constraints apply: the
recommendation is advice and never advances a stage on its own, and the score is persisted and
invalidated by an **input hash** rather than by an enumerated list of trigger events, so no future
call site can forget to invalidate it.

Complexity is O(*k*) per application, where *k* is the number of required skills; the board switches
to server-side ranking and pagination above 200 candidates, transparently to the user.

#### 3.7.9.2 Appraisal scoring and calibration

**Figure 3.26 — Decision-Support Algorithm: Two-Level Appraisal Scoring**
*[placeholder — insert `diagrams/fig-3-26-algorithm-appraisal-scoring.svg`]*

Scoring happens at two levels, because that is how real appraisal forms are written: "Goals 60 %,
Competencies 30 %, Values 10 %", with weighted lines inside each section.

```
Level 1 — each line's raw rating becomes its position on ITS OWN scale
    fraction_i = (score_i − scale_min_i) / (scale_max_i − scale_min_i)      ∈ [0, 1]

Level 2 — section attainment, then the overall
    section%_s = Σ(fraction_i × weight_i) / Σ(weight_i) × 100     over RATED lines only
    overall%   = Σ(section%_s × weight_s) / Σ(weight_s)           over sections with ≥1 rated line

    result_band   = the highest band in the tenant's rating model whose min_percent overall% reaches
    overall_score = 1 + (overall% / 100) × 4                       ← the 1–5 projection
```

Only rated lines contribute, so a draft carries a live running result and a section with nothing
rated is excluded entirely rather than dragging the total down. A section that declares no weight
of its own falls back to the summed weight of its lines, which is what makes a flat, unsectioned
scorecard score exactly as a plain weighted average. The 1–5 `overall_score` is retained purely as
an affine projection of the same 0–100 figure, because the ML forecast pipeline, the attrition
features, and the awards nominator are all built on it; it is a compatibility layer, not a second
opinion. A rating is validated against its own line's **snapshot** scale on the way in, so a
named-level scale accepts only the values it defines, and the result is recomputed on every save
and never trusted from the client.

**Calibration** is computed per review cycle: coverage against active headcount, in-progress and
awaiting-sign-off counts, average attainment, the result spread across the tenant's own bands, and
per-department deviation from the cycle average. Because a cycle may run several frameworks at
once, bands are grouped by the words they were actually reported in. The purpose is stated
plainly: rating inflation is invisible one scorecard at a time and obvious the moment the
distribution is on screen.

#### 3.7.9.3 Award nomination

**Figure 3.27 — Decision-Support Algorithm: Award Nomination**
*[placeholder — insert `diagrams/fig-3-27-algorithm-award-nomination.svg`]*

**Step 1 — classify the award type.** Keywords in the award type's name and description select a
focus profile; first match wins, and anything unmatched is ranked all-round.

**Step 2 — weights per profile** (each row sums to 100 before normalisation):

| Profile | Performance | Forecast | Attendance | Training | Tenure | Recognition gap |
|---|---|---|---|---|---|---|
| Performance | 40 | 10 | 15 | 10 | — | 25 |
| Attendance | 15 | — | 55 | — | 10 | 20 |
| Tenure | 15 | — | 10 | — | 55 | 20 |
| Growth | 20 | — | 10 | 50 | — | 20 |
| All-round | 30 | 10 | 15 | 10 | 10 | 25 |

**Step 3 — the six signals**, each normalised to [0, 1]:

```
Performance   (latest submitted appraisal overall − 1) / 4
Forecast      ML predicted rating / 100                     (only when a forecast run exists)
Attendance    over the last 90 days:
              rate = (present + 0.75 × (late + undertime)) / workdays
              leave days, days off and holidays are excused entirely
Training      completions in the last 12 months / 3, capped at 1
              refined as  base × 0.7 + (average score / 100) × 0.3
Tenure        years of service / 10, capped at 1
Gap           months since last recognised / 12, capped at 1; never recognised = full marks

score = Σ(signal × weight) over assessable signals / Σ(weight of those signals) × 100
shortlist = top 5 per award type
```

**Step 4 — the fairness guard.** Winning the same award within the last six months zeroes the
recognition-gap signal outright and flags the nominee on the board, so the board spreads
recognition rather than crowning the same person twice — which is precisely what a purely
score-ranked list would do.

**Step 5 — citation drafting (LLM).** Only after the ranking exists does the model receive the
employee, the award type, and that nominee's real signal breakdown, and return a one-to-two
sentence citation draft that the granter edits before saving. The language model never influences
the ranking.

#### 3.7.9.4 Attendance derivation

**Figure 3.28 — Decision-Support Algorithm: Attendance Derivation**
*[placeholder — insert `diagrams/fig-3-28-algorithm-attendance.svg`]*

```
walk the day's punches in chronological order as a state machine:
    on_clock = false ; on_break = false ; worked = 0 ; break = 0
    for each punch:
        delta = minutes since the previous punch
        if on_clock and on_break :  break  += delta
        elif on_clock            :  worked += delta
        clock_in → on_clock = true      clock_out → on_clock = on_break = false
        break_start → on_break = true   break_end → on_break = false

late_minutes      = max(0, first_in − (scheduled_start + grace_minutes))
undertime_minutes = max(0, scheduled_end − last_out)        when they left early
overtime_minutes  = max(0, worked − required_hours × 60)

status (first match wins):
    no punches, an approved leave covers the day        → on_leave
    no punches, weekday not in the schedule's work days → day_off
    no punches otherwise                                → absent
    clocked in but never out                            → incomplete
    late_minutes > 0                                    → late
    undertime_minutes > 0                               → undertime
    otherwise                                           → present

attendance_rate = present days / scheduled days × 100        (null if nothing was scheduled)
```

Three properties are deliberate. Arithmetic runs on UNIX timestamps, so the calculator is agnostic
to whether the application uses mutable or immutable date objects. Leave awareness reuses the leave
module's own "does this request cover this date" check rather than reimplementing it, so an
approved leave day is never flagged absent. And the calculator is pure and side-effect-free apart
from mutating the record it is handed — the caller persists — which is what makes it directly
unit-testable.

#### 3.7.9.5 Leave day computation

```
working_days(start, end, holidays) =
    count of dates in [start, end] that are
        · not a Saturday or Sunday
        · not in the non-working holiday set

chargeable(start, end, is_half_day, holidays) =
    0.5                                  if is_half_day and start = end and that day is a working day
    0.0                                  if is_half_day and start = end and that day is a weekend or holiday
    working_days(start, end, holidays)   otherwise
```

Holidays are resolved from the tenant's own holiday calendar, with yearly-recurring entries
expanded onto whichever years the range spans; holidays of type *special working* remain ordinary
working days. The computation is a pure date utility — the caller supplies the holiday set — which
makes it trivially testable and lets both the web controller and the mobile API call it without
duplication.

#### 3.7.9.6 Pipeline insights and the dashboard attention queue

`PipelineInsights` produces a per-stage reading of a recruitment pipeline: the average fit at each
stage, the count of strong matches, how many are ready to advance, which cards have stalled, and
the standout candidate. `DashboardOverview` composes the landing surface by reusing each module's
own statistics class — it introduces no new query — and then builds the **attention queue**: a
single consolidated list of items the viewer can act on *and* that currently need action (pending
leave, attendance approvals, overdue onboarding tasks, flagged clearances, upcoming interviews),
with empty rows dropped. Gating is done server-side: each block is computed only when the viewer
holds the matching view permission and is otherwise returned as null.

### 3.7.10 Machine learning design

**Figure 3.29 — Machine Learning Training Pipeline**
*[placeholder — insert `diagrams/fig-3-29-ml-training-pipeline.svg`]*

**Figure 3.30 — Machine Learning Serving Path and the Division of Labour**
*[placeholder — insert `diagrams/fig-3-30-ml-serving-path.svg`]*

The serving path is identical for all three analytics surfaces, and the division of labour between
the Python service and the application server is the design's key claim.

**What the service returns, and what the application derives:**

| Model | The service returns | Laravel derives | Why |
|---|---|---|---|
| Promotion readiness | probability, score (= probability × 100), tier, **per-feature factor contributions** | nothing further | the model is linear, so the "why" is real, not reconstructed |
| Attrition risk | probability, score, tier | **confidence** | a forest exposes no per-instance contributions |
| Performance forecast | predicted value only | **band and confidence** | a point regressor gives no probability and no tier at all |

**The three derived quantities:**

```
tier   (promotion, attrition)   probability < 0.33 → low
                                probability < 0.66 → medium
                                otherwise          → high

band   (performance forecast)   rating ≥ 80 → exceeds
                                rating ≥ 60 → on_track
                                otherwise   → below

confidence ∈ [0,1]              key features grounded in this employee's real HR data
                                ─────────────────────────────────────────────────────
                                              total key features
```

**Confidence is the honest quantity.** A tree ensemble and a point regressor cannot hand back a
per-employee explanation, so rather than invent one, the design reports how much of the prediction
rested on the person's own recorded data versus values the pipeline imputed. A brand-new hire with
no appraisals scores low confidence — which is exactly right. Confidence is explicitly **not** a
statistical interval: high confidence means "the model was given many real facts about this
person", not "the prediction is probably correct". The attrition model tracks 7 key features
(income, overtime, tenure, years in role, years since promotion, performance rating, training last
year); the performance forecast tracks 10.

**Feature mapping** is the translation layer where ERP reality meets model vocabulary. Notable
translations: an employee never promoted has years-in-role and years-since-promotion both fall back
to total tenure; overtime is not sent as a raw number to the attrition model but as a yes/no flag,
by summing approved overtime minutes over the last 90 days and thresholding at 120 minutes;
performance ratings are clamped into the range the training data actually contained; employment
type is mapped from the ERP's vocabulary (regular, probationary, part-time, contractual) into the
model's (Full-time, Part-time, Contract); and **anything that cannot be grounded is omitted from
the dictionary rather than sent as a zero**, because a zero is a claim while an absence is honest.

**Degradation.** If the Python service is not running, the HTTP client raises a typed exception
carrying an `unreachable` flag and a message that names the command to start it. The action shows a
friendly message, no run is recorded, and the page shows an offline banner — while every previously
stored assessment stays fully visible. The Reports module's ML chips read stored runs for exactly
this reason.

**Storage: runs, not columns.** Predictions are never written onto the employee record. Each
assessment is a **run**: a header row (who ran it, when, the model version, tier counts, averages)
plus one score row per employee holding the probability, the score, the tier or band, the
confidence or the factors, and a snapshot of the exact features that were sent. That snapshot is
what makes an old prediction auditable — a reader can see what the model was *told*, not only what
it said. Past runs remain selectable from a history picker.

### 3.7.11 Large language model integration design

One client class, six writers, and one agentic assistant. The model is `gemini-2.5-flash` at
temperature 0.2, reached through a thin server-side wrapper; the API key lives in server
configuration and never leaves the backend. The wrapper briefly retries a 503 ("high demand", which
usually clears in a second) with escalating backoff but **fails fast on a 429**, because a real
quota limit will not refill in milliseconds and the caller should surface a friendly message rather
than hang.

**The six writers.** Each is a single-purpose, single-call reader that returns strict JSON.

| Writer | Reads | Returns |
|---|---|---|
| Applicant insights | Candidate digest **plus the actual résumé and documents** | verdict, summary, strengths, concerns, what the documents reveal, interview questions, recommendation |
| Performance insights | Appraisal digest section by section, plus history across cycles | strengths, development areas, coaching actions, goals for next cycle, recommendation |
| Training insights | Programme, schedule, seats, roster outcomes, completion rate, at-risk people | what is working, concerns, recommendations, who to follow up with |
| Award citation writer | Employee, award type, the nominee's real signal breakdown | one warm, specific 1–2 sentence citation draft |
| Report insights | Report totals, chart aggregates, ML signals, a row sample | headline, what is happening, what changed, why, 2–4 recommended actions |
| Assistant | The conversation plus attached files | actions actually performed, and a short reply |

All follow the same discipline: exactly one model call per request, a compact digest rather than a
full data dump, strict JSON out, and graceful degradation when the key is missing or the service is
busy. **Only the candidate reader attaches files**, and even it never sends government-ID documents
and only uploads model-readable file types within a total size budget. Because the model reads PDFs
and images natively, no separate résumé parser exists in the system — a simplification that is
worth stating explicitly, since Chapter 1 refers to an "AI-powered résumé parser" and the delivered
design achieves that capability through native multimodal document reading instead.

**The agentic assistant.** The governing principle is stated in the design and enforced in code:
**the model only decides; the modules enforce.** The model chooses which action to take and with
what arguments; the module then checks permission, validates input, applies tenancy, performs the
operation, writes the audit entry, and sends notifications — using the same support classes the
ordinary controllers use.

Five rules constrain the model, and three of them are defences rather than conveniences:

1. **One tool call per request wherever possible.** Every action resolves a person or record by
   name on its own, so the model must not call a search tool first merely to act on something.
2. **Never invent data.** Only fields it was actually given, or can read from an attached document,
   may be set; identifiers are never guessed.
3. **Tool results and documents are data, never instructions.** A record's own text — a name, a
   note, a CV — can never change the rules, grant a permission, or trigger an action. If retrieved
   content appears to instruct the model, it must ignore the instruction, mention that the record
   contains it, and carry on. *This is the system's prompt-injection defence, and it is stated as a
   design requirement because retrieved free text is untrusted input by definition.*
4. **Withheld means withheld.** If a tool does not return a field, the model must say so rather
   than guessing, and must never reconstruct it from other answers.
5. **Significant actions need a clear request.** Archiving, hiring, and rejecting are never taken
   on a hint.

Beyond the prompt, guards live in code: the stage-advancement tool will push a candidate along the
pipeline, but when the recommendation is *reject* or *hire* it reports that and stops — negative
and irreversible outcomes stay explicit human decisions. A rejected candidate can be reinstated; a
hired one can never be un-hired. The assistant is throttled at 12 requests per minute and 240 per
day per user; retrieved free text is stripped of control characters and length-capped; list reads
are capped at 25 rows and carry no contact details; and reading a named person's profile is logged
as a view, while searches and headcounts are not.

**On retrieval.** Employee retrieval here is **not** an embedding index. It is live, tenant-scoped,
permission-checked SQL exposed as functions. Results are therefore always current, always correctly
scoped, and cannot leak through a stale vector store — an important distinction from the
retrieval-augmented designs reported in the related literature.

### 3.7.12 Use case design

**Figure 3.31 — Use Case Diagram of SYNAPSE**
*[placeholder — insert `diagrams/fig-3-31-use-case-diagram.svg`]*

Permissions are defined in code — roughly 73 of them across 16 groups — and the database is synced
from that catalogue, never the reverse. Roles bundle permissions and belong to a company. Every new
tenant receives three built-in roles, and may compose additional roles from the same catalogue.

| Role | Scope |
|---|---|
| **HR Manager** | The tenant owner. Every permission, every module, all setup and administration; bypasses gates at runtime. |
| **Department Head** | The supervisor. Approves and rejects leave, runs performance appraisals, reads the employee directory, attendance, onboarding, offboarding, training, awards, and events. May *view* the three predictive analytics screens but not *run* them. |
| **Staff** | The regular employee. Two permissions only — clock in/out and file leave. This is the role the mobile application is built around. |

Representative use case narratives are given below; the remainder follow the same shape.

| ID | Use case | Actor | Pre-condition | Main flow | Post-condition | Alternate |
|---|---|---|---|---|---|---|
| UC-01 | Hire applicant | HR Manager (`recruitment.hire`) | Application is at the offer stage and not already hired | Confirm hire → one transaction creates the employee, copies the résumé, seeds onboarding, marks the application hired, fills the posting → invitation issued after commit | Employee exists; onboarding case open; invitation pending | Already hired → action refused |
| UC-02 | File leave request | Staff (`leave.request`) | An entitlement exists for the type and year | Submit type, dates, half-day flag → server resolves holidays and computes chargeable days → validates balance → persists pending → notifies approvers | Request pending; approver notified | Type does not require approval → auto-approved |
| UC-03 | Run attrition assessment | HR Manager (`analytics.attrition.manage`) | At least one active employee | Gather cohort → map features → health check → batch score → derive tier and confidence → persist run | Run stored and rendered | Service unreachable → last stored run shown with an offline notice, no run recorded |
| UC-04 | Converse with the HR assistant | Any signed-in user | Gemini configured; user under throttle | Permitted tools built → model decides → module re-checks permission and executes → result sanitised → reply composed | Action performed and audited; conversation persisted | Not permitted / invalid arguments → refusal or clarification |
| UC-05 | Clock in from the phone | Staff (`attendance.clock`) | Employee linked to a user account in the active workspace | Capture GPS and optional selfie → POST punch → transition validated → punch written → daily record recomputed | Punch stored; daily record updated | Invalid transition (e.g. double clock-in) → rejected with a reason |

### 3.7.13 Record lifecycle (state) design

**Figure 3.32 — Lifecycle (State) Diagrams of the Principal Records**
*[placeholder — insert `diagrams/fig-3-32-state-diagrams.svg`]*

Figure 3.32 charts eight lifecycles and marks which of them are *stored* states and which are
*derived*. The distinction is the design invariant of Section 3.6.3 made visible: a job
application's stage and an appraisal's status are stored, because they change only through a
deliberate human act; a daily attendance status, a clearance status, and a training or event
status are derived on read, because a stored copy would drift the moment an underlying date or
item changed.

Three transitions carry policy rather than mechanics. An onboarding case moves to *in progress*
automatically on the first task activity, but completion is always deliberate — and completing a
case with unfinished tasks is *allowed*, because HR legitimately does this, with both the
confirmation message and the audit entry stating how many were left open. A rejected candidate may
be reinstated but a hired one may never be un-hired. And completing an offboarding case is the only
sanctioned way an employee's employment status changes.

### 3.7.14 Interaction design

**Figure 3.33 — Sequence Diagram: Mobile Clock-In with GPS and Selfie**
*[placeholder — insert `diagrams/fig-3-33-sequence-mobile-clock-in.svg`]*

One interaction is charted in full because it exercises the largest number of design decisions at
once: token authentication, tenant binding from the token, a permission gate on a mobile endpoint,
the canonical writer, multipart upload to the private disk, and derived recomputation.

Two properties are asserted by the diagram. First, **the button's state comes from the server**,
not from the client: the label of the single primary button flips with the day's state as computed
from the stored record, so a lost connection or a reinstalled application cannot desynchronise the
day. Second, the mobile path and the web path converge on the *same* canonical classes, so a punch
made on a phone computes identically to one made at a desktop, and mobile leave filing
auto-approves exactly the types web filing does.

### 3.7.15 Security and privacy design

**Figure 3.35 — Security and Privacy Enforcement Layers**
*[placeholder — insert `diagrams/fig-3-35-security-layers.svg`]*

Seven controls apply in a fixed order; a request must satisfy every one of them.

1. **Transport and browser.** TLS in transit. A global middleware sets a nonce-based Content
   Security Policy, `X-Frame-Options`, and `X-Content-Type-Options`, and strips the
   `X-Powered-By` fingerprint, on every response — including the health check and framework error
   pages.
2. **Rate limiting and bot control.** Login is throttled per e-mail and IP; registration at 6 per
   minute; workspace and invitation lookups at 10 per minute (these are *lookups*, but a join code
   names a real organization and an invitation code grants a seat in one, so both are guessing
   targets and are limited even though they do not mutate); the assistant at 12 per minute and 240
   per day per user. The public application form carries a hidden honeypot field that silently
   drops bots.
3. **Identity.** E-mail and password with verification and reset, optional TOTP two-factor and
   WebAuthn passkeys on the web, and Sanctum bearer tokens for mobile — each token minted bound to
   exactly one organization.
4. **Tenancy, at row level.** The active organization is bound per request and always validated
   against the user's memberships; a global query scope filters every read and stamps every write
   beneath the query layer.
5. **Authorization.** Seventy-three named permissions in sixteen groups, defined in code and synced
   to the database rather than the reverse; a permission gate on every route; bulk endpoints
   re-authorise each item individually; assistant tools are permission-scoped *before* they are
   offered and re-checked *on* execution.
6. **Data protection at rest.** The four Philippine government identifiers are encrypted (and
   therefore no longer SQL-searchable). Documents live on a private disk, are served only through
   a route, and every access is logged. Government IDs and bank details render masked with an
   explicit reveal control — which is shoulder-surfing cover for a shared screen, not access
   control, since whoever opened the record was already trusted with the value.
7. **Disclosure and the AI boundary.** The nine-field withholding list of Section 3.7.7.3;
   government-ID documents are never uploaded to the model; the `PROTECTED_FEATURES` set is never
   surfaced as a decision factor; and retrieved text is treated as data and never as instruction.

All live employee data is handled in compliance with **Republic Act 10173, the Data Privacy Act of
2012**, through access control, tenant isolation, append-only audit logging, and data minimisation.
Data minimisation is designed in rather than asserted: the pre-employment checklist records that a
medical clearance was obtained without ever storing a diagnosis, and the ML feature contract
excludes attributes the system has no operational reason to hold.

### 3.7.16 Interface and navigation design

**Figure 3.36 — Information Architecture and Navigation Map**
*[placeholder — insert `diagrams/fig-3-36-navigation-ia.svg`]*

The web application organises 53 screens into six sidebar groups that follow the flow of work
rather than an alphabetical menu: Talent Acquisition, Workforce, Offboarding, Analytics & AI,
Company Setup, and System. A navigation item is not merely disabled when the viewer lacks its
permission — it is not rendered at all, and its route is gated independently, so hiding the link is
a convenience rather than the control.

The mobile companion is deliberately narrow: five tabs, one hero feature. It is the employee's own
view of themselves, and creating a company, managing anyone else's record, running an assessment,
and reading another employee's data are all absent by design. Every mobile endpoint is
self-scoped — it returns the caller's own data and requires no permission beyond the two the Staff
role carries.

Notification delivery is designed as three channels from one call site: in-app (always, powering
the header bell and the notification centre), e-mail (when the user has e-mail notifications
enabled and an address), and web push (when the user has push enabled and at least one browser
subscription). All three are delivered inline on the request rather than through a queue worker, so
e-mail arrives whether or not a background worker is running; web push is additionally wrapped so
that a PHP build unable to sign a VAPID token logs a warning and lets in-app and e-mail through
rather than aborting the whole notification.

---

## 3.8 Database Design

### 3.8.1 Entity relationship diagram

**Figure 3.34 — Entity Relationship Diagram of SYNAPSE (Structural Level)**
*[placeholder — insert `diagrams/fig-3-34-erd.svg`]*

Figure 3.34 presents the entity relationship diagram at the structural level, grouping all **69
tables** into ten functional clusters. The `organizations` table anchors tenancy and is referenced
by nearly every domain entity through an `organization_id` column — a reference that is enforced by
a global query scope rather than written into each query by hand. The `users` table carries global
identities linked to organizations and roles through membership pivots, which is what allows one
person to belong to several tenants with different roles in each.

The `employees` table is the hub of the HR domain. The 201-file satellites (documents,
certifications, promotions, awards), every operational module (attendance, leave, performance,
training, events, onboarding, offboarding), and all three predictive score tables point at it.
Recruitment flows from postings through applications to interviews and, on hire, into `employees`.
Every template-driven module pairs a programme table with its instantiated per-employee records —
onboarding programmes with onboarding cases and tasks, offboarding programmes with cases and
clearance items, review templates with evaluations and scores — which is the structural expression
of the "template plus instance" pattern used throughout the design.

Three structural decisions are worth stating because they are not obvious from the table names
alone:

* **Predictions are runs, not columns.** No score is written onto the employee row. Each assessment
  produces a run header and one score row per employee, each carrying a JSON snapshot of the exact
  feature vector sent to the model.
* **Performance snapshots are denormalised on purpose.** `performance_evaluations` and
  `performance_scores` each carry copies of the framework, section, and scale that applied when
  they were written. This is deliberate redundancy: it is what makes a historical appraisal
  immutable against later configuration changes.
* **Invitation codes are globally unique, not per tenant.** An invitation code is redeemed *before*
  any tenant is bound, so it cannot be scoped to one.

The field-level structure of every table is given in Section 3.8.2.

### 3.8.2 Data dictionary

The data dictionary below covers the schema as designed for PostgreSQL. For each attribute
the data type, the maximum length or precision, the key type, and the nullability are given.
Tables 3.1–3.64 are the original dictionary; Section 3.8.3 documents the five tables and the
columns added afterwards, which together bring the schema to 69 tables.

**Table 3.1 — `users`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| email | VARCHAR | 255 | Unique | No |
| email_verified_at | TIMESTAMP | - | - | Yes |
| password | VARCHAR | 255 | - | Yes |
| remember_token | VARCHAR | 100 | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| two_factor_secret | TEXT | - | - | Yes |
| two_factor_recovery_codes | TEXT | - | - | Yes |
| two_factor_confirmed_at | TIMESTAMP | - | - | Yes |
| first_name | VARCHAR | 255 | - | No |
| middle_name | VARCHAR | 255 | - | Yes |
| last_name | VARCHAR | 255 | - | No |
| suffix | VARCHAR | 255 | - | Yes |
| phone_number | VARCHAR | 255 | - | Yes |
| profile_photo | VARCHAR | 255 | - | Yes |
| employee_id | VARCHAR | 255 | Unique | Yes |
| is_active | BOOLEAN | - | - | No |
| last_login_at | TIMESTAMP | - | - | Yes |
| password_changed_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |
| email_notifications | BOOLEAN | - | - | No |
| push_notifications | BOOLEAN | - | - | No |

**Table 3.2 — `passkeys`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| user_id | BIGINT | - | Foreign Key (users) | No |
| name | VARCHAR | 255 | - | No |
| credential_id | VARCHAR | 255 | Unique | No |
| credential | JSON | - | - | No |
| last_used_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.3 — `password_reset_tokens`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| email | VARCHAR | 255 | Primary Key | No |
| token | VARCHAR | 255 | - | No |
| created_at | TIMESTAMP | - | - | Yes |

**Table 3.4 — `sessions`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | VARCHAR | 255 | Primary Key | No |
| user_id | BIGINT | - | - | Yes |
| ip_address | VARCHAR | 45 | - | Yes |
| user_agent | TEXT | - | - | Yes |
| payload | TEXT | - | - | No |
| last_activity | INTEGER | - | - | No |

**Table 3.5 — `personal_access_tokens`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| tokenable_type | VARCHAR | 255 | - | No |
| tokenable_id | BIGINT | - | - | No |
| name | TEXT | - | - | No |
| token | VARCHAR | 64 | Unique | No |
| abilities | TEXT | - | - | Yes |
| last_used_at | TIMESTAMP | - | - | Yes |
| expires_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | Yes |

**Table 3.6 — `roles`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| name | VARCHAR | 255 | Unique | No |
| label | VARCHAR | 255 | - | No |
| description | VARCHAR | 255 | - | Yes |
| is_system | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.7 — `permissions`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| name | VARCHAR | 255 | Unique | No |
| label | VARCHAR | 255 | - | No |
| group | VARCHAR | 255 | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.8 — `permission_role`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| permission_id | BIGINT | - | Primary Key, Foreign Key (permissions) | No |
| role_id | BIGINT | - | Primary Key, Foreign Key (roles) | No |

**Table 3.9 — `role_user`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| role_id | BIGINT | - | Primary Key, Foreign Key (roles) | No |
| user_id | BIGINT | - | Primary Key, Foreign Key (users) | No |

**Table 3.10 — `push_subscriptions`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| subscribable_type | VARCHAR | 255 | - | No |
| subscribable_id | BIGINT | - | - | No |
| endpoint | VARCHAR | 500 | Unique | No |
| public_key | VARCHAR | 255 | - | Yes |
| auth_token | VARCHAR | 255 | - | Yes |
| content_encoding | VARCHAR | 255 | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.11 — `organizations`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| name | VARCHAR | 255 | - | No |
| slug | VARCHAR | 255 | Unique | No |
| legal_name | VARCHAR | 255 | - | Yes |
| logo | VARCHAR | 255 | - | Yes |
| email | VARCHAR | 255 | - | Yes |
| phone | VARCHAR | 255 | - | Yes |
| address | TEXT | - | - | Yes |
| tin | VARCHAR | 255 | - | Yes |
| sss_employer_no | VARCHAR | 255 | - | Yes |
| philhealth_employer_no | VARCHAR | 255 | - | Yes |
| pagibig_employer_no | VARCHAR | 255 | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.12 — `organization_user`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| user_id | BIGINT | - | Foreign Key (users) | No |
| is_default | BOOLEAN | - | - | No |
| joined_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.13 — `departments`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| name | VARCHAR | 255 | - | No |
| code | VARCHAR | 255 | Unique | No |
| parent_id | BIGINT | - | Foreign Key (departments) | Yes |
| head_id | BIGINT | - | Foreign Key (employees) | Yes |
| description | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.14 — `positions`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| title | VARCHAR | 255 | - | No |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| salary_grade_min | NUMERIC | 12, 2 | - | Yes |
| salary_grade_max | NUMERIC | 12, 2 | - | Yes |
| description | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.15 — `work_schedules`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| name | VARCHAR | 255 | - | No |
| start_time | TIME | - | - | Yes |
| end_time | TIME | - | - | Yes |
| work_days | JSON | - | - | Yes |
| grace_minutes | SMALLINT | - | - | No |
| required_hours | NUMERIC | 5, 2 | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.16 — `holidays`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| date | DATE | - | - | No |
| type | VARCHAR | 255 | - | No |
| is_recurring | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.17 — `employees`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| user_id | BIGINT | - | Foreign Key (users) | Yes |
| employee_no | VARCHAR | 255 | Unique | No |
| first_name | VARCHAR | 255 | - | No |
| middle_name | VARCHAR | 255 | - | Yes |
| last_name | VARCHAR | 255 | - | No |
| suffix | VARCHAR | 255 | - | Yes |
| birth_date | DATE | - | - | Yes |
| gender | VARCHAR | 255 | - | Yes |
| civil_status | VARCHAR | 255 | - | Yes |
| email | VARCHAR | 255 | - | Yes |
| phone | VARCHAR | 255 | - | Yes |
| address | TEXT | - | - | Yes |
| photo | VARCHAR | 255 | - | Yes |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| position_id | BIGINT | - | Foreign Key (positions) | Yes |
| manager_id | BIGINT | - | Foreign Key (employees) | Yes |
| work_schedule_id | BIGINT | - | Foreign Key (work_schedules) | Yes |
| employment_type | VARCHAR | 255 | - | No |
| employment_status | VARCHAR | 255 | - | No |
| date_hired | DATE | - | - | No |
| date_regularized | DATE | - | - | Yes |
| basic_salary | NUMERIC | 12, 2 | - | Yes |
| bank_name | VARCHAR | 255 | - | Yes |
| bank_account_no | VARCHAR | 255 | - | Yes |
| tin | VARCHAR | 255 | - | Yes |
| sss_no | VARCHAR | 255 | - | Yes |
| philhealth_no | VARCHAR | 255 | - | Yes |
| pagibig_no | VARCHAR | 255 | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.18 — `employee_documents`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| title | VARCHAR | 255 | - | No |
| type | VARCHAR | 255 | - | No |
| file | VARCHAR | 255 | - | No |
| uploaded_by | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.19 — `employee_certifications`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| name | VARCHAR | 255 | - | No |
| issuer | VARCHAR | 255 | - | Yes |
| issued_date | DATE | - | - | Yes |
| expiry_date | DATE | - | - | Yes |
| file | VARCHAR | 255 | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.20 — `employee_promotions`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| from_position_id | BIGINT | - | Foreign Key (positions) | Yes |
| to_position_id | BIGINT | - | Foreign Key (positions) | Yes |
| from_salary | NUMERIC | 12, 2 | - | Yes |
| to_salary | NUMERIC | 12, 2 | - | Yes |
| effective_date | DATE | - | - | No |
| reason | TEXT | - | - | Yes |
| approved_by | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |

**Table 3.21 — `job_postings`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| title | VARCHAR | 255 | - | No |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| position_id | BIGINT | - | Foreign Key (positions) | Yes |
| description | TEXT | - | - | Yes |
| requirements | TEXT | - | - | Yes |
| employment_type | VARCHAR | 255 | - | No |
| openings | SMALLINT | - | - | No |
| status | VARCHAR | 255 | - | No |
| closing_date | DATE | - | - | Yes |
| posted_by | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| min_years_experience | SMALLINT | - | - | Yes |
| skills | JSON | - | - | Yes |

**Table 3.22 — `applicants`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| first_name | VARCHAR | 255 | - | No |
| last_name | VARCHAR | 255 | - | No |
| email | VARCHAR | 255 | - | Yes |
| phone | VARCHAR | 255 | - | Yes |
| headline | VARCHAR | 255 | - | Yes |
| source | VARCHAR | 255 | - | No |
| resume | VARCHAR | 255 | - | Yes |
| notes | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| current_location | VARCHAR | 255 | - | Yes |
| linkedin_url | VARCHAR | 255 | - | Yes |
| portfolio_url | VARCHAR | 255 | - | Yes |
| years_experience | SMALLINT | - | - | Yes |

**Table 3.23 — `applicant_documents`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| applicant_id | BIGINT | - | Foreign Key (applicants) | No |
| title | VARCHAR | 255 | - | No |
| type | VARCHAR | 255 | - | No |
| file | VARCHAR | 255 | - | No |
| uploaded_by | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.24 — `job_applications`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| job_posting_id | BIGINT | - | Foreign Key (job_postings) | No |
| applicant_id | BIGINT | - | Foreign Key (applicants) | No |
| stage | VARCHAR | 255 | - | No |
| rating | SMALLINT | - | - | Yes |
| expected_salary | NUMERIC | 12, 2 | - | Yes |
| cover_note | TEXT | - | - | Yes |
| rejected_reason | TEXT | - | - | Yes |
| hired_employee_id | BIGINT | - | Foreign Key (employees) | Yes |
| applied_at | TIMESTAMP | - | - | Yes |
| decided_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| ai_insights | JSON | - | - | Yes |

**Table 3.25 — `interviews`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| job_application_id | BIGINT | - | Foreign Key (job_applications) | No |
| interviewer_id | BIGINT | - | Foreign Key (users) | Yes |
| scheduled_at | TIMESTAMP | - | - | No |
| mode | VARCHAR | 255 | - | No |
| location | VARCHAR | 255 | - | Yes |
| notes | TEXT | - | - | Yes |
| result | VARCHAR | 255 | - | No |
| feedback | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.26 — `onboarding_programs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| employment_type | VARCHAR | 255 | - | Yes |
| is_default | BOOLEAN | - | - | No |
| is_active | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.27 — `onboarding_program_tasks`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| onboarding_program_id | BIGINT | - | Foreign Key (onboarding_programs) | No |
| title | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| category | VARCHAR | 255 | - | No |
| due_offset_days | SMALLINT | - | - | No |
| sort_order | INTEGER | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.28 — `onboarding_cases`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| onboarding_program_id | BIGINT | - | Foreign Key (onboarding_programs) | Yes |
| status | VARCHAR | 255 | - | No |
| start_date | DATE | - | - | No |
| target_end_date | DATE | - | - | Yes |
| completed_at | TIMESTAMP | - | - | Yes |
| notes | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.29 — `onboarding_tasks`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| onboarding_case_id | BIGINT | - | Foreign Key (onboarding_cases) | No |
| title | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| category | VARCHAR | 255 | - | No |
| assigned_to | BIGINT | - | Foreign Key (users) | Yes |
| due_date | DATE | - | - | Yes |
| status | VARCHAR | 255 | - | No |
| completed_at | TIMESTAMP | - | - | Yes |
| completed_by | BIGINT | - | Foreign Key (users) | Yes |
| sort_order | INTEGER | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.30 — `leave_types`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| code | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| color | VARCHAR | 20 | - | No |
| default_days | NUMERIC | 5, 1 | - | No |
| is_paid | BOOLEAN | - | - | No |
| allow_half_day | BOOLEAN | - | - | No |
| requires_approval | BOOLEAN | - | - | No |
| is_active | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.31 — `leave_balances`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| leave_type_id | BIGINT | - | Foreign Key (leave_types) | No |
| year | SMALLINT | - | Unique | No |
| entitled_days | NUMERIC | 5, 1 | - | No |
| note | VARCHAR | 255 | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.32 — `leave_requests`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| leave_type_id | BIGINT | - | Foreign Key (leave_types) | No |
| start_date | DATE | - | - | No |
| end_date | DATE | - | - | No |
| days | NUMERIC | 4, 1 | - | No |
| is_half_day | BOOLEAN | - | - | No |
| half_day_period | VARCHAR | 255 | - | Yes |
| reason | TEXT | - | - | Yes |
| status | VARCHAR | 255 | - | No |
| filed_by | BIGINT | - | Foreign Key (users) | Yes |
| reviewed_by | BIGINT | - | Foreign Key (users) | Yes |
| reviewed_at | TIMESTAMP | - | - | Yes |
| review_note | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.33 — `attendance_punches`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| attendance_record_id | BIGINT | - | Foreign Key (attendance_records) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| type | VARCHAR | 255 | - | No |
| punched_at | TIMESTAMP | - | - | No |
| source | VARCHAR | 255 | - | No |
| latitude | NUMERIC | 10, 7 | - | Yes |
| longitude | NUMERIC | 10, 7 | - | Yes |
| accuracy | NUMERIC | 8, 2 | - | Yes |
| photo | VARCHAR | 255 | - | Yes |
| note | VARCHAR | 255 | - | Yes |
| recorded_by | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.34 — `attendance_records`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| work_date | DATE | - | Unique | No |
| work_schedule_id | BIGINT | - | Foreign Key (work_schedules) | Yes |
| scheduled_start | TIME | - | - | Yes |
| scheduled_end | TIME | - | - | Yes |
| status | VARCHAR | 255 | - | No |
| first_in_at | TIMESTAMP | - | - | Yes |
| last_out_at | TIMESTAMP | - | - | Yes |
| worked_minutes | INTEGER | - | - | No |
| break_minutes | INTEGER | - | - | No |
| late_minutes | INTEGER | - | - | No |
| undertime_minutes | INTEGER | - | - | No |
| overtime_minutes | INTEGER | - | - | No |
| is_manual | BOOLEAN | - | - | No |
| remarks | TEXT | - | - | Yes |
| approval_status | VARCHAR | 255 | - | Yes |
| approved_by | BIGINT | - | Foreign Key (users) | Yes |
| approved_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.35 — `evaluation_periods`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| start_date | DATE | - | - | No |
| end_date | DATE | - | - | No |
| status | VARCHAR | 255 | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.36 — `kpi_criteria`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| weight | NUMERIC | 6, 2 | - | No |
| is_active | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |
| scale_type | VARCHAR | 255 | - | No |
| scale_min | NUMERIC | 6, 2 | - | No |
| scale_max | NUMERIC | 6, 2 | - | No |
| scale_levels | JSON | - | - | Yes |

**Table 3.37 — `performance_evaluations`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| evaluation_period_id | BIGINT | - | Foreign Key (evaluation_periods) | No |
| evaluator_id | BIGINT | - | Foreign Key (users) | Yes |
| overall_score | NUMERIC | 5, 2 | - | Yes |
| status | VARCHAR | 255 | - | No |
| submitted_at | TIMESTAMP | - | - | Yes |
| acknowledged_at | TIMESTAMP | - | - | Yes |
| remarks | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| ai_insights | JSON | - | - | Yes |

**Table 3.38 — `performance_scores`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| performance_evaluation_id | BIGINT | - | Foreign Key (performance_evaluations) | No |
| kpi_criterion_id | BIGINT | - | Foreign Key (kpi_criteria) | Yes |
| label | VARCHAR | 255 | - | No |
| weight | NUMERIC | 6, 2 | - | No |
| score | NUMERIC | 5, 2 | - | Yes |
| remarks | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| scale_type | VARCHAR | 255 | - | No |
| scale_min | NUMERIC | 6, 2 | - | No |
| scale_max | NUMERIC | 6, 2 | - | No |
| scale_levels | JSON | - | - | Yes |

**Table 3.39 — `training_programs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| provider | VARCHAR | 255 | - | Yes |
| start_date | DATE | - | - | Yes |
| end_date | DATE | - | - | Yes |
| capacity | INTEGER | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |
| ai_insights | JSON | - | - | Yes |

**Table 3.40 — `training_enrollments`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| training_program_id | BIGINT | - | Foreign Key (training_programs) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| status | VARCHAR | 255 | - | No |
| score | NUMERIC | 5, 2 | - | Yes |
| completed_at | TIMESTAMP | - | - | Yes |
| remarks | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.41 — `award_types`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| color | VARCHAR | 255 | - | Yes |
| is_active | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.42 — `employee_awards`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| award_type_id | BIGINT | - | Foreign Key (award_types) | No |
| awarded_on | DATE | - | - | No |
| reason | TEXT | - | - | Yes |
| awarded_by | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.43 — `events`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| title | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| type | VARCHAR | 255 | - | No |
| starts_at | TIMESTAMP | - | - | No |
| ends_at | TIMESTAMP | - | - | Yes |
| location | VARCHAR | 255 | - | Yes |
| organizer_id | BIGINT | - | Foreign Key (users) | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| deleted_at | TIMESTAMP | - | - | Yes |

**Table 3.44 — `event_attendees`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| event_id | BIGINT | - | Foreign Key (events) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| response | VARCHAR | 255 | - | No |
| notified_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.45 — `offboarding_programs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | - | No |
| description | TEXT | - | - | Yes |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| exit_type | VARCHAR | 255 | - | Yes |
| is_default | BOOLEAN | - | - | No |
| is_active | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.46 — `offboarding_program_items`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| offboarding_program_id | BIGINT | - | Foreign Key (offboarding_programs) | No |
| item | VARCHAR | 255 | - | No |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| use_employee_department | BOOLEAN | - | - | No |
| sort_order | INTEGER | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.47 — `offboarding_cases`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| type | VARCHAR | 255 | - | No |
| notice_date | DATE | - | - | Yes |
| last_working_day | DATE | - | - | Yes |
| reason | TEXT | - | - | Yes |
| status | VARCHAR | 255 | - | No |
| completed_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| offboarding_program_id | BIGINT | - | Foreign Key (offboarding_programs) | Yes |

**Table 3.48 — `clearance_items`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| offboarding_case_id | BIGINT | - | Foreign Key (offboarding_cases) | No |
| item | VARCHAR | 255 | - | No |
| department_id | BIGINT | - | Foreign Key (departments) | Yes |
| status | VARCHAR | 255 | - | No |
| remarks | TEXT | - | - | Yes |
| cleared_by | BIGINT | - | Foreign Key (users) | Yes |
| cleared_at | TIMESTAMP | - | - | Yes |
| sort_order | INTEGER | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.49 — `attrition_risk_runs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| generated_by | BIGINT | - | Foreign Key (users) | Yes |
| status | VARCHAR | 255 | - | No |
| model_version | VARCHAR | 255 | - | Yes |
| employees_scored | INTEGER | - | - | No |
| high_count | INTEGER | - | - | No |
| medium_count | INTEGER | - | - | No |
| low_count | INTEGER | - | - | No |
| average_score | NUMERIC | 5, 2 | - | Yes |
| average_confidence | NUMERIC | 4, 3 | - | Yes |
| note | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.50 — `attrition_risk_scores`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| attrition_risk_run_id | BIGINT | - | Foreign Key (attrition_risk_runs) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| probability | NUMERIC | 6, 5 | - | No |
| score | NUMERIC | 5, 2 | - | No |
| tier | VARCHAR | 255 | - | No |
| confidence | NUMERIC | 4, 3 | - | No |
| features | JSON | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.51 — `performance_forecast_runs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| generated_by | BIGINT | - | Foreign Key (users) | Yes |
| target_period_id | BIGINT | - | Foreign Key (evaluation_periods) | Yes |
| status | VARCHAR | 255 | - | No |
| model_version | VARCHAR | 255 | - | Yes |
| employees_scored | INTEGER | - | - | No |
| exceeds_count | INTEGER | - | - | No |
| on_track_count | INTEGER | - | - | No |
| below_count | INTEGER | - | - | No |
| average_rating | NUMERIC | 5, 2 | - | Yes |
| average_confidence | NUMERIC | 4, 3 | - | Yes |
| note | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.52 — `performance_forecasts`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| performance_forecast_run_id | BIGINT | - | Foreign Key (performance_forecast_runs) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| predicted_rating | NUMERIC | 5, 2 | - | No |
| confidence | NUMERIC | 4, 3 | - | No |
| band | VARCHAR | 255 | - | No |
| features | JSON | - | - | Yes |
| history | JSON | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.53 — `promotion_readiness_runs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| generated_by | BIGINT | - | Foreign Key (users) | Yes |
| status | VARCHAR | 255 | - | No |
| model_version | VARCHAR | 255 | - | Yes |
| employees_scored | INTEGER | - | - | No |
| high_count | INTEGER | - | - | No |
| medium_count | INTEGER | - | - | No |
| low_count | INTEGER | - | - | No |
| average_score | NUMERIC | 5, 2 | - | Yes |
| note | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.54 — `promotion_readiness_scores`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| promotion_readiness_run_id | BIGINT | - | Foreign Key (promotion_readiness_runs) | No |
| employee_id | BIGINT | - | Foreign Key (employees) | No |
| probability | NUMERIC | 6, 5 | - | No |
| score | NUMERIC | 5, 2 | - | No |
| tier | VARCHAR | 255 | - | No |
| factors | JSON | - | - | Yes |
| features | JSON | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.55 — `assistant_conversations`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| user_id | BIGINT | - | Foreign Key (users) | No |
| title | VARCHAR | 255 | - | Yes |
| pinned | BOOLEAN | - | - | No |
| last_activity_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.56 — `assistant_messages`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| organization_id | BIGINT | - | Foreign Key (organizations) | No |
| conversation_id | BIGINT | - | Foreign Key (assistant_conversations) | No |
| role | VARCHAR | 255 | - | No |
| body | TEXT | - | - | Yes |
| steps | JSON | - | - | Yes |
| actions | JSON | - | - | Yes |
| attachments | JSON | - | - | Yes |
| failed | BOOLEAN | - | - | No |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.57 — `activity_logs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| log_name | VARCHAR | 255 | - | Yes |
| event | VARCHAR | 255 | - | No |
| description | TEXT | - | - | No |
| causer_id | BIGINT | - | Foreign Key (users) | Yes |
| subject_type | VARCHAR | 255 | - | Yes |
| subject_id | BIGINT | - | - | Yes |
| subject_label | VARCHAR | 255 | - | Yes |
| properties | JSON | - | - | Yes |
| ip_address | VARCHAR | 45 | - | Yes |
| user_agent | TEXT | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |
| organization_id | BIGINT | - | Foreign Key (organizations) | Yes |

**Table 3.58 — `notifications`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | UUID | - | Primary Key | No |
| type | VARCHAR | 255 | - | No |
| notifiable_type | VARCHAR | 255 | - | No |
| notifiable_id | BIGINT | - | - | No |
| data | TEXT | - | - | No |
| read_at | TIMESTAMP | - | - | Yes |
| created_at | TIMESTAMP | - | - | Yes |
| updated_at | TIMESTAMP | - | - | Yes |

**Table 3.59 — `cache`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| key | VARCHAR | 255 | Primary Key | No |
| value | TEXT | - | - | No |
| expiration | BIGINT | - | - | No |

**Table 3.60 — `cache_locks`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| key | VARCHAR | 255 | Primary Key | No |
| owner | VARCHAR | 255 | - | No |
| expiration | BIGINT | - | - | No |

**Table 3.61 — `jobs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| queue | VARCHAR | 255 | - | No |
| payload | TEXT | - | - | No |
| attempts | SMALLINT | - | - | No |
| reserved_at | INTEGER | - | - | Yes |
| available_at | INTEGER | - | - | No |
| created_at | INTEGER | - | - | No |

**Table 3.62 — `job_batches`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | VARCHAR | 255 | Primary Key | No |
| name | VARCHAR | 255 | - | No |
| total_jobs | INTEGER | - | - | No |
| pending_jobs | INTEGER | - | - | No |
| failed_jobs | INTEGER | - | - | No |
| failed_job_ids | TEXT | - | - | No |
| options | TEXT | - | - | Yes |
| cancelled_at | INTEGER | - | - | Yes |
| created_at | INTEGER | - | - | No |
| finished_at | INTEGER | - | - | Yes |

**Table 3.63 — `failed_jobs`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | - | Primary Key | No |
| uuid | VARCHAR | 255 | Unique | No |
| connection | VARCHAR | 255 | - | No |
| queue | VARCHAR | 255 | - | No |
| payload | TEXT | - | - | No |
| exception | TEXT | - | - | No |
| failed_at | TIMESTAMP | - | - | No |

**Table 3.64 — `migrations`**

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | INTEGER | - | Primary Key | No |
| migration | VARCHAR | 255 | - | No |
| batch | INTEGER | - | - | No |

### 3.8.3 Tables and columns added after the original data dictionary

The dictionary in Section 3.8.2 documented 64 tables. Five further tables and a set of new columns
were introduced by two later design decisions — self-served identity and workspace joining, and
tenant-defined appraisal frameworks — bringing the schema to **69 tables**. They are documented
here in the same format.

**Table 3.65 — `rating_scales`**  *(how a criterion is measured)*

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | – | Primary Key | No |
| organization_id | BIGINT | – | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | – | No |
| description | TEXT | – | – | Yes |
| type | VARCHAR | 255 | – | No |
| min | DECIMAL | 8,2 | – | No |
| max | DECIMAL | 8,2 | – | No |
| step | DECIMAL | 6,2 | – | No |
| levels | JSON | – | – | Yes |
| is_default | BOOLEAN | – | – | No |
| created_at | TIMESTAMP | – | – | Yes |
| updated_at | TIMESTAMP | – | – | Yes |
| deleted_at | TIMESTAMP | – | – | Yes |

`type` is one of `numeric`, `percentage`, or `levels`. `levels` holds the ordered named levels of a
descriptive scale as `[{value, label, description}]`.

**Table 3.66 — `review_templates`**  *(an appraisal framework)*

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | – | Primary Key | No |
| organization_id | BIGINT | – | Foreign Key (organizations) | No |
| name | VARCHAR | 255 | – | No |
| description | TEXT | – | – | Yes |
| rating_scale_id | BIGINT | – | Foreign Key (rating_scales) | Yes |
| sections | JSON | – | – | Yes |
| bands | JSON | – | – | Yes |
| result_display | VARCHAR | 255 | – | No |
| applies_to | VARCHAR | 255 | – | No |
| applies_to_values | JSON | – | – | Yes |
| is_default | BOOLEAN | – | – | No |
| is_active | BOOLEAN | – | – | No |
| created_at | TIMESTAMP | – | – | Yes |
| updated_at | TIMESTAMP | – | – | Yes |
| deleted_at | TIMESTAMP | – | – | Yes |

`sections` holds the weighted sections as `[{key, name, description, weight}]`; `bands` holds the
tenant's rating model as ordered outcome bands `[{key, label, min_percent, description, tone}]`;
`applies_to` is one of `all`, `department`, `position`, or `employment_type`, with the matching
values in `applies_to_values`.

**Table 3.67 — `review_template_items`**  *(one weighted line in a framework)*

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | – | Primary Key | No |
| organization_id | BIGINT | – | Foreign Key (organizations) | No |
| review_template_id | BIGINT | – | Foreign Key (review_templates) | No |
| kpi_criterion_id | BIGINT | – | Foreign Key (kpi_criteria) | Yes |
| rating_scale_id | BIGINT | – | Foreign Key (rating_scales) | Yes |
| section_key | VARCHAR | 255 | – | No |
| name | VARCHAR | 255 | – | No |
| description | TEXT | – | – | Yes |
| weight | DECIMAL | 6,2 | – | No |
| sort_order | INTEGER | – | – | No |
| created_at | TIMESTAMP | – | – | Yes |
| updated_at | TIMESTAMP | – | – | Yes |

`weight` is the item's weight **within its section**, not across the whole framework.

**Table 3.68 — `employee_invitations`**  *(a claim ticket for one roster line)*

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | – | Primary Key | No |
| organization_id | BIGINT | – | Foreign Key (organizations) | No |
| employee_id | BIGINT | – | Foreign Key (employees) | No |
| email | VARCHAR | 255 | – | No |
| token | VARCHAR | 64 | Unique | No |
| code | VARCHAR | 16 | Unique | No |
| invited_by | BIGINT | – | Foreign Key (users) | Yes |
| expires_at | TIMESTAMP | – | – | No |
| accepted_at | TIMESTAMP | – | – | Yes |
| accepted_by | BIGINT | – | Foreign Key (users) | Yes |
| revoked_at | TIMESTAMP | – | – | Yes |
| created_at | TIMESTAMP | – | – | Yes |
| updated_at | TIMESTAMP | – | – | Yes |

`token` is the SHA-256 of the e-mailed link and is never stored in the clear; `code` is the short
string a person retypes into the application. Both are unique **globally**, not per tenant, because
they are redeemed before any tenant is bound.

**Table 3.69 — `organization_join_requests`**  *(somebody who typed the join code but could not be matched automatically)*

| Attribute Name | Data Type | Max Length | Key Type | Null |
|---|---|---|---|---|
| id | BIGINT | – | Primary Key | No |
| organization_id | BIGINT | – | Foreign Key (organizations) | No |
| user_id | BIGINT | – | Foreign Key (users) | No |
| employee_id | BIGINT | – | Foreign Key (employees) | Yes |
| status | VARCHAR | 255 | – | No |
| decline_reason | VARCHAR | 255 | – | Yes |
| reviewed_by | BIGINT | – | Foreign Key (users) | Yes |
| reviewed_at | TIMESTAMP | – | – | Yes |
| created_at | TIMESTAMP | – | – | Yes |
| updated_at | TIMESTAMP | – | – | Yes |

A composite unique key on `(organization_id, user_id)` means re-asking after a decline revives the
same row rather than accumulating duplicates.

**Columns added to existing tables**

| Table | Column | Type | Null | Purpose |
|---|---|---|---|---|
| `organizations` | join_code | VARCHAR(16), Unique | Yes | The code a person types to ask to join this workspace |
| `organizations` | join_code_enabled | BOOLEAN | No | Switches the join code off without discarding it |
| `kpi_criteria` | rating_scale_id | BIGINT FK (rating_scales) | Yes | The scale this criterion is measured on |
| `kpi_criteria` | sort_order | INTEGER | No | Display order in the catalogue |
| `performance_evaluations` | review_template_id | BIGINT FK (review_templates) | Yes | The framework the appraisal was opened under |
| `performance_evaluations` | template_name | VARCHAR(255) | Yes | Snapshot of the framework name |
| `performance_evaluations` | template_sections | JSON | Yes | Snapshot of the weighted sections |
| `performance_evaluations` | template_bands | JSON | Yes | Snapshot of the tenant rating model |
| `performance_evaluations` | result_display | VARCHAR(255) | No | `band`, `percent`, or `points` |
| `performance_evaluations` | overall_percent | DECIMAL(5,2) | Yes | **The canonical 0–100 attainment** |
| `performance_evaluations` | result_band | VARCHAR(255) | Yes | The resolved band key |
| `performance_evaluations` | result_label | VARCHAR(255) | Yes | The words the tenant reports in |
| `performance_scores` | review_template_item_id | BIGINT FK | Yes | Provenance of the line |
| `performance_scores` | section_key | VARCHAR(255) | No | Snapshot of the section this line belongs to |
| `performance_scores` | section_name | VARCHAR(255) | Yes | Snapshot of the section name |
| `performance_scores` | section_weight | DECIMAL(6,2) | No | Snapshot of the section weight |
| `performance_scores` | description | TEXT | Yes | Snapshot of the criterion description |
| `performance_scores` | scale_name | VARCHAR(255) | Yes | Snapshot of the scale name |
| `performance_scores` | sort_order | INTEGER | No | Display order within the section |

---

## 3.9 Functional Requirements (System Features)

**FR-01 Authentication and account security.** The system shall authenticate users by e-mail and
password with verification and reset, shall support optional TOTP two-factor authentication and
passkey sign-in, and shall throttle repeated failed logins on both the web and mobile entry points.

**FR-02 User, role, and permission management.** The system shall manage user accounts including
creation, editing, archiving, restoration, activation, and export, and shall provide three built-in
roles — HR Manager, Department Head, and Staff — plus custom roles composed from a catalogue of
**73 granular permissions across 16 groups**, enforced on every route and action. Because a user is
a global identity, role assignment shall reconcile only within the active organization's roles, so
that a synchronisation cannot remove a person's roles at another employer.

**FR-03 Multi-organization support.** The system shall isolate the records of each organization at
row level, shall allow one account to belong to multiple organizations, and shall allow switching
the active organization on both web and mobile, re-issuing a mobile token bound to the newly
selected organization.

**FR-04 Self-served identity and workspace admission.** The system shall allow a person to register
their own identity independently of any employer; shall allow HR to invite a specific roster line
by a hashed link token plus a retypeable code; shall allow a person to request admission with an
organization join code, admitting them immediately on an exact unclaimed e-mail match and otherwise
queueing the request for HR approval; and shall route both paths through a single admission
operation. The system shall **not** create a login as a side effect of hiring, and shall not permit
HR to set or reset another person's password.

**FR-05 Employee management (201 file).** The system shall maintain the employee directory with
personal, employment, and compensation details; shall manage documents, certifications, and career
history; shall auto-generate employee numbers; shall write a promotion history row automatically on
any position or salary change; shall support archive, restore, and export; and shall store the four
Philippine government identifiers encrypted at rest.

**FR-06 Recruitment and applicant tracking.** The system shall manage job postings with optional
screening criteria and a closing date that is required once a posting is open, with daily automatic
closing of past-due postings; shall publish open postings on a public, rate-limited, bot-guarded
careers page; shall track applications through a configurable pipeline with interviews and blind
scorecards; shall rank candidates automatically with a deterministic fit score and a recommended
next step; shall generate AI candidate insights from the actual résumé and supporting documents;
and shall convert a hired applicant into an employee within a single transaction.

**FR-07 Onboarding management.** The system shall maintain reusable onboarding templates targeted
by department and/or employment type, shall open one case per hire, shall instantiate blueprint
tasks from relative day offsets into real dates, shall resolve task ownership to real people at
seed time, shall track derived progress to completion, and shall chase overdue work grouped per
owner.

**FR-08 Attendance and time management.** The system shall record web and mobile DTR punches with
source, GPS, and an optional selfie; shall derive one daily record per employee per day with today,
weekly, and monthly views; shall present every employee in the roster regardless of whether they
punched; shall support corrections, approval, and bulk approval; shall export to CSV; and shall
evaluate punches against work schedules, the holiday calendar, and approved leave.

**FR-09 Leave management.** The system shall maintain leave types and per-employee, per-year
entitlements; shall let employees file and cancel requests with server-computed, weekend- and
holiday-aware day counts; shall derive used, pending, and remaining figures from the requests
themselves; and shall route requests for approval with notifications.

**FR-10 Performance management.** The system shall let a tenant define its own rating scales,
criteria catalogue, weighted appraisal frameworks with eligibility rules, and outcome band model;
shall resolve the narrowest matching framework while permitting override; shall freeze the
framework and every scale onto the appraisal at the moment it is opened; shall score at two levels
producing a canonical 0–100 attainment, a tenant-worded band, and a 1–5 projection; shall block
submission until every criterion is rated; shall support sign-off and acknowledgement; shall
present per-cycle calibration including per-department deviation; and shall generate AI coaching
insights.

**FR-11 Training and development.** The system shall manage training programmes with
schedule-derived status and seat capacity, shall enrol employees individually or in bulk, shall
block enrolment into a full programme, shall record completions and scores, and shall generate AI
programme effectiveness insights.

**FR-12 Awards and recognition.** The system shall maintain award types, shall record recognitions
on a feed, shall present a nomination board ranking employees per award type from six weighted
signals with a repeat-winner fairness guard, and shall draft citation text with AI assistance that
the granter edits before saving.

**FR-13 Events and meetings.** The system shall schedule events with date-derived status, shall
invite and notify attendees, shall track responses, shall remind pending invitees, and shall
support duplication plus roster, CSV, and iCalendar export.

**FR-14 Offboarding and clearance.** The system shall open exit cases with department-grouped
clearance items seeded from templates, shall support item-level and bulk clearing with a derived
clearance status in which a flagged item blocks clearance, shall export clearance data, and shall
transition the employee's employment status on completion.

**FR-15 Predictive workforce analytics.** The system shall run batch attrition risk, performance
forecast, and promotion readiness assessments through the inference service; shall persist each run
with the model version, per-employee scores, tiers or bands, explanations or confidence, and
feature snapshots; shall never write a prediction onto the employee record; and shall degrade
gracefully to previously stored results with an offline notice when the service is unreachable.

**FR-16 LLM assistant.** The system shall provide a conversational assistant that executes HR
operations through a bounded function-calling loop; shall expose to the model only the tools the
requesting user is permitted to use and shall re-check that permission on execution; shall enforce
every action on the server through the same canonical classes the screens use; shall refuse to
perform negative or irreversible outcomes without an explicit human decision; shall treat retrieved
content and uploaded documents as data and never as instructions; shall withhold nine
disclosure-restricted employee fields from every read; shall accept uploaded documents for
structured extraction; and shall persist conversations.

**FR-17 Dashboard and reports.** The system shall present a permission-aware dashboard of HR
metrics, charts, an action queue, and an activity feed; and shall provide seven reports, each
gated on an existing module permission, with charts derived from the whole result set, ML signals
read from stored runs, on-demand LLM insights computed from a server-side re-run, and CSV/XLSX
export with optional PII redaction.

**FR-18 Company setup.** The system shall manage the company profile and join code, departments and
positions, work schedules and holidays, leave types, rating scales, criteria, appraisal frameworks
and review cycles, award types, and onboarding and offboarding templates, each behind view and
manage permissions.

**FR-19 Notifications.** The system shall deliver in-application, e-mail, and browser push
notifications for workflow events, honouring user preferences, and shall not allow the failure of
one channel to abort the others.

**FR-20 Activity logging.** The system shall record every user-facing mutation in an append-only
log with actor, action, subject, and timestamp, and shall support viewing, exporting, and clearing
under dedicated permissions.

**FR-21 Mobile self-service API.** The system shall expose a token-authenticated API for
registration, login, workspace preview and join, invitation listing and acceptance, DTR punching,
attendance views, leave filing and balances, awards, profile, and organization switching, with
every endpoint scoped to the caller's own data.

---

## 3.10 Non-Functional Requirements

| ID | Requirement | How it is met, and how it is verified |
|---|---|---|
| NFR-01 **Tenant isolation** | No request shall be able to read or write another organization's rows, irrespective of the requester's role. | Global query scope beneath the query layer; verified by feature tests that assert cross-tenant reads return empty and cross-tenant writes fail. |
| NFR-02 **Authorization completeness** | Every route and every mutating action shall be gated by a named permission. | Permission catalogue defined in code; verified by a page-smoke test that walks every page, download, and gate. |
| NFR-03 **Auditability** | Every user-facing mutation shall leave an append-only audit entry naming actor, event, and subject. | Single logging call site; verified by module feature tests. |
| NFR-04 **Feedback** | Every mutating action shall return an explicit outcome message unless silence is a documented decision. | 202 of 208 mutating actions emit a flash message; verified by a shared test assertion. |
| NFR-05 **Graceful degradation** | No core HR function shall become unavailable because an AI or ML dependency is unavailable. | Health check before prediction; typed unreachable exception; stored-run fallback; disabled-panel behaviour when the API key is absent. |
| NFR-06 **Determinism of decision support** | Every non-ML score shall be reproducible from its inputs. | Pure, side-effect-free scorer classes; verified by unit tests on worked examples. |
| NFR-07 **Explainability** | Every score shown to a user shall be accompanied by its breakdown or by an honest statement of what is unknown. | Component breakdowns on deterministic scores; per-feature factors for the linear model; confidence as a data-coverage measure elsewhere. |
| NFR-08 **Data protection** | Government identifiers shall be encrypted at rest and personal documents shall not be web-reachable. | Encrypted casts; private disk with routed, logged delivery. |
| NFR-09 **Prompt-injection resistance** | Content retrieved from records or uploaded documents shall never be able to change system behaviour. | Data-not-instruction rule in the system prompt; permission re-check in code; control-character stripping and length caps on retrieved text. |
| NFR-10 **Cost and latency bounds** | An assistant turn shall have a bounded number of external API calls. | Hard ceiling of six tool round-trips; local reply synthesis when every call succeeded. |
| NFR-11 **Responsiveness at scale** | Candidate ranking shall remain usable above 200 candidates. | Server-side ranking and pagination above the threshold; fit score persisted and invalidated by input hash. |
| NFR-12 **Portability of deployment** | The whole system, minus the LLM, shall be self-hostable on one institution-owned machine. | Single-host deployment (Figure 3.2); inference service bound to loopback. |

---

## 3.11 Developmental Tools

### Table 3.70 — Developmental Tools and Cost

**Hardware**

| Name | Purpose | Price | Qty | Total |
|---|---|---|---|---|
| Developer workstation | Coding, testing, and running local services | Existing | 1 | 0 |
| Android / iOS device | Mobile testing for the Expo DTR companion | Existing | 1 | 0 |
| | | | **Grand total** | **0** |

**Programming languages**

| Name | Purpose | Price | Qty | Total |
|---|---|---|---|---|
| PHP 8.3 | Backend application logic and HR module development | Free | 1 | 0 |
| TypeScript 5.7 | Statically typed web frontend | Free | 1 | 0 |
| Python 3.12 / 3.14 | ML model training and the inference service | Free | 1 | 0 |
| | | | **Grand total** | **0** |

**Frameworks**

| Name | Purpose | Price | Qty | Total |
|---|---|---|---|---|
| Laravel 13 | Backend framework — routing, ORM, scheduling, notifications | Free | 1 | 0 |
| Laravel Fortify | Authentication scaffolding, two-factor, passkeys | Free | 1 | 0 |
| Laravel Sanctum | Token authentication for the mobile API | Free | 1 | 0 |
| React 19 | Component-based web frontend | Free | 1 | 0 |
| Inertia.js 3 | Server-driven SPA bridge between Laravel and React | Free | 1 | 0 |
| Expo (React Native) | Cross-platform employee self-service application | Free | 1 | 0 |
| FastAPI + Uvicorn | ML inference service exposing health and prediction endpoints | Free | 1 | 0 |
| | | | **Grand total** | **0** |

**Libraries**

| Name | Purpose | Price | Qty | Total |
|---|---|---|---|---|
| Tailwind CSS 4 | Utility-first responsive styling | Free | 1 | 0 |
| shadcn/ui (Radix UI) | Accessible, composable UI component primitives | Free | 1 | 0 |
| Laravel Wayfinder | Type-checked route bindings between PHP and TypeScript | Free | 1 | 0 |
| sonner | Toast feedback rendering | Free | 1 | 0 |
| laravel-notification-channels/webpush | VAPID web push delivery | Free | 1 | 0 |
| scikit-learn | ML pipelines, training, and evaluation | Free | 1 | 0 |
| pandas / NumPy | Dataset loading, cleaning, preprocessing, numerical computing | Free | 1 | 0 |
| Matplotlib + Seaborn | EDA and model evaluation plots | Free | 1 | 0 |
| joblib | Pipeline persistence and artifact serialization | Free | 1 | 0 |
| Pest 4 | Automated backend testing | Free | 1 | 0 |
| | | | **Grand total** | **0** |

**Version control, design, and database**

| Name | Purpose | Price | Qty | Total |
|---|---|---|---|---|
| Git | Local source version control | Free | 1 | 0 |
| GitHub | Remote repository, collaboration, CI | Free | 1 | 0 |
| Figma | UI/UX wireframing, prototyping, and diagram authoring | Free tier | 1 | 0 |
| PostgreSQL 16 | Primary relational database — 69 tables, row-level multi-tenancy | Free | 1 | 0 |
| | | | **Grand total** | **0** |

**Cloud, AI, and other tooling**

| Name | Purpose | Price | Qty | Total |
|---|---|---|---|---|
| DigitalOcean | Cloud hosting for the application, database, and inference service | Pay-per-use | 1 | — |
| Google Gemini API (`gemini-2.5-flash`) | LLM assistant, document reading, and AI insights — server-side only | Pay-per-use | 1 | — |
| JupyterLab | Model experimentation and training notebooks | Free | 1 | 0 |
| Vite 8 | Frontend asset bundling | Free | 1 | 0 |
| Laravel Pint + ESLint + Prettier | Code formatting and linting across PHP and TypeScript | Free | 1 | 0 |
| | | | **Grand total** | **—** |

> **Note on the test database.** The automated suite is configured for an in-memory SQLite
> connection, but the development environment's PHP build does not ship the SQLite driver, so the
> suite is executed against a disposable PostgreSQL database that the suite migrates on each run.
> Because the committed default remains SQLite while the application runs on PostgreSQL, all
> database code is written to be driver-agnostic and any driver-specific SQL is reviewed by hand as
> well as tested. This is recorded here rather than omitted, because it is a real constraint on how
> the tests of Section 3.12.1 are run.

---

## 3.12 Testing and Evaluation

Evaluation proceeds on three coordinated fronts: software testing of the platform, quantitative
evaluation of the three predictive models against thresholds defined in advance, and a user
acceptability evaluation based on ISO/IEC 25010.

### 3.12.1 Software testing

**Unit and feature testing.** Tests written in Pest exercise the business rules of the backend —
validation, authorization, tenancy isolation, module workflows, and API behaviour — on every
change, alongside static analysis, type checking, and formatting enforced as a continuous
integration gate. The suite currently comprises 47 test files and on the order of 500 test cases,
and the standing requirement is that it is **green at the end of every increment**: any failure is
treated as a regression introduced by the increment, not as a pre-existing condition to be worked
around.

**Coverage-by-construction tests.** Two suites deserve naming because they are methodological
instruments rather than ordinary tests. A **page-smoke test** walks every page, every download, and
every permission gate in the application, which converts NFR-02 from an assertion into a checked
property and makes it the first place to look when a screen breaks. A shared **toast assertion**
pins the feedback contract of NFR-04 across all mutating actions.

**Integration testing.** Integration tests verify the seams between tiers: the web client against
the server, the mobile API against token authentication and tenant binding, the ML client against
the inference service *including its offline degradation path*, and the assistant's tool executions
against the same support classes the screens use.

**User acceptance testing.** Conducted at Mega Plywood Corporation, where representative HR
personnel, department heads, and staff perform scripted, module-by-module task scenarios. Observed
issues feed back into refinement iterations.

### 3.12.2 Machine learning evaluation

#### 3.12.2.1 Protocol

Each model is evaluated on its untouched 20 % test partition and compared against a naive baseline
— the majority-class predictor for classifiers, the mean predictor for the regressor. Beyond the
headline metrics, the evaluation includes stratified five-fold cross-validation scores to confirm
stability, confusion matrices at the tuned thresholds, calibration checks of the
probability-derived scores, and an explainability review in which feature importances and
per-feature contributions are inspected for face validity and for any unwanted dependence. A model
that fails any threshold is not deployed; the corresponding analytics module reports that no model
is available, consistent with the graceful-degradation policy of NFR-05.

#### 3.12.2.2 Acceptance thresholds

**Table 3.71 — Machine Learning Acceptance Thresholds (success criteria per model)**

| Model (algorithm) | Metric | Acceptance threshold |
|---|---|---|
| Attrition Risk (Random Forest) | ROC-AUC | at least 0.70 |
| | PR-AUC | above the 0.161 positive-rate baseline |
| | Recall on leavers at the tuned threshold | at least 0.60 |
| Performance Forecast (Hist. Gradient Boosting) | R² | at least 0.80 |
| | MAE on the 40–100 scale | at most 5.0 points |
| | RMSE | at most 6.0 points |
| Promotion Readiness (Logistic Regression) | ROC-AUC | at least 0.80 |
| | PR-AUC | above the 0.10 positive-rate baseline |
| | Recall on promoted at the tuned threshold | at least 0.60 |

The thresholds encode the decision-support role of the models: the classifiers must demonstrably
outperform chance on imbalanced targets with recall favoured on the minority class, and the
regressor must forecast within a practically useful error band.

#### 3.12.2.3 External validity — a limitation stated in the protocol, not in a footnote

The metrics obtained under Section 3.12.2.1 are **test scores on the models' source datasets, not
on a Philippine roster**, and the evaluation protocol therefore includes an explicit
transportability step rather than treating source-dataset performance as the finding. Four specific
risks are carried forward into Chapter 4 and Chapter 5:

1. **Distribution shift.** Attrition trains on a 1,470-row public dataset from a foreign
   organization; performance and promotion train on a 100,000-row promotion dataset. Strong
   in-sample discrimination says less than it appears to about behaviour on a real Philippine
   workforce.
2. **Construct shift on the promotion target.** Prior validation against a synthetic Philippine
   panel found the promotion model's real-world discrimination far below its source-dataset figure,
   because the model encodes *improvement* rather than *level*. The readiness score is therefore
   presented as a conversation starter for succession planning, never as a verdict, and the
   analytics screen is worded accordingly.
3. **Silent category neutralisation.** Because the one-hot encoder ignores unknown categories, a
   department name the model never saw is neutralised rather than raising an error. This is a safe
   failure but a silent one, and it must be checked rather than assumed.
4. **Inert monetary features.** Peso salaries scored against a feature learned in a foreign currency
   are close to inert; the magnitudes simply do not correspond.

**Mitigation, and the planned successor.** The models are deployed as triage instruments with the
above stated on the screens themselves, and every prediction is stored with its feature snapshot so
that a later audit can distinguish a model error from a data-coverage problem. The natural next
step, already identified, is retraining attrition on the institution's own accumulated exit history
once enough offboarding cases exist — at which point the model stops being borrowed and becomes the
institution's own. This study reports the transportability limitation as a finding rather than
suppressing it.

**Interpretation guidance for the reader.** A classifier at ROC-AUC ≈ 0.74, at its tuned threshold,
identifies roughly six in ten leavers at roughly four-in-ten precision. That is genuinely useful for
deciding who to have a conversation with, and it is completely unsuitable as evidence in any
decision *about* a person. This distinction is stated in the paper because it is the distinction
that determines whether the system is used responsibly.

### 3.12.3 System acceptability evaluation

The validated ISO/IEC 25010-based instrument will be administered to the five-member expert panel
from Mega Plywood Corporation after they complete the acceptance testing scenarios. Responses are
averaged per characteristic — functional suitability, performance efficiency, usability,
reliability, and security — and interpreted using the ranges in Table 3.72. The system is
considered acceptable when the overall weighted mean reaches at least **3.41** (Agree / Very Good),
with a target of **4.21** or higher (Strongly Agree / Excellent).

Because the evaluation relies on a small panel of domain-qualified evaluators rather than a large
statistical sample, findings are interpreted descriptively per characteristic and supplemented with
the evaluators' written comments, rather than validated through inferential reliability statistics
such as Cronbach's alpha. The panel's judgement is treated as evidence about *this system in this
institution*, and is not generalised to Philippine HR practice at large.

**Table 3.72 — Five-Point Likert Scale and Interpretation**

| Scale | Range of weighted mean | Verbal interpretation | Qualitative description |
|---|---|---|---|
| 5 | 4.21 – 5.00 | Strongly Agree | Excellent |
| 4 | 3.41 – 4.20 | Agree | Very Good |
| 3 | 2.61 – 3.40 | Neutral | Good |
| 2 | 1.81 – 2.60 | Disagree | Fair |
| 1 | 1.00 – 1.80 | Strongly Disagree | Poor |

### 3.12.4 Traceability matrix

**Table 3.73 — Objective → Deliverable → Design Artefact → Evidence**

| Chapter 1 objective | Deliverable | Design artefact | Evidence of attainment |
|---|---|---|---|
| 1. Design and develop a fully integrated HR ERP covering the nine core modules plus an employee self-service DTR application | 9 HR modules, Company Setup, System administration, Dashboard, Reports, mobile companion | Figures 3.1–3.3, 3.5–3.18, 3.31, 3.32, 3.34, 3.36; FR-01 – FR-14, FR-17 – FR-21 | Feature and page-smoke tests (§3.12.1); UAT scenarios; ISO 25010 functional suitability score (§3.12.3) |
| 2. Implement a multi-model machine learning layer (attrition, performance forecast, promotion readiness) | 3 trained pipelines + inference service + 3 analytics surfaces | Figures 3.16, 3.23, 3.29, 3.30; §3.6.4, §3.6.5, §3.7.10; FR-15 | Table 3.71 thresholds on the held-out partition; CV stability; explainability review; transportability assessment (§3.12.2.3) |
| 3. Integrate an LLM function-calling layer and a workforce analytics dashboard | Assistant with 56 permission-scoped tools; 6 insight writers; dashboard + 7 reports | Figures 3.17, 3.18, 3.24; §3.7.11; FR-16, FR-17 | Integration tests of tool execution against canonical classes; permission-scoping tests; UAT scenarios |
| 4. Evaluate model accuracy and overall system acceptability | Metrics report + ISO/IEC 25010 results | §3.12.2, §3.12.3; Tables 3.71, 3.72 | Reported in Chapter 4 |

---

## 3.13 Ethical Considerations

Ethical considerations are observed throughout the study.

**Datasets.** Modeling datasets are fully anonymized, contain no personally identifiable
information, and are used within their respective licenses. Partner-contributed operational records
are anonymized, de-identified, and stripped of identifiers by the researchers before use, are
transferred through a secure channel, and are stored read-only.

**Human participants.** The HR expert and all evaluation respondents participate under informed
consent, are informed of the purpose and use of their responses, may withdraw at any time, and
their identities remain confidential. Interviews are audio-recorded only with explicit consent.

**Decision support, not decision making.** Predictive outputs are treated strictly as
decision-support signals requiring human judgement. This is enforced in the design, not merely
asserted in the text: no predictive score is written onto an employee record; the assistant refuses
to execute rejection, hiring, or archival on a hint; and the analytics screens state the
interpretation guidance of Section 3.12.2.3 on the screen itself.

**Fairness.** Protected attributes are excluded from the feature contract at training time and are
never surfaced as decision factors at serving time (Section 3.6.5), at a measured cost in
predictive performance that the study accepts and reports.

**Data privacy.** Live employee data in the deployed system is handled in compliance with the Data
Privacy Act of 2012 (RA 10173) through access control, tenant isolation, append-only audit logging,
and data minimisation, as designed in Section 3.7.15. Data minimisation is a design property rather
than a policy statement: the pre-employment checklist records that a medical clearance was obtained
without ever storing a diagnosis, and nine sensitive fields are withheld from every AI-mediated
read regardless of the requester's permissions.

---

## Appendix 3-A — Revision Log

This appendix records what changed relative to the previous version of Chapter III, so that a
reviewer can see the corrections rather than having to find them.

### A. Factual corrections (chapter contradicted the built system)

| # | Previous statement | Corrected to | Where |
|---|---|---|---|
| A1 | "a catalogue of 62 granular permissions" | **73 permissions across 16 groups** | §3.9 FR-02, §3.7.12, §3.7.15 |
| A2 | "64 tables" | **69 tables**; the five undocumented tables (`rating_scales`, `review_templates`, `review_template_items`, `employee_invitations`, `organization_join_requests`) and nineteen added columns are now documented | §3.8.1, §3.8.3 |
| A3 | "shall provision a Staff login for newly hired employees" | Hiring creates **employment, never a login**; two admission paths (invitation, join code) converge on one operation | §3.7.7.3, §3.9 FR-04, Figure 3.22 |
| A4 | "SQLite (in-memory) — isolated database for the automated test suite" | The suite is configured for SQLite but is executed against a disposable **PostgreSQL** database, because the development PHP build lacks the SQLite driver; the constraint and its consequence for driver-agnostic SQL are stated | §3.11 note |
| A5 | Performance described as "weighted KPI criteria with configurable rating scales" | Tenant-defined **appraisal frameworks** with weighted sections, tenant rating models, eligibility rules, snapshotting, and two-level scoring | §3.7.7.6, §3.7.9.2, FR-10 |
| A6 | Architecture paragraph credited "queued jobs" for notifications | All three notification channels are delivered **inline on the request**; web push is wrapped so a signing failure does not abort in-app and e-mail | §3.7.16, FR-19 |
| A7 | Chapter 1 lists an "AI-powered résumé parser" | The design uses **native multimodal document reading** — there is no separate parser — and this is stated explicitly | §3.7.11 |
| A8 | Tool table listed Laravel/React/Python versions loosely | Verified against the project manifests: Laravel 13, React 19, Inertia 3, PHP 8.3, TypeScript 5.7, Tailwind 4, Vite 8, Pest 4, `gemini-2.5-flash` | §3.11 |
| A9 | Assistant described only as "a bounded loop" | 56 tools across 5 capability modules, permission-scoped when offered **and** re-checked on execution; local reply synthesis; explicit outcome guards | §3.7.7.12, §3.7.11, Figure 3.24 |

### B. Structural problems fixed

| # | Problem | Fix |
|---|---|---|
| B1 | "System Design" began at an unnumbered "2. System Flowchart"; there was no section 1, and "3. System Architecture" appeared ~950 lines later, *after* the data dictionary | Section 3.7 renumbered end to end; architecture now precedes the flow and flowchart material it is referenced by |
| B2 | Figure 3 and Figure 5 referred to "the data-flow diagram" and "the Level-2 DFD" — **neither existed anywhere in the chapter** | Added a context diagram, a Level-1 DFD, and thirteen Level-2 DFDs (Figures 3.4–3.18) |
| B3 | "3.6 Database Fields" was numbered as a top-level section while sitting inside System Design | Database design is now its own section (3.8) with the ERD and dictionary as subsections |
| B4 | Dangling cross-references to "Section 4.9", "Section 2.2.1", and "Section 3" | All cross-references now resolve within this chapter |
| B5 | Tense mixed inside single paragraphs ("SYNAPSE will adopt… the diagram is split…") | Stated convention: future tense for procedures still to be carried out, present tense for the design of the artefact |

### C. Sections that did not exist and were added

| # | Addition | Why it was needed |
|---|---|---|
| C1 | Context diagram + Level-1 DFD + 13 module-level Level-2 DFDs (§3.7.5–3.7.7) | The chapter is a methodology chapter for an ERP; one flow diagram cannot describe thirteen interacting subsystems, and two figures already referred to DFDs that were absent |
| C2 | Explicit algorithm specifications for every deterministic decision-support surface (§3.7.9, Figures 3.25–3.28) | The paper claimed decision support without ever stating a formula; the fit score, appraisal scoring, award nomination, attendance derivation, and leave computation are now specified with their weights, thresholds, bands, and complexity |
| C3 | ML serving design: the division of labour, the three derived quantities, feature mapping, degradation, and run storage (§3.7.10, Figures 3.29–3.30) | The previous chapter described *training* but not *serving*, so the system's most distinctive ML design decisions were invisible |
| C4 | LLM integration design, including the decide-vs-enforce principle and the prompt-injection defence (§3.7.11) | These are genuine contributions relative to the related work in Chapter 2 and were entirely absent |
| C5 | Use case model with narratives (§3.7.12, Figure 3.31) | Standard capstone requirement; previously missing |
| C6 | Record lifecycle / state diagrams (§3.7.13, Figure 3.32) | Makes the "derive, do not store" invariant checkable rather than merely stated |
| C7 | Sequence diagram (§3.7.14, Figure 3.33) | One interaction charted end to end shows the tiers cooperating, which no static diagram can |
| C8 | Security and privacy design (§3.7.15, Figure 3.35) | RA 10173 compliance was a single sentence; it is now a designed, seven-layer control set |
| C9 | Interface and navigation design (§3.7.16, Figure 3.36) | Information architecture and the deliberate narrowness of the mobile surface were undocumented |
| C10 | Deployment view and request lifecycle (§3.7.3, §3.7.4, Figures 3.2, 3.3) | The enforcement *order* — identity, tenancy, authorization — is the chapter's central security claim and had no figure |
| C11 | Non-functional requirements with verification methods (§3.10) | The chapter had functional requirements only, so nothing stated how quality attributes would be checked |
| C12 | Engineering conventions as design invariants (§3.6.3) | Several later design decisions are unintelligible without them |
| C13 | Increment backlog with dependency order (§3.6.2) | "Agile iterative and incremental" was asserted without showing the ordering that makes it a plan |
| C14 | External-validity subsection with four named risks and a mitigation (§3.12.2.3) | The most serious methodological weakness in the study was previously unstated |
| C15 | Traceability matrix (§3.12.4) | Section 3.1 claimed every activity traces to an objective; the matrix now demonstrates it |
| C16 | Justification for the five-characteristic ISO/IEC 25010 subset and for the panel size convention (§3.3.4, §3.2.1) | The subset was previously unexplained, and the small-panel convention was borrowed from heuristic usability evaluation without qualification |

### D. Items flagged for the researchers' decision (not changed unilaterally)

| # | Issue | Recommended action |
|---|---|---|
| D1 | **Chapter 1 Scope states "Multi-organization and multi-tenant deployment is likewise outside the scope of this study."** The delivered system is multi-tenant at row level, ships a workspace switcher, and allows one identity to belong to several organizations. This is a direct contradiction between Chapters 1 and 3. | Amend Chapter 1 to bring multi-tenancy **into** scope — it is implemented, tested, and is one of the study's stronger contributions — rather than removing it from Chapter 3. |
| D2 | Chapter 1 objectives mention "certifications" and "KPI scores" as ML inputs; the delivered attrition feature contract does not use certifications. | Align the Chapter 1 wording with the feature contract in §3.6.5. |
| D3 | The ISO/IEC 25010 instrument is cited as the 2011 edition. | Confirm which edition the adviser expects; if the 2023 revision is required, restate the characteristic names accordingly. |
| D4 | The related-literature citations in §3.6.4 were carried over from the previous draft and are marked in the project's internal notes as "to confirm". | Verify each citation supports the specific claim it is attached to before submission. |
| D5 | The `MODEL-JUSTIFICATION` note records a planned empirical model comparison that has not been run. | Either run the comparison and report it in Chapter 4, or keep it as stated future work in Chapter 5 — but do not let Chapter 3 imply it was performed. |

---

## List of Figures in Chapter III

| Figure | Title | File |
|---|---|---|
| 3.1 | Layered System Architecture of SYNAPSE | `fig-3-1-system-architecture.svg` |
| 3.2 | Deployment Diagram | `fig-3-2-deployment-diagram.svg` |
| 3.3 | Request Lifecycle and the Enforcement Pipeline | `fig-3-3-request-lifecycle.svg` |
| 3.4 | Context Diagram (Data Flow Diagram Level 0) | `fig-3-4-context-diagram.svg` |
| 3.5 | Data Flow Diagram — Level 1 | `fig-3-5-dfd-level-1.svg` |
| 3.6 | Level-2 DFD — Recruitment | `fig-3-6-dfd2-recruitment.svg` |
| 3.7 | Level-2 DFD — Onboarding | `fig-3-7-dfd2-onboarding.svg` |
| 3.8 | Level-2 DFD — Employee 201 File | `fig-3-8-dfd2-employee-201-file.svg` |
| 3.9 | Level-2 DFD — Attendance | `fig-3-9-dfd2-attendance.svg` |
| 3.10 | Level-2 DFD — Leave | `fig-3-10-dfd2-leave.svg` |
| 3.11 | Level-2 DFD — Performance | `fig-3-11-dfd2-performance.svg` |
| 3.12 | Level-2 DFD — Training | `fig-3-12-dfd2-training.svg` |
| 3.13 | Level-2 DFD — Awards | `fig-3-13-dfd2-awards.svg` |
| 3.14 | Level-2 DFD — Events | `fig-3-14-dfd2-events.svg` |
| 3.15 | Level-2 DFD — Offboarding | `fig-3-15-dfd2-offboarding.svg` |
| 3.16 | Level-2 DFD — Predictive Analytics | `fig-3-16-dfd2-predictive-analytics.svg` |
| 3.17 | Level-2 DFD — LLM Assistant | `fig-3-17-dfd2-llm-assistant.svg` |
| 3.18 | Level-2 DFD — Reports and Dashboard | `fig-3-18-dfd2-reports-and-dashboard.svg` |
| 3.19 | System Flowchart of SYNAPSE | `fig-3-19-system-flowchart.svg` |
| 3.20 | Flowchart — Authentication and Session Establishment | `fig-3-20-flowchart-authentication.svg` |
| 3.21 | Flowchart — Leave Request with Approval | `fig-3-21-flowchart-leave-approval.svg` |
| 3.22 | Flowchart — The Hire Bridge | `fig-3-22-flowchart-hire-bridge.svg` |
| 3.23 | Flowchart — Predictive Assessment Run | `fig-3-23-flowchart-predictive-assessment.svg` |
| 3.24 | Flowchart — LLM Assistant Function-Calling Loop | `fig-3-24-flowchart-assistant-loop.svg` |
| 3.25 | Decision-Support Algorithm — Recruitment Fit Score | `fig-3-25-algorithm-fit-score.svg` |
| 3.26 | Decision-Support Algorithm — Two-Level Appraisal Scoring | `fig-3-26-algorithm-appraisal-scoring.svg` |
| 3.27 | Decision-Support Algorithm — Award Nomination | `fig-3-27-algorithm-award-nomination.svg` |
| 3.28 | Decision-Support Algorithm — Attendance Derivation | `fig-3-28-algorithm-attendance.svg` |
| 3.29 | Machine Learning Training Pipeline | `fig-3-29-ml-training-pipeline.svg` |
| 3.30 | Machine Learning Serving Path and the Division of Labour | `fig-3-30-ml-serving-path.svg` |
| 3.31 | Use Case Diagram of SYNAPSE | `fig-3-31-use-case-diagram.svg` |
| 3.32 | Lifecycle (State) Diagrams of the Principal Records | `fig-3-32-state-diagrams.svg` |
| 3.33 | Sequence Diagram — Mobile Clock-In with GPS and Selfie | `fig-3-33-sequence-mobile-clock-in.svg` |
| 3.34 | Entity Relationship Diagram of SYNAPSE (Structural Level) | `fig-3-34-erd.svg` |
| 3.35 | Security and Privacy Enforcement Layers | `fig-3-35-security-layers.svg` |
| 3.36 | Information Architecture and Navigation Map | `fig-3-36-navigation-ia.svg` |

## List of Tables in Chapter III

| Table | Title |
|---|---|
| 3.1 – 3.64 | Data dictionary — original 64 tables (§3.8.2) |
| 3.65 – 3.69 | Data dictionary — tables added after the original dictionary (§3.8.3) |
| 3.70 | Developmental Tools and Cost (§3.11) |
| 3.71 | Machine Learning Acceptance Thresholds (§3.12.2.2) |
| 3.72 | Five-Point Likert Scale and Interpretation (§3.12.3) |
| 3.73 | Traceability Matrix: Objective → Deliverable → Artefact → Evidence (§3.12.4) |
