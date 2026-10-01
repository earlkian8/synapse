# A Privacy Policy and Terms of Service

The *Privacy* and *Terms* links in every footer used to go nowhere. They now open
SYNAPSE's **Privacy Policy** (`/privacy`) and **Terms of Service** (`/terms`), public
pages written for the Philippine Data Privacy Act from what the system actually does.
They name whoever operates the deployment, taken from configuration. People now meet
them where it matters: on the sign-up form, on every job application, and in the Help
Center. See [ADR 0063](../decisions/0063-privacy-policy-and-terms-of-service.md) and the
[module doc](../modules/legal-documents.md).

> These are careful drafts, not legal advice. Have them reviewed, and set the `LEGAL_*`
> variables, before relying on them.

## Highlights

- **Written from the system.** The policy lists what is really collected (down to punch
  locations and photos), the real providers (Supabase, DigitalOcean with Cloudflare,
  Brevo, Google Gemini, OpenStreetMap, browser push services), the real cookies, what
  each AI feature sends, and what it never sends. It also says that no decision is made
  by a machine alone.
- **Employer and operator, each with their part.** The employer is the controller of
  its workspace, the operator its processor. The Terms give each the matching duties.
- **Honest about AI training.** Whether Google may use what is sent to Gemini depends on
  the plan. `LEGAL_AI_PAID_PLAN` decides which sentence the policy shows, and the
  default is the free plan's, so the page never promises more than is true.
- **Easy to read.** Each page opens with *At a glance*, has its contents beside the text
  (folded on a phone), switches between the two documents, and prints cleanly to PDF.
- **Met where it matters.** A notice on the sign-up form, a privacy notice on the job
  application form, links in every footer, a *Privacy and your data* Help Center
  article, and a guide entry for the assistant.

## Backend

- `config/legal.php` (`LEGAL_OPERATOR`, `LEGAL_CONTACT_EMAIL`, `LEGAL_PRIVACY_EMAIL`,
  `LEGAL_ADDRESS`, `LEGAL_AI_PAID_PLAN`), documented in `.env.example`.
- `Support\Legal\LegalDocuments`: the two documents (title, summary, effective date,
  highlights) and their bodies, with the operator's details written in.
- `Legal\LegalDocumentController` on `routes/legal.php`: `GET /privacy`
  (`legal.privacy`) and `GET /terms` (`legal.terms`). They are public, and
  `RequireCompanySetup` exempts `legal.*`.
- Content: `resources/legal/{privacy,terms}.md`.
- Help Center: a new *Privacy and your data* article (55 articles in all). `SystemGuide`
  gains a `legal` entry.

## Frontend

- `pages/legal/show.tsx` (layout-less) and `features/legal/types.ts`. It reuses the
  Help Center's `ArticleBody`, `ArticleToc` and `headingsOf`.
- Links: the app footer, the sign-in screens' footer (*Terms of Service* added), the
  welcome page, the careers footer, the sign-up notice and the application notice. The
  last two open in a new tab, so a half-filled form is kept.
- `ArticleBody` keeps in-page anchor links (`#…`) on the page. They used to open in a new
  tab.

## Notes

- Not done: recording acceptance per user, the notice in the mobile app's sign-up, and
  per-organisation switches for optional processing such as AI.
- Changing what SYNAPSE collects, shares or sends to a provider now means updating the
  policy and its effective date in the same change.
