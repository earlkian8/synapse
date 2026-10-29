# 0097 — The ATS reads the résumé and matches skills by meaning

- **Status:** Accepted
- **Date:** 2026-10-29
- **Related:**
  - [0006 — Recruitment ATS and the hire bridge](./0006-recruitment-ats-and-hire-bridge.md);
  - [0029 — Configurable recruitment pipelines](./0029-configurable-recruitment-pipelines.md)
    (the fit score this changes);
  - [0087 — Uploads are private to their workspace](./0087-uploads-are-private-to-their-workspace.md)
    (where résumés live);
  - [0091 — Postings say what they pay](./0091-postings-say-what-they-pay-and-anyone-assigned-can-interview.md)
    (half years of experience);
  - module docs: [Recruitment](../modules/recruitment.md); schema:
    [recruitment tables](../database/recruitment-tables.md).

## Context

The fit score's skill match was a substring test of each skill HR typed against the
candidate's headline, notes and cover note. It failed in the ways applicant tracking
systems are known for:

- **Same skill, other words.** A posting asking for JavaScript gave nothing to a
  candidate who wrote "TypeScript", "JS" or "Next.js", though each of them shows
  JavaScript. "NodeJS" missed "Node.js", "Postgres" missed "PostgreSQL", and "managed
  several projects" missed "Project management".
- **Words inside words.** "Java" matched "JavaScript", "Excel" matched "excellent",
  "Go" matched "good", and "R" or "C" matched almost any text.
- **The résumé was never read.** Only the headline and two short notes were searched.
  Whatever the candidate wrote in the CV itself counted for nothing.
- **Every skill weighed the same.** A posting could not say that one skill is
  required and another only welcome.
- **A blank field scored as zero.** A candidate who skipped "years of experience"
  scored no experience at all, though their résumé listed eight years of jobs.
- **No reasons.** The breakdown said "2/4 skills matched" and not which two, or why.

Each of these drops good candidates down the ranking, which is the one thing an ATS
must not do.

## Decision

### The résumé is read

`Support\Files\DocumentText` reads the words out of an upload:

- a PDF with `pdftotext`, and a scanned PDF (almost no text) by rendering its first
  three pages with `pdftoppm` and reading them with `tesseract`;
- a .docx from its XML, headers and footers included;
- a .doc with `antiword` or `catdoc`, or else from the text runs the file stores;
- a photo of a résumé by `tesseract`.

Every tool is optional. One that isn't installed only means that kind of file can't be
read. Commands run without a shell and with a time limit, on a private temporary copy
that is deleted afterwards. A .docx that unpacks to more than 20 MB is not read. The text
is cleaned and kept to 60,000 characters.

The text is stored on the candidate (`applicants.resume_text`, with
`resume_read_status`: pending, read or unreadable). Each supporting document's text is
stored on the document, and together on the candidate as `documents_text`, so the
fit score never opens a file or loads a relation. A **government ID is never read**
(`read_status = skipped`).

Reading is triggered by the models, not the controllers. A new or changed
`applicants.resume` resets the reading to `pending`, and a new document is `pending`.
Then `Jobs\ReadApplicantFiles` runs **after the response**, in the same process (as the
attendance recompute does, because the app is often served without a queue worker). So
every way a file arrives is covered: the careers page, the recruiter's forms, and a CV
sent to the assistant. The job writes only what is pending, by query, and only while the
résumé is still the file it read. Running it twice changes nothing.

`recruitment:read-files` (hourly) reads what is still waiting: files on record before
this change (the migration marks them pending) and any read that never finished. It
works in batches of 100, and leaves files under five minutes old to their own
request. `--again` re-reads everything, for example after OCR is installed. The Docker
image now installs `poppler-utils` and `tesseract-ocr`.

The text is for matching only. Both columns are `$hidden` on the models, and no resource
sends them to the browser.

### Skills are matched by meaning

`Support\Recruitment\Skills` holds the matching, all of it pure and deterministic:

- **`catalogue.php`** lists about 400 skills across tech, office, finance, HR, BPO,
  sales, marketing, design, healthcare, education, trades, hospitality and languages,
  including Philippine terms (BIR forms, SSS/PhilHealth/Pag-IBIG remittances, DOLE, LET,
  TESDA NC II). Each skill has:
  - **aliases**: other ways of writing the same skill.
  - **implies**: skills it proves, because nobody can use it without them. TypeScript
    implies JavaScript; Laravel implies PHP.
  - **parts**: skills it is made of. Full-stack is front-end and back-end; MERN is four
    parts; Microsoft Office is Word, Excel and PowerPoint.
  - **cased** or **listed** spellings, for names that are everyday words: "Excel" only
    with its capital; "R", "C", "Go" and "Ruby" only as an item of a list.
  - an **umbrella** flag for broad areas.

  **Families** group skills close enough to give a head start (React and Vue, PostgreSQL
  and MySQL), each with its own partial credit.
