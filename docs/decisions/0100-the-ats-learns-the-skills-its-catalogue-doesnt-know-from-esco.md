# 0100 — The ATS learns the skills its catalogue doesn't know from ESCO

- **Status:** Accepted
- **Date:** 2026-11-03
- **Related:**
  - [0097 — The ATS reads the résumé and matches skills by meaning](./0097-the-ats-reads-the-resume-and-matches-skills-by-meaning.md)
    (the catalogue and matcher this extends);
  - [0087 — Uploads are private to their workspace](./0087-uploads-are-private-to-their-workspace.md)
    (why nothing about a candidate leaves the server);
  - module docs: [Recruitment](../modules/recruitment.md); schema:
    [recruitment tables](../database/recruitment-tables.md).

## Context

ADR 0097 matches skills by meaning through a hand-built catalogue of about 400 skills.
It covers the common ground well. Most trades, office, BPO, finance and tech skills a
Philippine posting asks for are there, with their other names, the skills that prove
them, and the guards that keep "Excel" out of "excellent". A skill outside it is
matched by its words only: "Venepuncture" finds "venepuncture" and nothing else. A
phlebotomist, who does venepuncture every day, scores nothing for it.

A catalogue that tried to cover every skill would never be finished. The long tail
(clinical procedures, specialised trades, regulated work) is exactly what a public
taxonomy already describes.

**ESCO** (European Skills, Competences, Qualifications and Occupations) is the European
Commission's classification of about 13,900 skills and 3,000 occupations. It is free
and needs no key. Its public API answers a search, with `full=true`, with each skill's
preferred label, its other labels, and the skills just broader and narrower than it.
It answers an occupation with its essential and optional skills. The alternatives were
worse fits: O\*NET needs an account and is US-only, and Lightcast needs client
credentials and a licence.

ESCO's search is a full-text search, though. Asked for "MS Excel", its best answer is
"pursue excellence in the creation of food products". Its labels are verb phrases
("operate forklift"), not the nouns a posting uses ("Forklift operation"). It is written
in British English ("call centre agent"), and a lookup takes one to two seconds. So it
can't be asked while a score is worked out, and its answers can't be trusted as they
come.

## Decision

### The catalogue first, always

A skill the catalogue knows is matched as ADR 0097 says, and is **never sent to ESCO**.
ESCO is asked only about the skills the catalogue can't resolve, each "or" alternative
on its own. The curated catalogue stays the authority. ESCO fills in the long tail
beneath it.

### Asked ahead of time, read from the database

`Support\Recruitment\Skills\Esco\EscoSkills` does all the asking, and stores what it
learns in two tables of public reference data (no `organization_id`):

- `esco_skills`: each ESCO skill once, by URI, with its label, other labels,
  description, type, and the skills just broader and narrower.
- `esco_skill_terms`: each skill a posting asked for, folded, and what ESCO said:
  `matched` (with the skill), `none` (ESCO has no skill by that name) or `failed`
  (ESCO couldn't be reached). A term is a skill's name, never who asked for it.

It is asked at three moments. None of them is while a score is worked out:

- **After a posting is saved.** The `JobPosting` model dispatches
  `Jobs\LookUpSkillsInEsco` after the response, whenever a posting is created or its
  skills change, however it was saved (the form or the assistant). The job carries only
  the skills' names.
- **Daily.** `recruitment:esco-lookup` (02:40) works off every workspace's waiting
  skills, at most 200 a run, with a short pause between lookups. Waiting skills are
  those on postings from before this change, lookups that failed, and answers due for a
  refresh. `--again` asks about every one.
- **From the posting form**, as HR types (below). An answer given there is kept, so the
  candidates' scores can use it before the posting is even saved.

An answer is trusted for 180 days (`none` for 30), then asked again. A failure is
asked again at the next chance, and at most daily once it has failed three times. When
ESCO can't be reached, **a skill already matched stays matched**: an outage never takes
away what was learned.

The fit score reads only the database. `EscoTaxonomy` implements a small
`SkillTaxonomy` interface. It reads a posting's terms in one query and keeps them for
the scorer's life. `SkillMatcher` takes the interface, so with `NoTaxonomy` (ESCO off,
or a unit test) it is exactly the matcher of ADR 0097.

### An answer must name what HR typed

`EscoLabels` accepts one of ESCO's answers only when one of its labels names the
skill, compared after folding:

| Agreement | Example | Accepted as |
| --- | --- | --- |
| the same words | "Phlebotomy" is an other label of *perform venepuncture procedures* | exact |
| the same words without a parenthesis, a light verb (perform, conduct, use, apply…) or a trailing generic noun (procedures, techniques…) | "Venepuncture" for *perform venepuncture procedures*; "Python" for *Python (computer programming)* | reduced |
| the same two or more meaningful words, in any order and form | "Forklift operation" for *operate forklift* | same words |

A preferred label beats somebody else's other label, whatever ESCO ranked first
("Bookkeeper" is the occupation *bookkeeper*, not *accountant*, which lists it as an
other label). Anything looser is refused and remembered as `none`. "MS Excel" is never a
cooking skill, and "Vendor accreditation" is never a forwarding agent's licence.

