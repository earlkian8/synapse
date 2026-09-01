# SYNAPSE Mobile

The employee-facing companion to the SYNAPSE HR ERP — built with Expo (SDK 54),
expo-router and TypeScript. It signs in against the live server's Sanctum token
API and gives employees their day-to-day: clock in/out (DTR), view attendance
with metrics, file and track leave, and view their awards and profile.

## Features

- **Auth** — branded splash + login; token stored in SecureStore; auto-restored.
- **Home** — today's clock state, quick actions, leave balances, latest award.
- **Clock (DTR)** — live clock, today's shift, state-driven Time-In / Break /
  Time-Out with real GPS and an optional selfie; live worked-hours counter.
- **Attendance** — month calendar with status dots, a metrics summary card, a
  list view, and a per-day punch timeline.
- **Leave** — balances, a file-leave form (server-computed days), history, cancel.
- **Profile + Awards** — the 201 profile (IDs masked) and your recognitions.
- Polish throughout: skeletons, empty states, pull-to-refresh, toasts, haptics,
  and light/dark themes.

## Running it

The app talks to the Laravel server in `../server`.

1. **Seed + run the server** (from `../server`):

   ```bash
   php artisan migrate:fresh --seed
   php artisan serve --host 0.0.0.0      # 0.0.0.0 so your phone can reach it
   ```

   Seeding links the demo account to an employee — sign in with
   `earlkian.dev@gmail.com` / `password`.

2. **Point the app at your machine.** Find your computer's LAN IP (`ipconfig` on
   Windows, e.g. `192.168.1.23`) and set it in `app.json`:

   ```json
   "extra": { "apiUrl": "http://192.168.1.23:8000/api" }
   ```

   Or, without editing `app.json`, export `EXPO_PUBLIC_API_URL` before starting.

3. **Start Expo and open in Expo Go** on your phone (same Wi-Fi):

   ```bash
   npm install
   npx expo start
   ```

## Project structure

```
app/                 expo-router routes (auth stack, (tabs) shell, detail stacks)
components/ui/        shared UI kit (Button, Card, Pill, Input, Sheet, Toast, …)
features/<module>/    per-feature api.ts + components (attendance, leave, awards, profile)
lib/                 api client, auth, formatting, location/selfie helpers
theme/               design tokens + ThemeProvider (light/dark)
types/api.ts         API response shapes
```

## Quality

```bash
npx tsc --noEmit     # typecheck
npx expo lint        # lint
```
