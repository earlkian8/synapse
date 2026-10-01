An **attendance policy** decides how a working day is judged: how much lateness is forgiven, when a day becomes a half day, what counts as overtime and whether it needs approval, how breaks and night work are treated, and how punches may be made. You choose a preset and adjust it with plain options — there are no formulas to write.

Find them under **Company Setup** → **Attendance Policies**.

## Presets

Choose **New policy** and start from a preset, or from the built-in rules:

| Preset                       | In short                                                                                                                                           |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Philippines — Labor Code** | Overtime after 8 hours a day, needing approval; a 1-hour unpaid lunch taken off after 5 hours if none was punched; night differential 22:00–06:00. |
| **Standard 40-hour week**    | Overtime after 40 hours a week; a 30-minute unpaid break after 6 hours.                                                                            |
| **Flexible, no lateness**    | Nobody is judged late; a day is short only on hours.                                                                                               |
| **Shift work**               | Times rounded to the nearest 15 minutes; overtime after 8 hours a day and 40 a week; a forgotten clock-out closed 2 hours after the shift.         |

A policy made from a preset can be put back to it at any time with **Reset to** the preset.

## The settings

Settings sit in groups, and each group's heading sums up what it amounts to, so you can read a policy without opening every group.

- **Punch windows** — how early a clock-in still counts towards the shift, and how long an open shift keeps claiming punches.
- **Lateness** — whether lateness is judged at all; the **grace** forgiven each day (or a **monthly allowance** drawn down as people are late); when lateness makes a day a **half day**, or **absent**.
- **Undertime** — whether short means leaving before the shift ends or only working too few hours; when a short day becomes a half day or absent.
- **Rounding** — round clock-ins and clock-outs to 5, 10, 15 or 30 minutes (nearest, up or down). The punches themselves never change.
- **Breaks** — how much of a punched break is paid; an unpaid break taken off automatically when none was punched; the longest a break may run.
- **Overtime** — daily, weekly, or both; after how long; the smallest block that counts; whether early clock-ins count; whether overtime **needs approval**; whether all rest-day or holiday work counts as overtime.
- **Missing clock-out** — flag it for a manager, or close the day automatically at the shift's end (or some time after). An automatic close needs a sign-off.
- **Night differential** — the window whose minutes count as night work.
- **Reminders** — remind people who haven't clocked in some minutes into their shift.
- **Capture** — where punches may come from (web, mobile, entered by HR); whether a mobile punch needs a **selfie**; what happens to a punch outside every [work location](/help/company-setup/work-locations)'s fence — **off** (just record where it was), **flag** it, or **block** it; which networks web punches must come from; how late a phone may send a punch it queued offline; and how far off a phone's clock may be before it's flagged.

## The worked example

Beside the settings, a **worked example** shows a sample day — choose a working day, rest day or holiday; the shift; the clock-in and clock-out; a break — and what your policy makes of it: the status and flags, regular and overtime minutes (and whether overtime awaits approval), lateness and how much grace forgave, short time, the break, and night, rest-day and holiday minutes. It updates a moment after you stop typing, using exactly the rules attendance will use.

## Which policy applies to a day

The most specific one wins:

1. the policy named on a schedule **assignment** for that date;
2. the policy on the **schedule** that day's shift comes from;
3. the person's **department's** policy;
4. their main **work location's** policy;
5. the **company default** (the star);
6. the built-in rules — exact punch times, each schedule's own grace, overtime as anything worked beyond the day's hours (never needing approval), breaks exactly as punched, and no thresholds or night differential.

> [!NOTE]
> A company that configures nothing is judged by the built-in rules.

## Changing a policy

Each day keeps the policy it was judged by when it opened. Editing or archiving a policy changes how **new** days are judged, never days already recorded. To bring past days in line, [re-apply the rules](/help/workforce/correcting-and-signing-off-attendance).

A policy still named by a schedule, department, location or assignment can't be deleted permanently — archive it instead.
