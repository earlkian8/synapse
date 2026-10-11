**Company Setup** → **Work Schedule & Holidays** holds two things attendance depends on: the **schedules** people work, and your **holiday calendar**.

## Work schedules

A schedule is a template of working days and hours that people are assigned to. Each one has:

- a **name**;
- how its days are judged:
    - **Fixed** — set start and end times. Late means after the start; undertime means leaving before the end.
    - **Flexible** — **core hours** everyone must be present for, plus a number of hours to work. Late means after the core start.
    - **Hours only** — just a number of hours a day. Nobody is ever late; undertime means working short.
- a **cycle**: a 7-day week, or a **rotation** of any length starting from an anchor date (for example four on, four off);
- a **grace** period for lateness;
- **one row per day of the cycle**: worked or a rest day, its hours, and the minutes required.

A day can have more than one stretch of hours — that makes it a **split shift**. An end time at or before the start time means the shift ends the next morning, so a night shift is simply **22:00–06:00**.

Before you save, the editor previews the **next 14 days** of the pattern, so you can check a rotation lands where you expect.

Each schedule shows how many people are assigned to it. Star one as your **company default** — the hours used for anyone not given a schedule some other way. A schedule can also name the [attendance policy](/help/company-setup/attendance-policies) its days are judged by.

### Which schedule applies to someone

The most specific one wins, in this order:

1. a one-off change on the [shift roster](/help/company-setup/shift-roster) for that day;
2. a schedule assigned to them from a date;
3. the schedule on their employee record;
4. their department's default;
5. their main work location's default;
6. the company default;
7. if none of those, Monday to Friday, 08:00–17:00.

## Holidays

Each holiday has a name, a type, when it falls, and who keeps it. The list is a table, the next one first, with the date each falls on next and how it repeats.

### When it falls

| Repeats                                 | For                                                                             |
| --------------------------------------- | ------------------------------------------------------------------------------- |
| **Doesn't repeat**                      | A one-off, or a day proclaimed for one year                                     |
| **Every year on this date**             | New Year's Day, Independence Day, Christmas                                     |
| **Every year, counted from Easter**     | Holy Week: Maundy Thursday (3 days before), Good Friday (2), Black Saturday (1) |
| **Every year, on a weekday of a month** | National Heroes Day, the last Monday of August                                  |

A holiday that follows Easter or a weekday is worked out for every year — you never re-enter it. The form shows the next two dates before you save. **Add Holy Week** puts Maundy Thursday, Good Friday, Black Saturday and National Heroes Day on the calendar this way in one go; if you had typed one in for a single year, it is turned into its rule rather than doubled.

### Who keeps it

A holiday is kept by **everyone**, or only at **some sites** — a city's charter day, a provincial feast. A local holiday is a day off only for the people whose base site (their primary [work location](/help/company-setup/work-locations), or their only one) is one of its sites: their leave isn't charged on it and their attendance reads it as a holiday. Everyone else works as usual. The [shift roster](/help/company-setup/shift-roster) marks it on the person's own cell.

### Type

| Type                    | What it means                                                                 |
| ----------------------- | ----------------------------------------------------------------------------- |
| **Regular**             | A non-working day. Nobody is expected at work, and leave isn't charged on it. |
| **Special non-working** | Also a non-working day.                                                       |
| **Special working**     | An ordinary working day — work as normal.                                     |

Someone who works on a non-working holiday has it counted as holiday work.

> [!TIP]
> The Setup Guide can add the Philippine holiday calendar for you, Holy Week and National Heroes Day included as yearly rules. Holidays proclaimed afresh each year — Eid'l Fitr, Eid'l Adha, Chinese New Year — have no rule to follow: add each one when it is announced.

## Archiving

Archive a schedule or holiday you no longer use; restore it from the archived list. A schedule people are still assigned to can't be deleted permanently.

> [!NOTE]
> Editing a schedule or adding a holiday doesn't change days already recorded. If past days should follow the change, re-apply the rules from the attendance board — see [Correcting and signing off attendance](/help/workforce/correcting-and-signing-off-attendance).
