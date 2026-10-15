import { useEffect, useEffectEvent, useState } from 'react';
import * as api from '../api';
import { beep } from '../sound';
import type {
    KioskInfo,
    KioskPageState,
    KioskPunchResult,
    KioskPunchType,
    KioskSession,
} from '../types';

/** The longest an employee number typed at the keypad can be. */
const NUMBER_MAX = 12;

/** How often the kiosk checks in with the server. */
const HEARTBEAT_MS = 60_000;

/** Somebody who walks away mid-way is signed out after this long untouched. */
export const IDLE_MS = { pin: 20_000, choose: 60_000, person: 30_000 } as const;

/** How long the stamped card stays up before the next person's turn. */
export const DONE_MS = 6_000;

export type KioskStep =
    | { kind: 'number' }
    | { kind: 'pin'; number: string }
    | { kind: 'choose'; session: KioskSession; first: string | null }
    | { kind: 'person'; session: KioskSession }
    | { kind: 'done'; session: KioskSession; result: KioskPunchResult };

/**
 * The kiosk's whole turn for one person (ADR 0092), as a small state machine:
 *
 *   number → PIN → (choose a PIN of their own, the first time) → their card
 *   → stamped → the next person.
 *
 * It owns the keypad's entry, the message under it, the idle sign-out, and the
 * heartbeat that keeps the kiosk's clock on the server's and notices when HR
 * has signed the device out.
 */
