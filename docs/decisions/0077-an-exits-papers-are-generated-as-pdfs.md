# 0077 — An exit's papers are generated as PDFs

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0075 — Training certificates with expiry](./0075-training-certificates-with-expiry-and-budgets-with-costs.md)
    (which chose a printed page and no PDF library; this decision differs, and says
    why);
  - [0076 — Employees resign; leavers answer an exit interview](./0076-employees-resign-and-leavers-answer-an-exit-interview.md)
    (built alongside);
  - module docs: [Offboarding](../modules/offboarding.md),
    [Company Profile](../modules/company-profile.md).

## Context

The default clearance checklist had two lines, "Clearance form" and "Certificate of
Employment". Neither document existed. HR typed both in a word processor. The Labor
Code has an employer issue a Certificate of Employment to a leaver who asks for one,
and DOLE Labor Advisory 06-2020 sets three days to do it.

ADR 0075 printed training certificates from a page laid out for the browser's print
dialog, and added no PDF library. That suits a certificate the holder prints
themselves. It does not suit these two papers:

- a Certificate of Employment is often downloaded on a phone, where printing a page
  is awkward;
- it is sent on to a new employer, so it should be a file;
- the clearance form is printed and walked round, so it has to look the same
  wherever it is printed.

## Decision

### Dompdf renders Blade views

`dompdf/dompdf` (^3.1) is added. `App\Support\Offboarding\OffboardingDocuments`
renders two Blade views under `resources/views/documents/` on A4:

- **remote fetching is off** and PHP in templates is off, with a chroot on the views
  folder;
- the company logo is read from storage and **embedded as a data URI**: a raster
  image under 2 MB, or nothing.

A document can never make the server fetch a URL.

### The clearance form

The clearance form can be printed **at any point of the exit**, and says how far the
clearance has come on the day it was printed. It carries:

- the letterhead;
- the employee's details;
- the checklist grouped by department, with each item's state, who signed it off
  and a signature line;
- the reference `CLR-<id>`.

### The Certificate of Employment

The certificate needs the exit **completed**, because it states the last day. It
states the period and the position, and is signed by the company's signatory.

It is **numbered once**, `COE-<year>-<id>`, the first time it is issued, and the
number is stamped on the case (`certificate_no`, `certificate_issued_at`). Every
later copy carries the same number. That first issue is the one recorded in the
audit trail, with "at their request" when the leaver downloaded it.

The signatory's name and title are two new Company Profile fields, under
*Documents*. Without them the certificate leaves a blank line to sign.

### Who gets them

- HR (`offboarding.view`) downloads both from the exit's **Documents** menu.
- The leaver downloads their certificate from **My resignation** once it is ready.
- In the app, `POST /api/offboarding/certificate-link` returns a **signed URL valid
  for 10 minutes**, minted for the case and the user. The app opens it in the
  browser, which can save a PDF.

  `GET /api/documents/certificate/{case}/{user}` checks the signature, binds the
  case's organisation and checks the employee belongs to that user. It carries no
  token, so a bearer token never sits in a URL.

PDFs are answered inline, `private, no-store`.

## Consequences

- The project now has a PDF dependency. ADR 0075's printed training certificate is
  unchanged. Moving it onto Dompdf is a separate, small decision.
- Only these two documents, with one letterhead for the company. Per-document
  letterheads, templates the company edits, and a certificate for people still
  employed are not built.
- A document is generated on request and never stored. The number is what makes two
  copies the same certificate.
