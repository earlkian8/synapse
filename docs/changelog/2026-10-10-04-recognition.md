# Recognition: kudos, nominations, points and rewards

Recognition used to be something HR did and nobody else saw. The page called
"Nominations" was an AI-ranked shortlist, and award recipients weren't even told. Now
everyone takes part. See
[ADR 0071](../decisions/0071-recognition-kudos-nominations-points-and-rewards.md).

## Highlights

- **Recognition wall** (`/awards/wall`, web and mobile). It shows kudos and awards from
  the last 90 days, filterable to Kudos or Awards. Thank a colleague in a line; the
  kudos earns them points while you have kudos with points left this month.
- **Nominate → approve.** Anyone can put a colleague forward for an award type open to
  nominations, and the reason becomes the citation. HR approves (with an editable
  citation and an AI draft), which gives the award and its points, or turns it down
  with a note. Nobody reviews a nomination they're part of.
- **Points and rewards.** Awards and kudos earn points, kept in an append-only ledger.
  **My points** shows the balance, the catalogue, your requests and where every point
  came from. Redeeming holds the points under a lock, so a double tap can't spend them
  twice. HR hands the reward over, or declines and the points come back.
- **HR's desks.**
  - **Nominations**, with the AI shortlist as its second view.
  - **Rewards**: requests, the catalogue, top balances with *Adjust points*, and the
    kudos settings.
  - Award types gain **Points** and **Open to nominations**.
- **Recipients are told.** Whoever gets an award or kudos is notified, with the
  points.
- **One way in.** Awards & Recognition is a single sidebar entry for anyone who can
  view awards or take part. Its sections are **Wall · My points · My nominations ‖
  Awards · Nominations · Rewards**, and each shows only to people who may open it.
  "Recognition" no longer sits in Main next to a near-identical "Awards & Recognition".

## Server

- New permission `awards.participate`, back-filled to the built-in Staff, Department
  Head and HR Manager roles.
- Migration `2026_10_10_010000_create_recognition_tables`:
  - adds `award_nominations`, `kudos`, `point_transactions`, `rewards` and
    `reward_redemptions`;
  - adds `award_types.points` and `accepts_nominations`;
  - adds `organizations.kudos_points` and `kudos_monthly_limit`.
- `App\Support\Recognition`: `PointsLedger`, `KudosWorkflow`, `NominationWorkflow`,
  `RewardWorkflow` and `RecognitionException`. `AwardWorkflow` keeps points in step
  (`settle()`) and notifies the recipient.
- Routes:
  - the participant routes live under `/awards`: `wall`, `points`, `my-nominations`,
    `kudos`, `rewards/{reward}/redeem` and `redemptions/{id}/cancel`;
  - HR's routes are `nominations` (approve / reject), `shortlist` and `rewards`
    (catalogue, fulfil / decline, adjust, settings);
  - mobile: `/api/recognition`, `/api/kudos`, `/api/nominations`, `/api/points`,
    `/api/rewards` and `/api/redemptions`.
- `AwardController@index` sends someone with only `awards.participate` to the wall.
  HR pages carry the count of nominations waiting.
- The wall puts a backdated award at noon of its date, so a late entry doesn't jump to
  the top.
- The assistant gains `give_kudos`, `nominate_colleague` and `get_my_points`
  (participants), and `find_nominations`, `review_nomination`, `find_redemptions` and
  `handle_redemption` (HR). Kudos, nominations, reviews and hand-overs wait for
  Confirm. The topic brief carries one's own points and HR's waiting counts.
- `SystemGuide` gains the wall, My points, My nominations, Nominations and Rewards. The
  router knows kudos, points and rewards (not "thank", which would pull these tools
  into every "thanks!").
- Data Export covers the five tables.
- The seeder adds points, rewards, kudos and nominations.

## Frontend

- Pages:
  - `awards/wall.tsx`: one list, Everything / Kudos / Awards, *Show more*;
  - `awards/points.tsx`: stat tiles, catalogue, requests, history;
  - `awards/my-nominations.tsx`;
  - `awards/nominations.tsx` (the queue) and `awards/shortlist.tsx` (the AI view);
  - `awards/rewards.tsx` (the desk).
- `AwardsNav` (two groups) and `NominationViews` (underline tabs), built on the shared
  `ModuleNav`.
- A self-review guard shows who decides instead of a disabled button with a tooltip
  that never appears.
- Award type form: points and open to nominations. Dashboard shortcuts point at the
  wall and My invitations.

## Mobile

- `app/recognition/index.tsx` (the wall), `kudos` and `nominate` (modals),
  `nominations`, and `app/rewards/index.tsx`.
- Home has a Recognition shortcut. Profile has *Recognition* and *Points & rewards*.

## Docs

- `modules/awards.md`, `modules/mobile-app.md` and `modules/notifications.md`.
- `database/awards-tables.md` and ERD §9.
- Help:
  - *Awards and recognition* is rewritten;
  - new: *Kudos, points and rewards*;
  - *Award types* and the mobile article are extended.
- `not-yet-built.md`: nominations, points, rewards and kudos are removed; expiring
  points, budgets and reactions are listed.

## Notes

- **Verified:**
  - tested as HR and as Staff in headless Chromium, at desktop and phone width;
  - kudos, nominate, redeem, approve and hand-over checked in the browser and the Expo
    web build;
  - Pest (count in the commit message), Pint, tsc, ESLint, Prettier and the build.
- Points are a company currency, not money. Nothing goes to payroll.