export function useKiosk(
    initial: KioskInfo,
    /** The device stopped being a kiosk, or somebody signed in on it. */
    onLost: (state: Exclude<KioskPageState, 'ready'>) => void,
) {
    const [kiosk, setKiosk] = useState(initial);
    const [offset, setOffset] = useState(
        () => Date.parse(initial.now) - Date.now(),
    );
    const [step, setStep] = useState<KioskStep>({ kind: 'number' });
    const [entry, setEntry] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    // Bumped on every key press, so the idle timer restarts from it.
    const [touched, setTouched] = useState(0);

    const pinLength = kiosk.pin_length;

    const lost = useEffectEvent(onLost);

    // ── The heartbeat ────────────────────────────────────────────────────
    useEffect(() => {
        const id = window.setInterval(async () => {
            const outcome = await api.fetchState();

            if (outcome.ok) {
                setKiosk(outcome.data.kiosk);
                setOffset(Date.parse(outcome.data.kiosk.now) - Date.now());
            } else if (
                outcome.reason === 'unpaired' ||
                outcome.reason === 'signed_in'
            ) {
                lost(outcome.reason);
            }
        }, HEARTBEAT_MS);

        return () => window.clearInterval(id);
    }, []);

    // ── Leaving a turn ───────────────────────────────────────────────────

    /** Back to the start, dropping whatever ticket is still live. */
    const reset = (note: string | null = null) => {
        if (
            (step.kind === 'person' || step.kind === 'choose') &&
            step.session.ticket
        ) {
            api.done(step.session.ticket);
        }

        setStep({ kind: 'number' });
        setEntry('');
        setMessage(note);
        setBusy(false);
    };

    const onIdle = useEffectEvent(() => {
        if (step.kind === 'done') {
            reset();
        } else {
            reset('Signed out — nothing was recorded.');
        }
    });

    useEffect(() => {
        const wait =
            step.kind === 'done'
                ? DONE_MS
                : step.kind === 'number'
                  ? null
                  : IDLE_MS[step.kind];

        if (wait === null) {
            return;
        }

        const id = window.setTimeout(onIdle, wait);

        return () => window.clearTimeout(id);
    }, [step, touched]);

    // ── What the server said ─────────────────────────────────────────────

    const refused = (outcome: { message: string; reason: string | null }) => {
        beep('refused');

        if (outcome.reason === 'unpaired' || outcome.reason === 'signed_in') {
            onLost(outcome.reason);

            return;
        }

        if (outcome.reason === 'expired') {
            reset(outcome.message);

            return;
        }

        setMessage(outcome.message);
    };

    const enterSession = (session: KioskSession) => {
        setEntry('');
        setMessage(null);
        setStep(
            session.must_change_pin
                ? { kind: 'choose', session, first: null }
                : { kind: 'person', session },
        );
    };

    const submitPin = async (number: string, pin: string) => {
        setBusy(true);
        const outcome = await api.signIn(number, pin);
        setBusy(false);
        setEntry('');

        if (outcome.ok) {
            enterSession(outcome.data);
        } else {
            refused(outcome);
        }
    };

    const submitChosen = async (
        session: KioskSession,
        first: string,
        second: string,
    ) => {
        setBusy(true);
        const outcome = await api.choosePin(session.ticket, first, second);
        setBusy(false);
        setEntry('');

        if (outcome.ok) {
            enterSession(outcome.data);
        } else {
            setStep({ kind: 'choose', session, first: null });
            refused(outcome);
        }
    };

    // ── The keypad ───────────────────────────────────────────────────────

    const limit =
        step.kind === 'number'
            ? NUMBER_MAX
            : step.kind === 'pin' || step.kind === 'choose'
              ? pinLength
              : 0;

    const press = (digit: string) => {
        if (busy || entry.length >= limit) {
            return;
        }

        const next = entry + digit;
        setTouched((n) => n + 1);
        setEntry(next);
        setMessage(null);

        if (next.length < pinLength) {
            return;
        }

        // A full PIN goes as soon as it is typed — one tap fewer at the door.
        if (step.kind === 'pin') {
            void submitPin(step.number, next);
        } else if (step.kind === 'choose' && step.first === null) {
            setStep({ ...step, first: next });
            setEntry('');
        } else if (step.kind === 'choose' && step.first !== null) {
            void submitChosen(step.session, step.first, next);
        }
    };

    const erase = () => {
        setTouched((n) => n + 1);
        setEntry((value) => value.slice(0, -1));
    };

    /** The keypad's Next: from the number to the PIN. */
    const next = () => {
        if (step.kind !== 'number' || entry.trim() === '' || busy) {
            return;
        }

        setStep({ kind: 'pin', number: entry });
        setEntry('');
        setMessage(null);
    };

    /** One step back: the PIN to the number; anything later starts over. */
    const back = () => {
        if (step.kind === 'pin') {
            setStep({ kind: 'number' });
            setEntry(step.number);
            setMessage(null);
        } else if (step.kind !== 'number') {
            reset();
        } else {
            setEntry('');
            setMessage(null);
        }
    };

    // ── Punching ─────────────────────────────────────────────────────────

    const record = async (type: KioskPunchType, photo: Blob | null) => {
        if (step.kind !== 'person' || busy) {
            return;
        }

        const { session } = step;
        setTouched((n) => n + 1);
        setBusy(true);
        setMessage(null);
        const outcome = await api.punch(session.ticket, type, photo);
        setBusy(false);

        if (outcome.ok) {
            beep('punch');
            setStep({
                kind: 'done',
                session: { ...session, day: outcome.data.day },
                result: outcome.data,
            });
        } else {
            refused(outcome);
        }
    };

    // A kiosk with a keyboard: digits, Backspace, Enter and Escape work too.
    const onKey = useEffectEvent((event: KeyboardEvent) => {
        if (event.target instanceof HTMLInputElement) {
            return;
        }

        if (/^\d$/.test(event.key)) {
            press(event.key);
        } else if (event.key === 'Backspace') {
            erase();
        } else if (event.key === 'Enter') {
            if (step.kind === 'done') {
                reset();
            } else {
                next();
            }
        } else if (event.key === 'Escape') {
            back();
        } else {
            return;
        }

        event.preventDefault();
    });

    useEffect(() => {
        const listener = (event: KeyboardEvent) => onKey(event);
        window.addEventListener('keydown', listener);

        return () => window.removeEventListener('keydown', listener);
    }, []);

    return {
        kiosk,
        offset,
        step,
        entry,
        message,
        busy,
        pinLength,
        touched,
        press,
        erase,
        next,
        back,
        reset,
        record,
    };
}

export type KioskController = ReturnType<typeof useKiosk>;
