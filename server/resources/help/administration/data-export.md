**System** → **Data Export** gives you a copy of your company's records as one archive: a ZIP file you can keep for your own files, hand to an auditor, or bring to another system. Every record is included as SYNAPSE stores it, archived ones too.

## What you can export

The screen lists every kind of record **you're allowed to view**, grouped the way the sidebar is: recruitment, onboarding, employees, attendance, leave, appraisals, training, awards, events, offboarding, the three predictions, your company setup, user accounts, roles, and the activity logs. Each shows how many records it holds today.

If your role can't open a screen, its records aren't offered to you. An export never gives you more than the screens do.

## Preparing an archive

1. Tick what to include. Everything you can see starts ticked; use **Select section** and **Clear section** to change a whole group at once.
2. Choose a format:
   - **CSV** opens in Excel, Numbers or Google Sheets, one file per table;
   - **JSON** is for moving your records into another system.
3. Turn on **Include uploaded files** to add photos, résumés, documents and certificates. The archive gets larger.
4. Choose **Prepare archive**.

The panel on the right shows the folders the archive will unzip into as you choose.

SYNAPSE writes the archive in the background. You can leave the page; you'll get a [notification](/help/your-account/notifications) when it's ready. Only one archive is prepared at a time in a company.

## Downloading it

Choose **Download** next to your archive. It's kept for **7 days**, then deleted.

- **Only you can download your archive.** Others who can open the screen see that it exists, and who prepared it, but can't take it.
- If your role changes and you can no longer view something that's in it, you can't download it any more. Prepare a new one.
- Every download is recorded in the [activity logs](/help/administration/activity-logs).

## What's inside

- `README.txt` says when the archive was made, by whom, and what's in it.
- `manifest.json` lists every file, its columns and its row count, for software.
- A folder for each kind of record, with one file per table.
- `files/`, when you included uploads, under the same names the records use.

Every table keeps its ids, so the files link up: an employee's `department_id` is a department's `id`. Stored times are in UTC.

> [!NOTE]
> In CSV files, a value starting with `=`, `+`, `-` or `@` is written with an apostrophe in front, so a spreadsheet shows it instead of running it as a formula.

## What's never included

- Passwords, two-step sign-in secrets, invitation links and codes, and your company's join code. They let someone sign in, and aren't records.
- Each person's own conversations with the assistant, and their notifications.

> [!WARNING]
> An archive holds personal information about your people: government ID numbers, pay, bank details and more. Store it somewhere safe, share it only with those entitled to it, and delete it when you no longer need it. Deleting an archive here doesn't touch your records in SYNAPSE.

## Isn't this a backup?

Your company's database is already backed up by our hosting provider. This screen is how **you** take a copy of your records. It's what to do before your company stops using SYNAPSE, too.
