This Privacy Policy explains how personal information is handled in SYNAPSE, the HR system operated by **{{operator}}** ("we", "us"). It covers the web app, the SYNAPSE mobile app, and the public careers pages where people apply for jobs.

We follow the **Data Privacy Act of 2012** (Republic Act No. 10173), its Implementing Rules and Regulations, and the issuances of the National Privacy Commission (NPC).

## 1. Who is responsible for your information

SYNAPSE is used by organisations — employers — to run their human resources. That means two different parties are responsible for two kinds of information:

| Information                                                                                                                                                           | Who decides what happens to it                                    | Our role                                                                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| **Your organisation's HR records** — employee records, attendance, leave, appraisals, recruitment, onboarding, offboarding, and everything else kept in its workspace | **Your organisation**, as the **personal information controller** | We are its **personal information processor**: we hold and process the records only on its instructions, to provide SYNAPSE |
| **Your SYNAPSE account** — your sign-in details and settings — and how the service itself is run and secured                                                          | **{{operator}}**, as personal information controller              | —                                                                                                                           |

So if you are an employee or a job applicant, questions about your HR records — what is recorded about you, why, and who at your organisation can see it — are best answered by **your employer**. We will help them answer, and you can always contact us too (see [section 13](#13-how-to-contact-us)).

## 2. What we collect

### Your account

When you create an account or an administrator adds one for you: your **name**, **email address**, **phone number** (if given), a **password** (stored only as a one-way hash, never readable), and optionally a **profile photo**. If you turn them on, we keep what **two-factor authentication** and **passkeys** need — never your fingerprint or face, which stay on your device. We record when you last signed in.

### What your organisation keeps in its workspace

Depending on what your organisation uses, this can include:

- **Employee records (the 201 file):** personal details such as date of birth, sex, civil status and home address; contact details; position, department, manager, employment type, status and key dates; **compensation** and **bank details**; **government ID numbers** (TIN, SSS, PhilHealth and Pag-IBIG); documents, certifications and career history.
- **Attendance:** when you clock in and out and take breaks, how each punch was made, the **location** of your device when you punched (if your organisation's settings check where people punch), an optional or required **photo** taken with a punch, and your device's clock time for punches sent while offline.
- **Leave:** requests, dates, reasons, decisions and balances.
- **Performance:** appraisals, ratings, comments and review cycles.
- **Training, awards and events:** enrollments and results, recognitions and their citations, invitations and replies.
- **Onboarding and offboarding:** checklists and, for an exit, its type, dates and reason.
- **Notifications** sent to you, and your choices about how you receive them.

### Job applicants

When you apply through a careers page, the organisation you apply to receives what you submit: your name and contact details, your current location, years of experience, profile links (such as LinkedIn or a portfolio), your **résumé** and any supporting documents, and your answers to the posting's screening questions. The organisation may add its own notes, ratings and interview records.

### Using the assistant

If your role includes the assistant, we keep your **conversations** with it — your messages, any files you attach, and its replies — so that you can return to them. You can delete them at any time.

### Technical and security information

To keep the service secure and accountable we record, for actions taken in SYNAPSE, **who** did what and **when**, together with the **IP address** and **browser** used (the "activity log"). Our servers also keep short-lived technical logs for diagnosing faults.

## 3. Why we use it

We, and your organisation, use personal information only to:

- **provide SYNAPSE** — sign you in, show you what your role allows, and do what you or your organisation ask of it;
- **run HR processes** for your organisation: hiring, onboarding, attendance, leave, appraisals, training, recognition, events and exits;
- **send notifications** — in the app, and by email or desktop notification if you choose;
- **keep the service secure** — prevent misuse, investigate incidents and keep the audit trail;
- **provide AI and predictive features** your organisation turns to (see [section 5](#5-ai-features-and-predictions));
- **comply with the law** and respond to lawful requests from authorities.

We do **not** sell personal information, and we do **not** use it for advertising.

### Our lawful basis

Your organisation decides the lawful basis for its HR records — usually that processing is necessary for your employment contract, for its legal obligations (for example, to government agencies), or for its legitimate interests — and it is responsible for telling you. For accounts and the running of the service, we rely on our contract with you and your organisation, our legitimate interest in providing a secure, working service, and our legal obligations. Where the law requires your **consent**, we or your organisation will ask for it, and you may withdraw it.

## 4. Who can see your information

- **People in your organisation** see only what their role allows. Your organisation decides the roles, and every change made in SYNAPSE is recorded in its audit trail.
- **Nobody outside your organisation** can see its workspace. Each organisation's records are kept separate from every other's.
- **{{operator}}** staff access customer data only when needed to provide, secure or support the service, or when the law requires it.
- **Service providers** who host and run parts of SYNAPSE for us, under contracts that require them to protect the information and use it only for us:

| Provider                                                                          | What they do for SYNAPSE                                                                                             |
| --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| **Supabase**                                                                      | Database and file storage — your organisation's records, documents and photos                                        |
| **DigitalOcean**, with **Cloudflare** in front of it                              | Runs the application, and protects it from attacks                                                                   |
| **Brevo**                                                                         | Delivers email — verification codes, invitations and notifications                                                   |
| **Google (Gemini)**                                                               | Powers the AI features described in [section 5](#5-ai-features-and-predictions)                                      |
| **OpenStreetMap**                                                                 | Map images and address search on the Locations screen — requested by your browser directly, when you use that screen |
| Your **browser's push service** (for example Google, Mozilla, Apple or Microsoft) | Delivers desktop notifications, if you turn them on                                                                  |

- **Authorities**, when the law requires us to disclose information.

## 5. AI features and predictions

### The AI features

Some features send information to **Google's Gemini** model to produce an answer:

- **The assistant** sends your message and the records needed to answer it. It never discloses **pay**, **government ID numbers**, **bank details**, **home addresses** or **dates of birth** in chat, whatever your role.
- **Candidate insights** in recruitment send a candidate's profile and their résumé and documents — but **never government ID documents**.
- **Report insights** send a report's totals, charts and a small sample of rows.
- **Coaching insights** on an appraisal send that appraisal.

Google processes this information to produce the answer, under its Gemini API terms. {{ai_training}}

### Predictions

SYNAPSE can estimate **attrition risk**, **promotion readiness** and **next-appraisal performance**, and rank job candidates by **fit**. These are calculated by our own models, from your organisation's records, and are not sent to anyone. They never use protected characteristics such as age, sex or civil status.

**No decision about you is made by a machine alone.** Scores and predictions are offered to the people at your organisation as one input among many, and SYNAPSE asks them to check before acting. If you want to know whether, or how, a prediction was used about you, ask your employer.

## 6. Cookies and similar technologies

We use only what SYNAPSE needs to work:

| Name                          | What it is for                                                    |
| ----------------------------- | ----------------------------------------------------------------- |
| Session cookie                | Keeps you signed in                                               |
| `XSRF-TOKEN`                  | Protects your forms from cross-site request forgery               |
| `appearance`, `sidebar_state` | Remember your light or dark mode, and whether the sidebar is open |
| `__cf_bm`                     | Set by Cloudflare to tell people from automated attacks           |

Your browser also keeps a few preferences on your device — such as unsent assistant messages and your preferred table or board view — which never leave it. We use **no advertising or tracking cookies**, and no third-party analytics.

## 7. How long we keep it

- **Your account** is kept until it is deleted — by you, from your profile settings, or by your organisation's administrators.
- **Your organisation's records** are kept for as long as your organisation keeps them. Your organisation decides how long, in line with its legal obligations. Records it archives stay restorable until it deletes them permanently.
- **Activity logs** are kept until your organisation clears them.
- **Assistant conversations** are kept until you delete them.
- **Verification codes, password-reset links and invitations** expire on their own.
- When an organisation stops using SYNAPSE, it can export its records first, and we delete its workspace on its request, except where the law requires us to keep something.
- **Backups** taken by our providers are overwritten on their own schedule.

## 8. How we protect it

- Connections to SYNAPSE are encrypted (HTTPS).
- Passwords are stored only as one-way hashes. Two-factor authentication and passkeys are available to everyone.
- Each organisation's records are kept separate from every other's, and every person sees only what their role allows.
- Changes are recorded in an audit trail, including those made through the assistant.
- Government ID numbers and bank details are masked on screen until someone chooses to reveal them.
- Our providers protect the data they hold for us with their own physical, technical and organisational safeguards.

No system is perfectly secure. If a personal data breach occurs, we will notify the affected organisation without undue delay, and we and the organisation will notify the National Privacy Commission and the people affected as the law requires.

## 9. Information stored outside the Philippines

Our service providers may store and process information outside the Philippines — for example in Singapore or the United States. We remain responsible for it, and we require our providers to protect it to the standard the Data Privacy Act sets.

## 10. Your rights

Under the Data Privacy Act you have the right to:

- **be informed** that your personal information is being processed, and how;
- **access** it, and receive a copy;
- **object** to its processing, including processing based on consent;
- **correct** it if it is inaccurate or incomplete;
- **erasure or blocking** of information that is no longer needed, was unlawfully obtained, or is processed in breach of your rights;
- **data portability** — receive it in a structured, commonly used electronic format;
- **damages**, if you are harmed by inaccurate, incomplete, outdated, false, unlawfully obtained or unauthorised use of your information;
- **file a complaint** with the National Privacy Commission.

**How to use them.** You can correct your name and email address, and delete your account, yourself in **Settings**. For your HR records, contact **your employer** first — it controls them, and we will help it answer. You can also write to us at **{{privacy_email}}**. We may need to confirm who you are first, and we will answer within the time the law allows.

Some rights have limits — for example, your employer may have to keep certain records for a period the law sets.

## 11. Children

SYNAPSE is a tool for organisations and is not directed at children. An organisation that employs or receives applications from people under 18 is responsible for processing their information as the law requires.

## 12. Changes to this policy

We may update this policy as SYNAPSE or the law changes. The date at the top shows when the current version took effect. When a change is significant, we will tell you in SYNAPSE or by email before it takes effect.

## 13. How to contact us

For privacy questions or to use your rights:

- **Privacy and data protection:** {{privacy_email}}
- **Operator:** {{operator}}
- **Address:** {{address}}

If you are not satisfied with our answer, you may contact the **National Privacy Commission** at [privacy.gov.ph](https://privacy.gov.ph).

See also the [Terms of Service](/terms).
