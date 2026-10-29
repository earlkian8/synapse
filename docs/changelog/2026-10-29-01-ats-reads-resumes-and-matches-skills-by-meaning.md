# The ATS reads résumés and matches skills by meaning

The fit score no longer misses good candidates over wording. A posting that asks for
JavaScript now credits a candidate who wrote "TypeScript", "JS" or "Next.js".
"Java" is no longer found inside "JavaScript". The résumé itself is read: PDF, Word, or
a scan or photo by OCR. Postings can say which skills are must-haves and which are only
nice to have, and every match is explained. See
[ADR 0097](../decisions/0097-the-ats-reads-the-resume-and-matches-skills-by-meaning.md).

## Highlights

- **Skills by meaning.** About 400 skills across tech, office, finance, HR, BPO, sales,
  marketing, design, healthcare, education, trades, hospitality and languages,
  Philippine terms included (BIR forms, SSS/PhilHealth/Pag-IBIG, DOLE, LET). A skill
  counts when the candidate:
  - names it any way ("NodeJS", "Postgres", "MS Excel");
  - shows a skill that proves it (TypeScript → JavaScript, Laravel → PHP, Next.js →
    React);
  - shows all its parts (React + Laravel → full-stack);
  - spells it one letter off ("Javscript").

  A related skill earns part of the credit: JavaScript for TypeScript, Vue for React,
  MariaDB for MySQL. A skill SYNAPSE doesn't know is matched by its words, in any order
  and form. "React or Vue" accepts either.
- **No more words inside words.** Matching is by whole words, so "Java" isn't in
  "JavaScript" and "excel at" isn't Excel. "R", "C" and "Go" count only as items in a
  list, so a middle initial never counts as R. A candidate's own name is never read as
  a skill.
- **The résumé is read.** Text comes out of PDFs, Word files (.docx and .doc), and scans
  and photos (OCR) when a file arrives: the careers page, the recruiter's forms, or a CV
  sent to the assistant. Supporting documents are read too. Government IDs never are.
  Résumés already on file are read by an hourly sweep.
- **Must-have and nice-to-have skills.** The posting form has both lists. A must-have
  counts twice a nice-to-have. Neither knocks anyone out. Suggestions appear as you
  type, and a recognised skill shows a check mark with what else counts for it.
- **Experience from the résumé.** A candidate who left their years blank is credited
  with what their job dates add up to, shown as "≈6 yrs from résumé".
- **Every match explained.** Under the skill match, the candidate modal lists each
  skill and how it was shown ("JavaScript — shown by TypeScript · résumé") and says when
  a résumé couldn't be read.
  - The pipeline export gains *Skills shown* and *Skills missing* columns.
  - The assistant's candidate profile names the missing must-haves.
  - AI insights receive the skill readings and a Word résumé's text.

## Backend

- Migration `2026_10_29_000000_read_resumes_and_match_skills_by_meaning`:
  - `applicants` gains `resume_text`, `resume_read_status` and `documents_text`.
  - `applicant_documents` gains `extracted_text` and `read_status`.
  - `job_postings` gains `preferred_skills`.
  - Files already on record are marked `pending`, government IDs `skipped`.
- `Support\Files\DocumentText` reads files with pdftotext, pdftoppm + tesseract, the
  .docx XML and antiword/catdoc (or the text runs of a .doc). Each tool is optional.
  Commands run with no shell, under a time limit, on a temporary copy.
- `Jobs\ReadApplicantFiles` runs after the response. The `Applicant` and
  `ApplicantDocument` models dispatch it whenever a file arrives. It writes only
  pending files, by query, and only while the file is still the one it read.
- `recruitment:read-files` (hourly, batches of 100, `--again` to re-read everything).
- `Support\Recruitment\Skills`: `catalogue.php`, `Words`, `Skill`, `SkillCatalogue`
  (a singleton), `SkillText`, `CandidateEvidence` and `SkillMatcher`.
  `Support\Recruitment\ResumeExperience` estimates experience.
- `ApplicantScorer`'s skill component is matched by meaning: must-haves weigh 2 and
  nice-to-haves 1, out of the same 20 points. It lists each skill's reading and whether
  the résumé was read. Experience falls back to the résumé's estimate.
- `GET /recruitment/skills` (`recruitment.view`, cached for an hour) describes the
  catalogue for the posting form.
- `StoreJobPostingRequest` takes `preferred_skills`. A skill may be 60 characters (was
  40).
- The resources send `resume_read_status` and never the read text. Both text columns
  are hidden on the models.
- The assistant's `create_job_posting` and `update_job_posting` take `preferred_skills`.
  `candidate_profile` names the missing must-haves.
- `ApplicantInsights` adds nice-to-haves, the skill evidence, and the text of a Word
  résumé the model can't be sent.
- The pipeline CSV gains *Skills shown* and *Skills missing*.
- The Dockerfile installs `poppler-utils`, `tesseract-ocr` and `tesseract-ocr-eng`.
- The seeder gives the Software Engineer and Accountant postings nice-to-haves.

## Frontend

- `skills-input.tsx`: a tag input with catalogue suggestions (arrow keys and Enter), and
  a check mark and tooltip on each recognised skill. The posting form uses two of them,
  *Must-have skills* and *Nice-to-have skills*.
- `use-skill-catalogue.ts` fetches the catalogue once per page load, and resolves and
  suggests skills with the server's folding rules.
- `fit-score.tsx` lists each skill's reading under the skill match: shown, partly, or
  missing, with what stood for it and where.
- The candidate modal shows an estimated experience as "≈6 years (from résumé)".
- The posting detail and the pipeline header show nice-to-haves as dashed chips.

## Docs

- ADR 0097; the Recruitment module doc gains *Reading résumés & matching skills*. The
  recruitment tables doc and the ERD gain the new columns. The ERD's experience columns
  are now decimal, as ADR 0091 made them.
- The Help Center's *Job postings* and *Candidates and the pipeline* articles explain
  the two skill lists and how skills are matched.

## Notes

- Scores change for every posting with skills. Nothing stored needs migrating, because
  the fit score is computed on every view.
- No Gemini request was made. AI insights are covered by the existing faked tests.
- The Docker image was not built or run here.
- Not built: company-defined synonyms, negation ("no Java experience") and recency, and
  OCR in languages other than English.
