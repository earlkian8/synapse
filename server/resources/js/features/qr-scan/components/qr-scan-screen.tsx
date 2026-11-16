import {
    AlertTriangle,
    Camera,
    Clock3,
    LocateFixed,
    QrCode,
    Smartphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { KIOSK_TOKENS } from '@/features/kiosk/constants';
import { clockTime } from '@/features/kiosk/hooks/use-kiosk-clock';
import type { QrPunchType } from '@/features/qr-stations/types';
import { cn } from '@/lib/utils';
import { locate, qrScanApi } from '../api';
import type { QrDay, QrIdentified, QrPunched, QrScanPageProps } from '../types';

/** What each punch is called on the button, and once it has landed. */
const PUNCHES: Record<
    QrPunchType,
    { action: string; done: string; ink: string }
> = {
    clock_in: { action: 'Time in', done: 'Timed in', ink: 'var(--k-in)' },
    break_start: {
        action: 'Start break',
        done: 'Break started',
        ink: 'var(--k-break)',
    },
    break_end: {
        action: 'End break',
        done: 'Back from break',
        ink: 'var(--k-break)',
    },
    clock_out: { action: 'Time out', done: 'Timed out', ink: 'var(--k-out)' },
};

const ORDER: QrPunchType[] = [
    'clock_in',
    'break_start',
    'break_end',
    'clock_out',
];

/** Where the last number typed on this phone is kept — a convenience, not a secret. */
const NUMBER_KEY = 'synapse.qr.employee-number';

type Step =
    | { kind: 'who' }
    | {
          kind: 'choose_pin';
          ticket: string;
          firstName: string;
          day: QrDay;
          until: number;
      }
    | {
          kind: 'punch';
          ticket: string;
          firstName: string;
          day: QrDay;
          until: number;
      }
    | { kind: 'done'; punched: QrPunched }
    | { kind: 'stopped'; message: string; reason?: string };

/**
 * Scanning a QR station with a phone's own camera, no app (ADR 0103): who you
 * are (employee number and PIN), where you are (the phone's location, asked for
 * once), a PIN of your own the first time, a photo if your policy wants one,
 * and the punch — stamped on a time card, in the station screen's own look.
 *
 * The page holds the code it was opened with for a few minutes; past that, or
 * after the punch, it says to scan again rather than offering anything stale.
 */
export function QrScanScreen(props: QrScanPageProps) {
    const { station, organization } = props;

    return (
        <div
            style={KIOSK_TOKENS}
            className="flex min-h-dvh flex-col bg-(--k-ground) text-white antialiased"
        >
            <header className="flex items-center gap-3 px-5 pt-5 pb-4">
                {organization?.logo_url ? (
                    <img
                        src={organization.logo_url}
                        alt=""
                        className="size-10 shrink-0 rounded-lg bg-white object-contain p-1"
                    />
                ) : (
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-white/10">
                        <QrCode className="size-5 text-(--k-signal)" />
                    </span>
                )}
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">
                        {organization?.name ?? 'SYNAPSE'}
                    </p>
                    {station && (
                        <p className="truncate text-sm text-white/60">
                            {station.site
                                ? `${station.name}, ${station.site}`
                                : station.name}
                        </p>
                    )}
                </div>
            </header>

            <main className="flex flex-1 flex-col px-4 pb-8">
                <div className="mx-auto w-full max-w-md">
                    {props.state === 'ready' && props.claim ? (
                        <Flow {...props} claim={props.claim} />
                    ) : (
                        <Card>
                            <Stopped
                                message={
                                    props.message ??
                                    'Scan the code on the station’s screen again.'
                                }
                                appLink={
                                    props.state === 'app_only'
                                        ? props.app_link
                                        : undefined
                                }
                            />
                        </Card>
                    )}
                </div>
            </main>
        </div>
    );
}

function Flow({
    claim,
    expires_in = 180,
    station,
    pin_length,
    app_link,
}: QrScanPageProps & { claim: string }) {
    const [step, setStep] = useState<Step>({ kind: 'who' });
    const [claimUntil] = useState(() => Date.now() + expires_in * 1000);

    const content = (() => {
        switch (step.kind) {
            case 'who':
                return (
                    <WhoStep
                        claim={claim}
                        until={claimUntil}
                        pinLength={pin_length}
                        stationName={station?.name ?? 'the station'}
                        appLink={app_link}
                        onIdentified={(result) => {
                            const next = {
                                ticket: result.ticket,
                                firstName: result.first_name,
                                day: result.day,
                                until: Date.now() + result.expires_in * 1000,
                            };
                            setStep(
                                result.must_change_pin
                                    ? { kind: 'choose_pin', ...next }
                                    : { kind: 'punch', ...next },
                            );
                        }}
                        onStopped={(message, reason) =>
                            setStep({ kind: 'stopped', message, reason })
                        }
                    />
                );
            case 'choose_pin':
                return (
                    <ChoosePinStep
                        ticket={step.ticket}
                        firstName={step.firstName}
                        pinLength={pin_length}
                        onChosen={() => setStep({ ...step, kind: 'punch' })}
                        onStopped={(message, reason) =>
                            setStep({ kind: 'stopped', message, reason })
                        }
                    />
                );
            case 'punch':
                return (
                    <PunchStep
                        ticket={step.ticket}
                        firstName={step.firstName}
                        day={step.day}
                        until={step.until}
                        onPunched={(punched) =>
                            setStep({ kind: 'done', punched })
                        }
                        onStopped={(message, reason) =>
                            setStep({ kind: 'stopped', message, reason })
                        }
                    />
                );
            case 'done':
                return (
                    <DoneStep
                        punched={step.punched}
                        timeZone={station?.timezone ?? 'Asia/Manila'}
                        stationName={station?.name ?? ''}
                    />
                );
            case 'stopped':
                return <Stopped message={step.message} />;
        }
    })();

    return <Card>{content}</Card>;
}

// ── Who you are ──────────────────────────────────────────────────────────────

function WhoStep({
    claim,
    until,
    pinLength,
    stationName,
    appLink,
    onIdentified,
    onStopped,
}: {
    claim: string;
    appLink?: string;
    until: number;
    pinLength: number;
    stationName: string;
    onIdentified: (result: QrIdentified) => void;
    onStopped: (message: string, reason?: string) => void;
}) {
    const [number, setNumber] = useState(() => {
        try {
            return window.localStorage.getItem(NUMBER_KEY) ?? '';
        } catch {
            return '';
        }
    });
    const [pin, setPin] = useState('');
    const [phase, setPhase] = useState<'idle' | 'locating' | 'checking'>(
        'idle',
    );
    const [error, setError] = useState<{
        message: string;
        location?: boolean;
    } | null>(null);
    const left = useCountdown(until);
    const pinRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (left === 0) {
            onStopped(
                'That took too long. Scan the code on the screen again.',
                'expired',
            );
        }
    }, [left, onStopped]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);

        if (number.trim() === '') {
            setError({ message: 'Type your employee number.' });

            return;
        }

        if (!new RegExp(`^\\d{${pinLength}}$`).test(pin)) {
            setError({ message: `Your PIN is ${pinLength} digits.` });
            pinRef.current?.focus();

            return;
        }

        setPhase('locating');
        const where = await locate();

        if (!where.ok) {
            setPhase('idle');
            setError({
                location: true,
                message:
                    where.reason === 'denied'
                        ? 'Allow this page to use your location: tap the icon beside the address bar, choose Allow, then try again.'
                        : 'Your phone couldn’t find where you are. Turn on location (and precise location), then try again.',
            });

            return;
        }

        setPhase('checking');
        const outcome = await qrScanApi.identify({
            claim,
            number: number.trim(),
            pin,
            ...where.fix,
        });
        setPhase('idle');

        if (outcome.ok) {
            try {
                window.localStorage.setItem(NUMBER_KEY, number.trim());
            } catch {
                // Private browsing: nothing is remembered, which is fine.
            }

            onIdentified(outcome.data);

            return;
        }

        const reason = outcome.error.reason;

        if (
            reason === 'expired' ||
            reason === 'app_only' ||
            reason === 'inactive' ||
            reason === 'no_site'
        ) {
            onStopped(outcome.error.message, reason);

            return;
        }

        setPin('');
        setError({
            message: outcome.error.message,
            location:
                reason === 'no_location' ||
                reason === 'imprecise' ||
                reason === 'away',
        });
        (reason === 'wrong_pin' ? pinRef.current : null)?.focus();
    };

    const busy = phase !== 'idle';

    return (
        <form onSubmit={submit} noValidate className="space-y-5">
            <div className="space-y-1">
                <h1 className="text-[1.6rem] leading-tight font-semibold">
                    Clock in at {stationName}
                </h1>
                <p className="text-[0.95rem] text-(--k-muted)">
                    Type your employee number and the PIN you use at kiosks.
                </p>
            </div>

            <Field
                label="Employee number"
                hint="The digits are enough: 42 for EMP-00042."
            >
                {(id) => (
                    <input
                        id={id}
                        value={number}
                        onChange={(event) => setNumber(event.target.value)}
                        inputMode="numeric"
                        autoComplete="off"
                        autoCapitalize="characters"
                        maxLength={40}
                        disabled={busy}
                        className={INPUT}
                    />
                )}
            </Field>

            <Field label="PIN">
                {(id) => (
                    <input
                        id={id}
                        ref={pinRef}
                        value={pin}
                        onChange={(event) =>
                            setPin(
                                event.target.value
                                    .replace(/\D/g, '')
                                    .slice(0, pinLength),
                            )
                        }
                        type="password"
                        inputMode="numeric"
                        autoComplete="off"
                        maxLength={pinLength}
                        disabled={busy}
                        className={cn(INPUT, 'tracking-[0.5em]')}
                    />
                )}
            </Field>

            {error && (
                <Notice
                    tone="warn"
                    icon={error.location ? LocateFixed : AlertTriangle}
                >
                    {error.message}
                </Notice>
            )}

            <button type="submit" disabled={busy} className={PRIMARY}>
                {phase === 'locating'
                    ? 'Finding where you are…'
                    : phase === 'checking'
                      ? 'Checking…'
                      : 'Continue'}
            </button>

            <p className="flex items-center gap-2 text-[0.8125rem] text-(--k-muted)">
                <LocateFixed className="size-4 shrink-0" />
                Your phone will ask to share its location. It’s checked once, to
                make sure you’re at the station.
            </p>

            {left <= 60 && (
                <p className="flex items-center gap-2 text-[0.8125rem] font-medium text-(--k-break)">
                    <Clock3 className="size-4 shrink-0" />
                    {left} seconds left before you’ll need to scan again.
                </p>
            )}

            <p className="border-t border-dashed border-(--k-rule) pt-4 text-[0.8125rem] text-(--k-muted)">
                No PIN yet? Ask HR to issue you one.
                {appLink && (
                    <>
                        {' '}
                        Have the SYNAPSE app?{' '}
                        <a
                            href={appLink}
                            className="font-semibold text-(--k-in) underline underline-offset-4"
                        >
                            Open this in the app
                        </a>
                        .
                    </>
                )}
            </p>
        </form>
    );
}

