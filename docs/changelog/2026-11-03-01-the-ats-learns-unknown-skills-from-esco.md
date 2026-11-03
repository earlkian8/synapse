# The ATS learns the skills its catalogue doesn't know from ESCO

A posting can now ask for a skill beyond SYNAPSE's hand-built catalogue, such as
"Venepuncture" or "Tower crane operation", and still credit a candidate who shows it in
other words. SYNAPSE looks the skill up in ESCO, the European Commission's free public
taxonomy of skills and occupations. It keeps the answer, and uses ESCO's other names,
more specific skills and broader skills when it reads résumés. The catalogue stays the
authority. A skill it knows is never sent to ESCO, and ESCO only ever adds to what a
candidate shows. See
[ADR 0100](../decisions/0100-the-ats-learns-the-skills-its-catalogue-doesnt-know-from-esco.md).

## Highlights

- **The long tail, by meaning.** For a skill the catalogue doesn't know, a candidate
  now shows it by:
  - any of ESCO's names for it ("drawing blood samples" for Venepuncture);
  - a catalogue skill ESCO names as the same ("Phlebotomist" for Venepuncture);
  - a more specific skill ("operating a tower crane" for lifting equipment
    operation);
  - a broader one, for part of the credit.

  An ESCO skill that is a catalogue skill, like *Python (computer programming)*, is
  matched exactly as the catalogue matches it.
- **Only answers that name the skill.** ESCO's search is loose. Asked for "MS Excel",
  it answers "pursue excellence in the creation of food products". An answer is taken
  only when one of its names is what HR typed:
  - the same words;
  - the same words without a light verb ("perform") or a parenthesis;
  - the same words in another order and form ("Forklift operation" is *operate
    forklift*).

  British and American spellings are treated as one ("call centre" is "call center").
  A one-word name counts only when it can't be an everyday word.
- **Never in the way.** Skills are looked up after a posting is saved, and nightly
  for what is still waiting. Scores are read from what was kept, so a ranking never
  waits on ESCO. When ESCO can't be reached, what was learned stands.
- **The posting form knows more:**
  - a skill ESCO recognises gets a globe mark, with ESCO's name and what else counts in
    its tooltip;
  - ESCO's skills are suggested after SYNAPSE's own;
  - **Suggest the skills ESCO lists for "…"** offers the role's essential skills as
    must-haves and its optional ones as nice-to-haves, to add with a click.
- **Explained.** The candidate profile tags a skill read through ESCO, and the AI
  insights digest says which ESCO skill it was read as.
- **Private.** Only skill names, typed prefixes and job titles are ever sent, never
  anything about a candidate. `ESCO_ENABLED=false` turns it all off.

## Backend

- Migration `2026_11_03_000000_learn_skills_from_esco`: `esco_skills` (by URI: label,
  other labels, description, type, broader and narrower skills) and `esco_skill_terms`
  (a folded skill name → `matched` / `none` / `failed`, attempts, when asked). Both are
  reference data with no `organization_id`. Models `EscoSkill` and `EscoSkillTerm`.
- `Support\Recruitment\Skills\Esco`:
  - `EscoClient`: `/search` with `full=true`, timeouts, `EscoUnavailable` on failure.
  - `EscoConcept`: ESCO's answer, cleaned and cut to size.
  - `EscoLabels`: which answer names the skill.
  - `BritishSpelling`: compares both spellings as one.
  - `EscoSkills`: asks and stores. Answers last 180 days (`none` 30); an outage keeps
    what was matched.
  - `EscoTaxonomy`: what the fit score reads, one query per posting.
- `Support\Recruitment\Skills`:
  - `SkillTaxonomy` interface and `NoTaxonomy`.
  - `TaxonomySkill`: an ESCO skill laid onto the catalogue.
  - `SkillCatalogue::named()`: an exact name, or the same words in another order.
  - `Skill::spelling()`, taken out of `fromEntry()`.
  - `SkillMatcher`: takes a taxonomy; `alternatives()` is public. A reading gains
    `taxonomy` and keeps the better of ESCO's reading and the plain word match.
- `JobPosting` dispatches `Jobs\LookUpSkillsInEsco` after the response when a posting
  is created or its skills change. `recruitment:esco-lookup` runs daily at 02:40, at
  most 200 lookups, with `--again` to refresh everything.
- `SkillTaxonomyController` + `SkillTaxonomyRequest` (create or update permission):
  `GET /recruitment/skills/lookup`, `/suggest`, `/role`. Throttled `skill-taxonomy`
  (40 a minute, 800 a day). JSON errors.
- `AppServiceProvider` binds `EscoClient`, `EscoSkills`, `SkillTaxonomy` and
  `SkillMatcher`, so `ApplicantScorer` gets the taxonomy.
- `ApplicantInsights` names the ESCO skill a reading used.
- `config/services.php` `esco` (`ESCO_ENABLED`, `ESCO_API_URL`, `ESCO_LANGUAGE`,
  `ESCO_VERSION`, `ESCO_TIMEOUT`). `.env.example` lists them. `phpunit.xml` turns ESCO
  off for the suite.

## Frontend

- `hooks/use-esco.ts`: readings for unrecognised chips, debounced ESCO suggestions
  (only when the catalogue has fewer than three of its own), and a role's skills. All
  are kept for the page's life.
- `SkillsInput`: globe-marked ESCO chips with their tooltip, and ESCO suggestions under
  the catalogue's.
- `RoleSkillSuggestions` under the skill lists in the posting form.
- `fit-score.tsx` tags a reading made through ESCO.

## Notes

- Docs: ADR 0100, [Recruitment](../modules/recruitment.md),
  [recruitment tables](../database/recruitment-tables.md) and the ERD, and the Help
  Center (job postings; candidates and the pipeline).
- Tests: `EscoLabelsTest` (8), `SkillTaxonomyMatchTest` (15) and `EscoSkillsTest`
  (10). ESCO is faked in all of them, and stray requests are refused.
- Postings saved before this change are looked up by the first nightly runs.
