# 0063 — A Privacy Policy and Terms of Service that describe the system and name its operator

- **Status:** Accepted
- **Date:** 2026-10-01
- **Related:**
  - [0062 — A Help Center in the app](./0062-a-help-center-read-for-the-reader.md)
    (whose renderer the documents reuse, and which gains a privacy article);
  - [0061 — One container image, with Supabase for the database and the files](./0061-one-container-image-with-supabase-for-data-and-files.md)
    (the providers the policy names);
  - module doc: [Privacy Policy and Terms of Service](../modules/legal-documents.md).

## Context

Every footer carried *Privacy* and *Terms* links that went nowhere, and nobody signing
up, joining or applying for a job was told how their information would be handled.
SYNAPSE holds sensitive personal information: government ID numbers, pay, bank details,
the location and a photo of every punch, applicants' résumés. It also sends some of it
to an AI provider. The Data Privacy Act requires that the people it is about be told.

The things to settle:

1. **Whose documents these are.** SYNAPSE is deployed by an operator and used by
   employers. Who is the controller, and who the processor?
2. **How to keep them true** as the system changes and from one deployment to another.
3. **What to disclose about AI**, where the honest answer depends on the operator's plan
   with Google.
4. **Where people must meet them.**

## Decision

**Employers are controllers, the operator is their processor.** The policy says so in
its first section. An employer decides what goes into its workspace and answers its
people's requests about their HR records. The operator processes those records for it,
and is the controller only for accounts and for running the service. The Terms give
the employer the matching duties: a lawful basis, telling its people, accuracy, roles,
and its settings.

**Written from the system, as Markdown in the app.** `resources/legal/*.md`, reviewed in
code like any change. Every claim was checked against the code: the cookies, the
external hosts in the Content-Security-Policy, the fields the assistant withholds, the
government IDs never sent to Gemini, and the inputs of each prediction. The page reuses
the Help Center's renderer and contents, so there is one way documents look and link.

**The operator's details come from configuration.** `config/legal.php`
(`LEGAL_OPERATOR`, `LEGAL_CONTACT_EMAIL`, `LEGAL_PRIVACY_EMAIL`, `LEGAL_ADDRESS`),
written into `{{…}}` tokens when the page is built. The same text then names whoever
actually runs each deployment, and nobody has to edit legal text to deploy.

**The AI disclosure follows the deployment's plan.** On Google's free Gemini plan Google
may use what is sent to improve its products. On a paid plan it does not.
`LEGAL_AI_PAID_PLAN` picks the sentence the policy shows. It defaults to the free plan,
which is what the project has run on, so the policy is never more reassuring than the
truth.

**Met where it matters, publicly.** `/privacy` and `/terms` need no account, and the
setup redirect leaves them alone. The sign-up form carries the agreement notice. The
job application form carries a privacy notice, since applicants give the most and have
no account. Every footer links both. The Help Center explains the rights in plain words,
and the assistant knows where the documents are.

## Consequences

- A feature that changes what personal information SYNAPSE collects, shares or sends to
  a provider changes the policy in the same change, with a new effective date.
- Deployments must set the `LEGAL_*` variables. The defaults read as placeholders: the
  app name, the mail sender, and "available on request".
- The documents are drafts written by the engineering team and need a lawyer's review
  before an organisation relies on them.
- **Not done:** recording acceptance (and re-acceptance after a change) per user, the
  notice in the mobile app's sign-up, and per-organisation switches for optional
  processing such as AI features.