// ── A PIN of one's own ───────────────────────────────────────────────────────

function ChoosePinStep({
    ticket,
    firstName,
    pinLength,
    onChosen,
    onStopped,
}: {
    ticket: string;
    firstName: string;
    pinLength: number;
    onChosen: () => void;
    onStopped: (message: string, reason?: string) => void;
}) {
    const [pin, setPin] = useState('');
    const [again, setAgain] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);

        if (pin.length !== pinLength) {
            setError(`A PIN is ${pinLength} digits.`);

            return;
        }

        if (pin !== again) {
            setError('The two PINs don’t match. Type it again.');
            setAgain('');

            return;
        }

        setBusy(true);
        const outcome = await qrScanApi.choosePin(ticket, pin);
        setBusy(false);

        if (outcome.ok) {
            onChosen();

            return;
        }

        if (outcome.error.reason === 'expired') {
            onStopped(outcome.error.message, 'expired');

            return;
        }

        setError(outcome.error.message);
    };

    const digits = (value: string) =>
        value.replace(/\D/g, '').slice(0, pinLength);

    return (
        <form onSubmit={submit} noValidate className="space-y-5">
            <div className="space-y-1">
                <h1 className="text-[1.6rem] leading-tight font-semibold">
                    Hi {firstName}, choose your own PIN
                </h1>
                <p className="text-[0.95rem] text-(--k-muted)">
                    The one HR gave you works once. Pick {pinLength} digits only
                    you know; you’ll use them here and at kiosks.
                </p>
            </div>
            <Field label="New PIN">
                {(id) => (
                    <input
                        id={id}
                        value={pin}
                        onChange={(event) => setPin(digits(event.target.value))}
                        type="password"
                        inputMode="numeric"
                        autoComplete="new-password"
                        className={cn(INPUT, 'tracking-[0.5em]')}
                    />
                )}
            </Field>
            <Field label="The same PIN again">
                {(id) => (
                    <input
                        id={id}
                        value={again}
                        onChange={(event) =>
                            setAgain(digits(event.target.value))
                        }
                        type="password"
                        inputMode="numeric"
                        autoComplete="new-password"
                        className={cn(INPUT, 'tracking-[0.5em]')}
                    />
                )}
            </Field>
            {error && (
                <Notice tone="warn" icon={AlertTriangle}>
                    {error}
                </Notice>
            )}
            <button type="submit" disabled={busy} className={PRIMARY}>
                {busy ? 'Saving…' : 'Save my PIN'}
            </button>
        </form>
    );
}

