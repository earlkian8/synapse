# 0034 — A company writes its own rules in the setup wizard

- **Status:** Accepted
- **Date:** 2026-09-10
- **Related:** [Company Setup Wizard module](../modules/company-setup-wizard.md),
  [0032 — Guided company setup before the first dashboard](./0032-guided-company-setup.md)
  (the wizard this extends, and the "blueprints are offers, not defaults" rule it
  inherits),
  [0028 — Appraisal frameworks & tenant rating models](./0028-appraisal-frameworks-and-tenant-rating-models.md)
  (a criterion is chosen, not typed — and what that means on a company's first day),
  [0029 — Configurable recruitment pipelines](./0029-configurable-recruitment-pipelines.md)
  (a stage's meaning is what recruitment reads, never its name).

## Context

ADR 0032 turned the four heaviest Company Setup screens into one decision each: pick a
department list, a set of leave entitlements, a hiring process, an appraisal framework.
That solved the cost of an empty tenant, and introduced a smaller problem in its place.

**A company whose answer was not on the list had nowhere to put it.** Every step but
departments was a closed choice. A coffee shop hiring through a walk-in and a trial
shift had to adopt "Standard Hiring" and rewrite it afterwards; an employer granting
typhoon leave had to adopt something close and edit it; a company whose appraisal asks
about latte art and shift handovers had to take "Balanced Appraisal" and then dismantle
it in the framework editor. The wizard was inviting a company to describe itself, and
then offering it a template.

That reads worse than it works. The screens it hands off to are all genuinely editable,
so nothing was *lost* — but "adopt something close, then go and fix it" is the exact
experience ADR 0029 walked away from when it refused to seed module defaults, and it
lands on the owner in their first five minutes, when they are least equipped to know
what they have just adopted.

The pull the other way is real: what makes a posted blueprint safe is that the client
sends a **key** and the server resolves it. A statutory entitlement, an open/won/lost
stage kind and a rating scale's anchors are not things a request body should define.

## Decision

**Every step of the wizard accepts the company's own definitions as well as the offers,
and both are written by the same code.**

**1. The offers stay, and gain a way in.** Every suggestion carries a **Customise**
action that lifts it out of the list of offers and into the company's own — prefilled
with the offer's own content. Nobody starts at an empty form unless they ask to
(**Design your own** does that), and nobody is stuck with wording that is not theirs.
Departments and kinds of leave become editable rows; a hiring process and an appraisal
framework open into a real editor.

**2. Adopted and bespoke answers converge before anything is written.**
`Support\Setup\SetupDefinition` turns either into one shape — a department is a name, a
code and a description whether it was ticked or typed; a hiring process is a name and
an ordered list of stages whether it came from a card or was drawn stage by stage. The
installer underneath (`SetupInstaller`, formerly `BlueprintInstaller`) never learns
which it was. That is what makes a bespoke pipeline a first-class pipeline rather than
a lesser one, and it is why the surface area of this change is small: one new class,
and the installer taking definitions rather than blueprint keys.

**3. Vocabulary with meaning attached stays server-side either way.** The rule from
ADR 0032 is unchanged, only stated more precisely. What a client may now send is the
company's own *words* — names, descriptions, weights, entitlements. What it still may
not send is anything the modules downstream read as meaning:

| The company's own | Resolved server-side |
| --- | --- |
| A leave type's name, code, colour, days, and its three policy flags | The name, colour, policy and statutory entitlement of a **suggested** leave type — a ticked suggestion carries only its days from the client |
| A stage's name and its order | A stage's `kind`, checked against `RecruitmentPipelineStage::KINDS`, with the same "exactly one hired, at least one rejected" rule the pipelines editor enforces |
| A framework's name, sections, weights, and the wording of a criterion it writes | A **catalogue** criterion's wording, resolved from its key; and every rating scale, named out of the shared library rather than described |

**4. A bespoke step is held to the shape its module's own screen enforces.** The wizard
must not be able to create something Company Setup would refuse to save, so the wizard's
FormRequests carry the same rules: unique leave codes per tenant, one hired stage,
sections that every line belongs to, no criterion measured twice, a rating model whose
lowest band starts at 0%.

**5. A criterion the company writes joins its catalogue.** In the framework editor
proper, a one-off line stays out of the catalogue on purpose — it is written into one
framework and exists nowhere else (ADR 0028). In the wizard the opposite is true: there
is no catalogue yet, and writing a criterion is *how the catalogue gets built*. So
"Latte art" becomes a real `KpiCriterion` on the instrument it was given, reusable by
every framework the company builds later.

**What the wizard still does not do:** design a rating scale. A scale has bounds and
anchors that the whole scoring apparatus reads, and the screen that builds one properly
is one click away once setup is done. The wizard offers the six-instrument library and
says so.

## Consequences

- A company that does not look like any of the offers can say so on day one, in its own
  words, and still reach a working system in five minutes.
- `BlueprintInstaller` is now `SetupInstaller` and takes definitions. The rename is the
  honest one: it stopped installing blueprints when it started installing whatever the
  company settled on.
- The wizard's appraisal step is now a second surface that edits a framework. It is
  deliberately the smaller one — no eligibility rule (a day-one framework applies to
  everyone, because there is nobody to divide yet), and instruments chosen rather than
  designed. `PercentInput`, the weight tally and the split-evenly arithmetic were
  extracted to `features/kpi-config/components/weight-controls.tsx` so both editors
  compute and render a weight the same way.
- Validation is duplicated between the wizard's requests and the modules'. This is the
  price of the wizard posting its own payload shape rather than proxying the modules'
  endpoints; the alternative — the wizard POSTing to `setup.leave-types.store` five
  times from the client — would have made a step non-atomic and its progress
  unrecordable.

## Alternatives considered

- **A link out to the real screen.** "Set this up properly in Company Setup →" is one
  line of code and abandons the person mid-wizard, which is what ADR 0032 existed to
  stop.
- **Free text everywhere, no offers.** Truest to ADR 0029 and worst for the owner: it
  is the wall of empty screens the wizard replaced.
- **Letting the client post a whole leave type for a ticked suggestion too.** Simpler
  (one payload shape instead of two) and wrong: a statutory entitlement would then be
  whatever the request said it was, and "Maternity Leave, 105 days under RA 11210"
  would stop being a claim the server stands behind.
