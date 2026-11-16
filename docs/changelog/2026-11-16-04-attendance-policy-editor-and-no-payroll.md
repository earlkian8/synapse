# The attendance policy editor reads as plain rules; 13th month pay is out

The attendance policy editor was hard to understand at first sight: ten collapsible
groups of labels and switches, where a switch that was off showed what "off" meant in
words that read like the opposite. It now reads as a short rulebook. And the 13th month
pay module built earlier the same day is gone before it shipped: it is payroll, which
SYNAPSE leaves out ([ADR 0019](../decisions/0019-remove-payroll-and-benefits.md)).

## Highlights

- **Six topics, one at a time.** *About this policy*, *Late arrivals*, *Short days*,
  *Breaks*, *Overtime & night work*, *Clocking in*, *Fine-tuning*, in a rail that says in
  a line what each is set to. **Next** walks them in order; a save that fails opens the
  topic with the problem, marked in red.
- **Every rule is a sentence.** "Count it as a half day when someone is more than
  [2 hours] late." Tick it to turn it on; when it's off it stays readable, greyed out.
  Choices are written out in full ("A day is short when someone… leaves before their shift
  ends"). Lengths of time read in minutes or hours, whichever is natural.
- **Start from a card, not a chip.** Each preset shows what makes it different;
  *A plain start* is the built-in rules.
- **Ways to clock in explained.** Each is a card with a line on what it is (the app, a QR
  station, a kiosk, the web, entered by HR).
- **The sample day** (formerly the worked example) sits beside the rules; on a phone it
  folds under them.
- **Who follows which policy?** on the list is now a numbered list, open when no policy
  is the company default.

## Removed

- 13th month pay: its tables, screens (web and app), permissions, Help Center articles,
  Data Export entry and ADR 0104. Nothing of it was committed or released. The
  `DocumentRenderer` it introduced stays, used by the exit papers and the discipline
  notices.

## Frontend (`server/resources/js`)

- `features/attendance-policy-config/components/rule-kit.tsx` (`Rule`, `Options`,
  `Statement`, `DurationBlank`, `NumberBlank`, `TimeBlank`, `SelectBlank`);
  `policy-sections.tsx` rewritten as the topic rail and the rules; `policy-form-modal.tsx`
  rebuilt around it; `constants.ts` gains `topicSummaries()` and `SOURCE_DESCRIPTIONS`.
  The setup wizard's customise step uses the same editor.

## Notes

- Settings, validation and the server's evaluation are unchanged: only the editor is
  new. Checked in headless Chromium at 1440 and 390 px, every topic, no script errors.