// ── The punch ────────────────────────────────────────────────────────────────

function PunchStep({
    ticket,
    firstName,
    day,
    until,
    onPunched,
    onStopped,
}: {
    ticket: string;
    firstName: string;
    day: QrDay;
    until: number;
    onPunched: (punched: QrPunched) => void;
    onStopped: (message: string, reason?: string) => void;
}) {
    const [photo, setPhoto] = useState<{ blob: Blob; url: string } | null>(
        null,
    );
    const [busy, setBusy] = useState<QrPunchType | null>(null);
    const [error, setError] = useState<string | null>(null);
    const left = useCountdown(until);
    const fileRef = useRef<HTMLInputElement>(null);
    const choices = ORDER.filter((type) => day.allowed.includes(type));

    useEffect(() => {
        if (left === 0) {
            onStopped(
                'That took too long. Scan the code on the screen again.',
                'expired',
            );
        }
    }, [left, onStopped]);

    useEffect(
        () => () => {
            if (photo) {
                URL.revokeObjectURL(photo.url);
            }
        },
        [photo],
    );

    const punch = async (type: QrPunchType) => {
        if (day.selfie_required && !photo) {
            setError(
                'Your company asks for a photo with every punch. Take one first.',
            );
            fileRef.current?.click();

            return;
        }

        setError(null);
        setBusy(type);
        const outcome = await qrScanApi.punch(
            ticket,
            type,
            photo?.blob ?? null,
        );
        setBusy(null);

        if (outcome.ok) {
            onPunched(outcome.data);

            return;
        }

        if (
            outcome.error.reason === 'expired' ||
            outcome.error.reason === 'inactive'
        ) {
            onStopped(outcome.error.message, outcome.error.reason);

            return;
        }

        setError(outcome.error.message);
    };

    return (
        <div className="space-y-5">
            <div className="space-y-1">
                <h1 className="text-[1.6rem] leading-tight font-semibold">
                    Hi {firstName}
                </h1>
                <p className="text-[0.95rem] text-(--k-muted)">
                    {choices.length === 0
                        ? 'There’s nothing left to punch today.'
                        : 'You’re at the station. What are you punching?'}
                </p>
            </div>

            {day.selfie_required && (
                <div className="flex items-center gap-4 rounded-2xl border border-(--k-rule) p-3">
                    {photo ? (
                        <img
                            src={photo.url}
                            alt="Your photo for this punch"
                            className="size-16 shrink-0 rounded-xl object-cover"
                        />
                    ) : (
                        <span className="flex size-16 shrink-0 items-center justify-center rounded-xl bg-(--k-rule)/60">
                            <Camera className="size-6 text-(--k-muted)" />
                        </span>
                    )}
                    <div className="min-w-0 flex-1">
                        <p className="font-medium">
                            {photo
                                ? 'Photo taken'
                                : 'A photo goes with your punch'}
                        </p>
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
                            className="mt-1 text-sm font-semibold text-(--k-in) underline underline-offset-4"
                        >
                            {photo ? 'Take it again' : 'Take a photo'}
                        </button>
                    </div>
                    <input
                        ref={fileRef}
                        type="file"
                        accept="image/*"
                        capture="user"
                        className="sr-only"
                        onChange={async (event) => {
                            const file = event.target.files?.[0];
                            event.target.value = '';

                            if (file) {
                                const blob = await shrink(file);
                                setPhoto({
                                    blob,
                                    url: URL.createObjectURL(blob),
                                });
                                setError(null);
                            }
                        }}
                    />
                </div>
            )}

            {error && (
                <Notice tone="warn" icon={AlertTriangle}>
                    {error}
                </Notice>
            )}

            <div className="grid gap-2.5">
                {choices.map((type) => {
                    const next = type === day.next_expected;

                    return (
                        <button
                            key={type}
                            type="button"
                            disabled={busy !== null}
                            onClick={() => void punch(type)}
                            className={cn(
                                'flex h-14 items-center justify-center rounded-2xl text-lg font-semibold transition-[transform,opacity] active:scale-[0.98] disabled:opacity-60',
                                next
                                    ? 'text-white'
                                    : 'border border-(--k-rule) bg-transparent text-(--k-ink)',
                            )}
                            style={
                                next
                                    ? { background: PUNCHES[type].ink }
                                    : undefined
                            }
                        >
                            {busy === type ? 'Punching…' : PUNCHES[type].action}
                        </button>
                    );
                })}
            </div>

            {choices.length > 0 && (
                <p className="flex items-center gap-2 text-[0.8125rem] text-(--k-muted)">
                    <Clock3 className="size-4 shrink-0" />
                    Good for {formatLeft(left)} more.
                </p>
            )}
        </div>
    );
}

