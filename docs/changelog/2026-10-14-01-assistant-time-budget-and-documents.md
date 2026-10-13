# Assistant: answers inside the gateway timeout, reads attached documents fully

Assistant turns sometimes ended in a bare 504 (the hosting edge cuts requests off at
about 100 s), and a CV turn read the asker's own record because the message said "my".
A turn now has a time budget and answers inside it. A document is read in full and
every detail it states is filed. The default model is Gemini 3.5 Flash with low
thinking. See [ADR 0086](../decisions/0086-the-assistant-answers-inside-the-gateway-timeout.md).

## Highlights

- No more silent 504s from long turns. A turn that runs out of time answers with what
  it did, or, if it did nothing yet, with a retryable "took too long".
- "Add this CV to the Software Engineer posting" is enough. Email, phone, location,
  headline, links, years of experience and a skills/education/roles summary are filed
  without being asked for.
- A turn with a document no longer pulls the asker's own record into the prompt.

## Backend

- `GeminiClient::until()` / `secondsLeft()`: a deadline for every request. Each request
  waits at most what is left (max 60 s, 10 s to connect). Retries happen only when they
  fit. A connection failure or timeout becomes `GeminiException(504)`.
- `GeminiException`: `isTimeout()`; `isBusy()` now includes 504.
- `Assistant::handle()`: the loop runs under `GEMINI_TURN_SECONDS` (75 s). No step
  starts with under 12 s left, and a later step's timeout ends the loop instead of
  failing the turn.
- `Retriever` / `SubjectResolver`: on a turn with files, only typed names pick a
  subject.
- The system instruction, the user turn's document note, Recruitment's guidance and
  the `notes` parameter tell the model to read documents fully and carry every detail
  over.
- `AssistantController`: a "took too long" message for timeouts.
- `config/services.php`: `turn_seconds`; default model `gemini-3.5-flash`.
  `.env.example`: `GEMINI_MODEL=gemini-3.5-flash`, `GEMINI_THINKING_LEVEL=low`,
  `GEMINI_TURN_SECONDS=75`.

## Frontend

- `features/assistant/api.ts`: a gateway's 502/503/504/524 page and a non-JSON 2xx
  reply get a "try again" message instead of "failed (200)" / "failed (504)".

## Tests

- `GeminiClientTest`: a timeout is a retryable 504; nothing is sent once the deadline
  has all but passed; the request timeout follows the deadline; no 503 retry when it
  could not finish.
- `AgentLoopTest`: a first-step timeout fails as retryable with nothing changed; a later
  timeout still reports what was done; no step starts when nearly out of time; a
  document turn is told to read in full.
- `RetrievalTest`: on a document turn, "my" and pronouns pick nobody, and a typed name
  still resolves.

## Notes

- Pest 1,990/1,990 (serial, local Postgres, no Gemini key). Pint, tsc, ESLint,
  Prettier and `npm run build` pass. No real Gemini request was made, and the Docker
  image was not built.
- The "(200)" failure was not reproduced. It now shows a clear message, and its cause
  is still open.
