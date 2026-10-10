# A queued punch that wouldn't send now sends

A Time Out punch waited on the phone, and pressing **Send now** did nothing — no
request, no message.

## Highlights

- **Every request has a deadline.** React Native's Android HTTP client sets no
  timeouts, and the app set none either. A queued send that went out on a connection
  that never answered never finished, so the queue stayed "sending" for good and every
  later attempt — the button, the 30-second retry, coming back to the app — returned at
  once. Requests now give up after 30 seconds (60 for a punch with a selfie) and are
  treated as "not sent yet".
- **Send now says what happened.** It shows a spinner, then "Punch sent.", or that
  SYNAPSE couldn't be reached and the punch is kept and retried. A refusal still shows
  the server's reason.

## Mobile

- `lib/api.ts`: an `AbortController` deadline covers the whole exchange
  (`DEFAULT_TIMEOUT_MS`, overridable per call with `{ timeoutMs }`); a timeout becomes
  `ApiError(0, 'The server took too long to answer.')`.
- `features/attendance/api.ts`: a punch allows 60 seconds with a photo, 30 without.
- `features/attendance/punch-queue.ts`: `flush()` shares the attempt in flight (an
  `inFlight` promise instead of a `sending` flag), so a press during a background send
  waits for it and reports its outcome: `{ sent, refused, waiting, offline }`.
  Refusals are published through `onRefused()`. A send stops if the signed-in person
  changes part-way.
- `features/attendance/punch-queue-runner.tsx`: announces refusals from `onRefused`, so
  nothing is toasted twice.
- `app/(tabs)/clock.tsx`: *Send now* shows progress and the outcome.

## Notes

- **Verified:** in the Expo web build against a server that accepts the connection and
  never answers — before, *Send now* did nothing; after, it times out, says so, and the
  next press sends. Mobile tsc and lint pass.
- **To clear the punch already stuck on a phone:** fully close and reopen the app (or
  reload it in Expo Go) after updating. The old code's "sending" flag lives only in
  memory, so a restart frees it, and the queue sends on start.