// ── Stamped ──────────────────────────────────────────────────────────────────

function DoneStep({
    punched,
    timeZone,
    stationName,
}: {
    punched: QrPunched;
    timeZone: string;
    stationName: string;
}) {
    const words = PUNCHES[punched.type];

    return (
        <div className="space-y-6 text-center" role="status" aria-live="polite">
            <div
                className="mx-auto flex w-fit -rotate-3 animate-[qr-stamp_420ms_cubic-bezier(.2,1.4,.4,1)] flex-col items-center rounded-xl border-[3px] px-7 py-4 motion-reduce:animate-none"
                style={{ borderColor: words.ink, color: words.ink }}
            >
                <style>{`@keyframes qr-stamp{0%{opacity:0;transform:scale(1.6) rotate(-3deg)}100%{opacity:1;transform:scale(1) rotate(-3deg)}}`}</style>
                <span className="text-sm font-bold tracking-[0.2em]">
                    {words.done.toUpperCase()}
                </span>
                <span className="text-[2.6rem] leading-none font-semibold tabular-nums">
                    {clockTime(punched.at, timeZone)}
                </span>
            </div>
            <div className="space-y-1">
                <p className="text-xl font-semibold">
                    {words.done}, {punched.first_name}.
                </p>
                <p className="text-(--k-muted)">
                    Recorded at {stationName}. You can close this page.
                </p>
            </div>
        </div>
    );
}