Both sides are compared in American spelling (`BritishSpelling`), because ESCO writes
British English and Philippine postings mostly don't. "Call center agent" is ESCO's
*call centre agent*. The change is limited to listed whole words and medical digraphs,
so "exercise" and "enterprise" are left alone.

### Laid onto the catalogue wherever the two agree

`TaxonomySkill::fromEsco()` turns an ESCO skill into something the matcher can use:

- **The same skill.** When ESCO's preferred label is a catalogue name (exactly, or
  the same two or more words in another order and form), the skill *is* that catalogue
  skill and is matched exactly as the catalogue matches it. *Python (computer
  programming)* is Python, so Django proves it.
- **Its own names.** Otherwise the preferred label and other labels are its names,
  each also reduced and in American spelling. A name of two or more meaningful words is
  also found in other words ("drawing blood samples" for *draw blood samples*). A
  one-word name counts only when it can't be an everyday word: the preferred label's
  own word, or a term of art of at least ten letters among the other labels
  ("phlebotomy"). "Site" or "quality" never counts.
- **Proof.** A catalogue skill that another label names proves it, with the
  catalogue's own guards (Excel only with its capital) and its provers. A skill just
  narrower proves it, as a kind of it: *operate forklift* proves *operate lifting
  equipment*, and if the narrower skill is a catalogue skill, the catalogue's
  "Forklift operator" alias shows it. Broad catalogue areas (Programming) are never
  proof.
- **A head start.** A skill just broader earns the parent credit (0.4), as a catalogue
  skill's parent does.

To support this, `SkillCatalogue::named()` answers whether a text is a catalogue name.
Unlike `resolve()`, it drops nothing around the name and never loosens one word.

### ESCO only ever adds

A skill ESCO knows is also matched by its own words, as before, and **the better
reading wins**. ESCO can raise a candidate's showing and never lower it. Its
readings carry `recognised: true` and a `taxonomy` entry naming the ESCO skill, so
every reading says where it came from.

### The posting form

All three endpoints sit under `recruitment/skills/` and are allowed only to someone who
can create or edit postings (`SkillTaxonomyRequest`), because each can reach ESCO. They
are throttled to 40 a minute and 800 a day per person (`skill-taxonomy`), and answer
JSON errors.

- `GET /recruitment/skills/lookup?q=`: how a skill will be read: the catalogue's,
  ESCO's (looked up now if need be), or by its words. A chip ESCO recognises gets a
  globe mark. Its tooltip names the ESCO skill, its other labels, and what proves it or
  partly shows it.
- `GET /recruitment/skills/suggest?q=`: ESCO skills whose names start with what is
  typed, asked only when the catalogue has fewer than three suggestions of its own,
  after a pause in typing. They are kept for a day, and shown under the catalogue's,
  marked as ESCO's. A description that merely mentions the word is never suggested.
- `GET /recruitment/skills/role?q=`: the occupation ESCO has for the posting's title
  (seniority words dropped, "closest" when the title doesn't name it), with its
  essential skills to add as must-haves and its optional ones as nice-to-haves. Each is
  given its catalogue name where the catalogue knows it. Asked only when HR presses the
  button, and kept for 30 days. Nothing is added by itself.

The candidate modal marks a skill read through ESCO with a small *ESCO* tag. The AI
insights digest says which ESCO skill a reading used, cleaned as untrusted text.

### What is sent, and the switch

Only skill names, a typed prefix and job titles are ever sent. Never a résumé, a
candidate's name or anything about who is applying. The browser never calls ESCO: the
server does, from a URL in config. `ESCO_ENABLED=false` turns it all off, and the
catalogue works alone, exactly as before. `ESCO_API_URL` can point at a self-hosted
ESCO API, `ESCO_VERSION` pins a release, `ESCO_LANGUAGE` sets the language (English by
default), and `ESCO_TIMEOUT` bounds a request. The tests never reach ESCO:
`phpunit.xml` turns it off, and the ESCO tests fake it and refuse stray requests.

## Consequences

- Scores rise for candidates whose résumés show long-tail skills in ESCO's words or
  through a narrower skill. They never fall, because the better reading wins. Rankings
  are recomputed on every view, so nothing stored needs migrating.
- Postings saved before this change are looked up by the first nightly runs, 200 skills
  at a time.
- A saved posting can make a few outbound requests after its response, one per skill the
  catalogue doesn't know. ESCO's answers are one to two seconds each.
- ESCO's labels are its own. A synonym ESCO lists that a recruiter would not is
  matched as ESCO says. The one-word rule and the refusal of loose answers keep this
  small, and the tooltip and the *ESCO* tag show where every such reading came from.
- The catalogue stays the place for skills that matter to Philippine postings. A skill
  that keeps arriving through ESCO is a candidate for the catalogue, with its own
  aliases and guards.
- The two new tables have no `organization_id`, so they are not part of a workspace's
  Data Export. They hold nothing about any workspace.

## Not built

- Occupations behind the score. A posting's title does not change how its skills are
  matched. ESCO's role skills are only offered to add.
- ESCO's languages other than English, and its skill-to-skill "essential/optional for"
  relations.
- Showing HR the ESCO terms that keep arriving, to grow the catalogue from them.
