# Privacy Policy and Terms of Service

SYNAPSE's two public legal documents: the **Privacy Policy** (`/privacy`) and the
**Terms of Service** (`/terms`). They are written for the Philippine **Data Privacy Act
of 2012** (RA 10173), describe what the system actually does with personal information,
and name **whoever operates the deployment**, from configuration.

> Status: **Active** · Routes: `GET /privacy` (`legal.privacy`), `GET /terms` (`legal.terms`)
> Linked from: every footer (app, sign-in screens, welcome page, careers pages), the
> sign-up form, the job application form, the Help Center and the assistant's guide
> See [ADR 0063](../decisions/0063-privacy-policy-and-terms-of-service.md).

> **Review before relying on them.** The text is a careful draft, written from the
> system's real behaviour. It is not legal advice. Have a lawyer review it for your
> organisation and fill in the operator details below before going live.

## What they say

**Privacy Policy** — 13 sections: who is responsible (the employer is the *personal
information controller* for its workspace; the operator is its *processor*, and the
controller for accounts and running the service); what is collected (accounts, the 201
file, attendance with location and photos, leave, performance, training, awards, events,
onboarding and offboarding, applicants, assistant conversations, the activity log); why,
and the lawful basis; who can see it, with the service providers (Supabase, DigitalOcean
with Cloudflare, Brevo, Google Gemini, OpenStreetMap, browser push services); the AI
features and predictions (what each sends to Gemini, that nothing about government IDs
is sent, that no decision is made by a machine alone); cookies (session, `XSRF-TOKEN`,
`appearance`, `sidebar_state`, `__cf_bm`, and no tracking); retention; security;
transfers outside the Philippines; the data subject's rights and how to use them;
children; changes; contact, and the National Privacy Commission.

**Terms of Service** — 18 sections: the service; accounts; the organisation's
responsibilities as controller; acceptable use; ownership of data; AI features and
predictions; attendance figures are minutes, not pay; third-party services;
availability; fees (agreed separately); suspension and termination; disclaimers;
limitation of liability; indemnity; Philippine law; changes; general; contact.

Each page opens with **At a glance**, a short list of the key points, and states that
the full text is what applies.

## Configuration

`config/legal.php`, from the environment:

| Variable | Used for | Default |
| --- | --- | --- |
| `LEGAL_OPERATOR` | `{{operator}}` — who runs the deployment | `APP_NAME` |
| `LEGAL_CONTACT_EMAIL` | `{{contact_email}}` — questions about the Terms | `MAIL_FROM_ADDRESS` |
| `LEGAL_PRIVACY_EMAIL` | `{{privacy_email}}` — the DPO or privacy contact | `LEGAL_CONTACT_EMAIL` |
| `LEGAL_ADDRESS` | `{{address}}` | "available on request, by email" |
| `LEGAL_AI_PAID_PLAN` | `{{ai_training}}` — whether Google may use what is sent to Gemini to improve its products | `false` (free plan: it may) |

`LEGAL_AI_PAID_PLAN` must be true only when the Gemini key is on a paid plan. The policy
then says Google does not use the data to improve its products; on the free plan it says
that Google may, and that people at Google may review it. Real employee data belongs on
a paid plan.

## The page

`pages/legal/show.tsx` (layout-less, like the other public pages). It has its own top
bar (logo, a Privacy / Terms switch, *Sign in* or *Back to SYNAPSE*), the title,
summary and effective date, **Print or save as PDF**, *At a glance*, the body, a
"Read next" link to the other document, and a footer. Contents sit beside the text from
`lg` up and fold into the top of it below. Printing drops the page chrome.

The body is rendered by the Help Center's `ArticleBody`, and the contents by its
`ArticleToc` and `headingsOf()`, the same components, styles and anchors. In-page
links (`#5-ai-features-and-predictions`) stay on the page.

## Where people meet them

| Where | What |
| --- | --- |
| Sign-up form | "By creating an account, you agree to the Terms of Service and confirm you have read the Privacy Policy" (both open in a new tab, so the form is kept) |
| Job application form | What the organisation will use the application for, linking the Privacy Policy, and a request not to include what the posting does not ask for |
| Footers | The app footer (*Privacy*, *Terms*), the sign-in screens, the welcome page and the careers pages |
| Help Center | *Privacy and your data* (Your account): who is responsible, the rights, and where to ask |
| Assistant | A `legal` entry in `SystemGuide` ("where is the privacy policy?") |

The routes are public and are exempt from `RequireCompanySetup`, so an owner part-way
through setup can read them.

## Backend

- `Support\Legal\LegalDocuments` — `DOCUMENTS` (title, summary, effective date,
  highlights), `find()`, `body()` (the Markdown with the operator's details written in),
  `href()`, `path()`.
- `Legal\LegalDocumentController` — `privacy`, `terms`.
- Content: `resources/legal/{privacy,terms}.md`.

## Changing a document

Edit the Markdown and move its `effective` date in `LegalDocuments::DOCUMENTS` in the
same change, since the date on the page is how readers tell versions apart. For a
significant change, the documents promise notice in SYNAPSE or by email before it takes
effect. That is an operational step (an announcement under System → Notifications);
nothing sends it automatically.

## Tests

`tests/Feature/Legal/LegalDocumentsTest.php` (8): both documents served publicly and
whole; operator details written in with no placeholder left; the address fallback; the
AI-plan disclosure both ways; every page link and in-page anchor resolving; an owner
mid-setup can read them; the guide and Help Center entries.

## Not done

- **Recorded acceptance.** Sign-up shows the notice but stores no acceptance or version.
  Recording it, with re-acceptance after a significant change, would need columns on
  `users` and a prompt.
- **The mobile app** registers accounts without showing the notice. It should link both
  documents on its *Create account* screen.
- **Consent for optional processing** (for example AI features per organisation) has no
  switch. Employers decide through roles.