- **`Words`** is one set of folding rules for the catalogue, the posting and the résumé.
  Case, accents, punctuation and spacing don't matter. C++, C# and .NET survive as words.
  Versions don't hide a skill ("PHP8"). A light stemmer compares inflected forms.
- **`SkillText`** indexes one piece of candidate text once. Matching is by whole words
  only, so "Java" is not in "JavaScript". Neighbouring words also count joined ("Type
  Script"). A cased or listed name is checked against the original text, and the
  candidate's own name never counts as a skill.
- **`CandidateEvidence`** is the candidate's text in order of trust: résumé, headline,
  cover note, documents, then the recruiter's notes. Each skill is looked for once per
  candidate.
- **`SkillMatcher`** assesses each skill a posting asks for. HR may write "or", as in
  "React or Vue". Qualifiers and generic words are ignored ("Strong knowledge of payroll",
  "React development"). The strongest reading wins:

  | Status | Example | Credit |
  | --- | --- | --- |
  | found | "JS" for JavaScript; "managed several projects" for Project management | 1 |
  | implied | TypeScript (or Next.js) for JavaScript | 1 |
  | parts | React + Laravel for Full-stack | 1 |
  | spelling | "Javscript" (long or camel-cased names only, so "wedding" is never "welding") | 1 |
  | related | JavaScript for TypeScript (0.4); Vue for React (0.5); MariaDB for MySQL (0.9); two of MERN's four parts (0.4) | 0–1 |
  | missing | | 0 |

  A skill the catalogue doesn't know is matched by its words, in any order and form,
  close together ("Vendor accreditation" in "accredited new vendors").

### Must-haves and nice-to-haves

`job_postings.skills` stays the list it was, now named **must-have** in the UI. The new
`preferred_skills` holds the **nice-to-haves**. In the skill component a must-have
weighs 2 and a nice-to-have 1, and a nice-to-have that repeats a must-have is dropped.
The component's weight (20 of 100) and the rest of the formula are unchanged.

There is no knock-out. A missing must-have costs its share of the score but never
hides the candidate. Over-eager knock-out filters are how ATSs lose good people, and
the yes/no screening questions remain HR's explicit tool for real requirements.

### Experience left blank is read from the résumé

`Support\Recruitment\ResumeExperience` adds up the résumé's job date ranges ("Jan 2019 –
Present", "2016 - 2020", "03/2018 to 06/2021"). Overlapping jobs count once. Education,
training, seminar and similar sections and lines are left out. With no ranges it takes
the candidate's own statement ("7+ years of experience"). It is used only when
`years_experience` is blank, and is shown as an estimate: "≈6 yrs from résumé (needs 3)".

### Every reading is explained

The `skills` item of the fit breakdown now carries, per skill, its tier, status, what
stood for it (`via`), where it was found (`source`) and its credit. It also carries
whether the résumé could be read (`resume`).

- **The candidate modal** lists them: "JavaScript — shown by TypeScript · résumé",
  "Vue — related: React, through Next.js · 50% credit". When the résumé couldn't be
  read, or hasn't been read yet, it says so.
- **The pipeline export** gains *Skills shown* and *Skills missing* columns.
- **The assistant's** `candidate_profile` card names the missing must-haves.
- **The AI insights digest** passes the skill readings to the model to check, plus a
  Word résumé's text, which it could never read before.

The posting form takes must-haves and nice-to-haves in two tag inputs. As HR types it
suggests catalogue skills (`GET /recruitment/skills`, gated `recruitment.view`, cached
for an hour). A skill the catalogue recognises is marked, and its tooltip says what else
counts for it.

## Consequences

- Scores change for every posting with skills, and change upward for candidates whose
  résumés show what their headlines didn't. Rankings are recomputed on every view, so
  nothing stored needs migrating. Résumés already on file are read by the hourly sweep,
  100 candidates at a time.
- Reading runs after the response inside the PHP-FPM worker. A scanned PDF can take a
  few seconds of OCR there; `request_terminate_timeout` is 600 s.
- The image is larger by poppler and tesseract with English data (about 30 MB).
- The catalogue is data in the repository. A new skill or alias is a code change, and
  `SkillCatalogueTest` refuses an alias shared by two skills, an unknown reference, or
  a loop in `implies`.
- `skills.*` now allows 60 characters (was 40), so a skill can be described in words.
- Data Export already dumps whole tables, so the new columns are included in a
  company's export.

## Not built

- Skills or synonyms a company defines for itself. Today the catalogue is the same for
  every workspace.
- Negation ("no experience with Java" still shows Java) and recency (a skill last used
  in 2009 counts like one used today).
- Detecting hidden text a candidate added to game the match. The AI insights prompt
  already treats such text as a red flag.
- Languages other than English for OCR.