function Stopped({ message, appLink }: { message: string; appLink?: string }) {
    return (
        <div className="space-y-5 text-center">
            <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-(--k-rule)/60">
                {appLink ? (
                    <Smartphone className="size-7 text-(--k-ink)" />
                ) : (
                    <QrCode className="size-7 text-(--k-ink)" />
                )}
            </span>
            <p className="text-lg leading-snug font-medium">{message}</p>
            {appLink ? (
                <a href={appLink} className={PRIMARY}>
                    Open in the SYNAPSE app
                </a>
            ) : (
                <p className="text-sm text-(--k-muted)">
                    Point your camera at the code on the screen to start again.
                </p>
            )}
        </div>
    );
}

// ── Pieces ───────────────────────────────────────────────────────────────────

const INPUT =
    'block h-14 w-full rounded-2xl border border-(--k-rule) bg-white px-4 text-lg font-semibold text-(--k-ink) outline-none transition-shadow focus:border-(--k-in) focus:ring-4 focus:ring-(--k-signal)/25 disabled:opacity-60';

const PRIMARY =
    'flex h-14 w-full items-center justify-center rounded-2xl bg-(--k-in) text-lg font-semibold text-white transition-[transform,opacity] active:scale-[0.98] disabled:opacity-60';

function Card({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-[1.75rem] bg-(--k-paper) px-5 py-6 text-(--k-ink) shadow-[0_30px_80px_-30px_rgba(0,0,0,0.6)] sm:px-7">
            {children}
        </div>
    );
}

function Field({
    label,
    hint,
    children,
}: {
    label: string;
    hint?: string;
    children: (id: string) => ReactNode;
}) {
    const id = `qr-${label.toLowerCase().replace(/\W+/g, '-')}`;

    return (
        <div className="space-y-1.5">
            <label htmlFor={id} className="block text-sm font-semibold">
                {label}
            </label>
            {children(id)}
            {hint && (
                <p className="text-[0.8125rem] text-(--k-muted)">{hint}</p>
            )}
        </div>
    );
}

function Notice({
    tone,
    icon: Icon,
    children,
}: {
    tone: 'warn';
    icon: LucideIcon;
    children: ReactNode;
}) {
    return (
        <p
            role="alert"
            className={cn(
                'flex items-start gap-2.5 rounded-2xl px-4 py-3 text-[0.9375rem] leading-snug',
                tone === 'warn' && 'bg-[#FDECEE] text-[#8A1C2E]',
            )}
        >
            <Icon className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </p>
    );
}

/** Seconds left until a moment, ticking each second. */
function useCountdown(until: number): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    return Math.max(0, Math.ceil((until - now) / 1000));
}

function formatLeft(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return minutes > 0
        ? `${minutes}:${String(seconds % 60).padStart(2, '0')}`
        : `${seconds} seconds`;
}

/**
 * A phone photo made small enough to send quickly — a face, not a portrait:
 * at most 960 px across, as a JPEG. Falls back to the original when the browser
 * can't draw it.
 */
async function shrink(file: File): Promise<Blob> {
    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, 960 / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas
            .getContext('2d')
            ?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', 0.82),
        );

        return blob ?? file;
    } catch {
        return file;
    }
}
