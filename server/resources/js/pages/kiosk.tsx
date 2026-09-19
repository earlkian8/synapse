import { Head } from '@inertiajs/react';
import {
    CameraOff,
    Coffee,
    Delete,
    LogIn,
    LogOut,
    MapPin,
    Play,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    clearKeyFromAddress,
    forgetKey,
    kioskApi,
    readKey,
} from '@/features/kiosk/api';
import type {
    KioskDevice,
    KioskPerson,
    KioskPunchType,
} from '@/features/kiosk/api';
import { cn } from '@/lib/utils';

/** Back to the keypad after this long with nobody touching the kiosk. */
const IDLE_MS = 30_000;

/** How long the stamped time stays up after a punch. */
const DONE_MS = 5_000;

const PUNCHES: Record<
    KioskPunchType,
    { label: string; done: string; icon: LucideIcon }
> = {
    clock_in: { label: 'Clock in', done: 'Clocked in', icon: LogIn },
    break_start: { label: 'Start break', done: 'Break started', icon: Coffee },
    break_end: { label: 'End break', done: 'Back from break', icon: Play },
    clock_out: { label: 'Clock out', done: 'Clocked out', icon: LogOut },
};

type Step =
    | { name: 'enter'; error: string | null }
    | {
          name: 'choose';
          reference: string;
          person: KioskPerson;
          error: string | null;
      }
    | {
          name: 'done';
          person: KioskPerson;
          type: KioskPunchType;
          at: string;
          photo: boolean;
      };

/**
 * The web kiosk (ADR 0040): a tablet at the door that anybody can punch on.
 * Nobody signs in — the tablet holds its device's key, set once from the link
 * Company Setup → Devices gives. Somebody types their employee number, sees
 * their name and the punches their day allows, and the camera takes their photo
 * with the punch. The punch engine judges it exactly as it judges a punch from
 * the phone.
 */
export default function Kiosk() {
    const [key, setKey] = useState<string | null>(() => readKey());
    const [device, setDevice] = useState<KioskDevice | null>(null);
    const [deviceError, setDeviceError] = useState<string | null>(null);
    const [now, setNow] = useState(() => new Date());
    const [step, setStep] = useState<Step>({ name: 'enter', error: null });
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);

    // The setup link's key is stored now; take it out of the address bar.
    useEffect(() => clearKeyFromAddress(), []);

    // Who the kiosk is, once per key.
    useEffect(() => {
        if (!key) {
            return;
        }

        let cancelled = false;

        void kioskApi.me(key).then((outcome) => {
            if (cancelled) {
                return;
            }

            if (outcome.ok) {
                setDevice(outcome.data);
                setDeviceError(null);
            } else {
                setDeviceError(
                    outcome.status === 401 || outcome.status === 403
                        ? 'key'
                        : outcome.message,
                );
            }
        });

        return () => {
            cancelled = true;
        };
    }, [key]);

    // The clock face.
    useEffect(() => {
        const id = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(id);
    }, []);

    // Nobody left standing at the choice for long; the stamp goes away by itself.
    useEffect(() => {
        if (step.name === 'enter') {
            return;
        }

        const id = window.setTimeout(
            () => {
                setReference('');
                setStep({ name: 'enter', error: null });
            },
            step.name === 'done' ? DONE_MS : IDLE_MS,
        );

        return () => window.clearTimeout(id);
    }, [step]);

    const timeZone = device?.timezone;

    if (!key) {
        return (
            <Setup
                title="This tablet isn’t a kiosk yet"
                body="In Company Setup → Devices, register a kiosk and open the link it gives you on this tablet."
            />
        );
    }

    if (deviceError === 'key') {
        return (
            <Setup
                title="This kiosk’s key no longer works"
                body="It was replaced, or the kiosk was removed or deactivated. Open the new link from Company Setup → Devices."
                action={{
                    label: 'Forget this key',
                    run: () => {
                        forgetKey();
                        setKey(null);
                        setDeviceError(null);
                    },
                }}
            />
        );
    }

    const lookup = async (event: FormEvent) => {
        event.preventDefault();
        const typed = reference.trim();

        if (typed === '' || busy) {
            return;
        }

        setBusy(true);
        const outcome = await kioskApi.lookup(key, typed);
        setBusy(false);

        setStep(
            outcome.ok
                ? {
                      name: 'choose',
                      reference: typed,
                      person: outcome.data,
                      error: null,
                  }
                : { name: 'enter', error: outcome.message },
        );
    };

    return (
        <>
            <Head title="Kiosk" />

            <div className="flex min-h-dvh flex-col bg-[#0F2044] text-white selection:bg-[#0ABFBF]/40 lg:flex-row">
                {/* The clock face: the one thing everybody glances at. */}
                <section className="flex flex-col justify-between gap-8 px-6 pt-8 pb-4 sm:px-10 lg:w-1/2 lg:py-12">
                    <div className="text-sm text-[#9FB0CC]">
                        <p className="font-medium text-white">
                            {device?.organization ?? '\u00a0'}
                        </p>
                        {device?.location && (
                            <p className="mt-0.5 inline-flex items-center gap-1.5">
                                <MapPin className="size-3.5" />
                                {device.location}
                            </p>
                        )}
                    </div>

                    <div>
                        <Clock now={now} timeZone={timeZone} />
                        <p className="mt-3 text-lg text-[#9FB0CC] sm:text-xl">
                            {now.toLocaleDateString(undefined, {
                                weekday: 'long',
                                month: 'long',
                                day: 'numeric',
                                timeZone,
                            })}
                        </p>
                    </div>

                    <p className="hidden text-xs text-[#6F82A6] lg:block">
                        {device?.name}
                        {deviceError && ` · ${deviceError}`}
                    </p>
                </section>

                {/* The counter. */}
                <section className="flex flex-1 items-start justify-center px-4 pb-8 sm:px-10 lg:items-center lg:py-12">
                    <div className="w-full max-w-md rounded-3xl bg-white/[0.06] p-5 ring-1 ring-white/10 sm:p-7">
                        {step.name === 'enter' && (
                            <EnterNumber
                                value={reference}
                                onChange={setReference}
                                onSubmit={lookup}
                                busy={busy}
                                error={step.error}
                            />
                        )}

                        {step.name === 'choose' && (
                            <Choose
                                person={step.person}
                                error={step.error}
                                now={now}
                                timeZone={timeZone}
                                busy={busy}
                                onCancel={() => {
                                    setReference('');
                                    setStep({ name: 'enter', error: null });
                                }}
                                onPunch={async (type, photo) => {
                                    setBusy(true);
                                    const outcome = await kioskApi.punch(
                                        key,
                                        step.reference,
                                        type,
                                        photo,
                                    );
                                    setBusy(false);

                                    if (outcome.ok) {
                                        setReference('');
                                        setStep({
                                            name: 'done',
                                            person: step.person,
                                            type,
                                            at: outcome.data.at,
                                            photo: photo !== null,
                                        });
                                    } else {
                                        setStep({
                                            ...step,
                                            error: outcome.message,
                                        });
                                    }
                                }}
                            />
                        )}

                        {step.name === 'done' && (
                            <Stamped
                                step={step}
                                timeZone={timeZone}
                                onDone={() =>
                                    setStep({ name: 'enter', error: null })
                                }
                            />
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

// ── Steps ────────────────────────────────────────────────────────────────────

function EnterNumber({
    value,
    onChange,
    onSubmit,
    busy,
    error,
}: {
    value: string;
    onChange: (value: string) => void;
    onSubmit: (event: FormEvent) => void;
    busy: boolean;
    error: string | null;
}) {
    const press = (digit: string) => onChange(`${value}${digit}`.slice(0, 32));

    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
            <label htmlFor="kiosk-number" className="text-lg font-medium">
                Your employee number
            </label>
            <input
                id="kiosk-number"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                autoFocus
                autoComplete="off"
                inputMode="numeric"
                aria-describedby={error ? 'kiosk-error' : undefined}
                className="h-16 rounded-2xl bg-white/10 px-5 text-center text-3xl font-semibold tracking-widest tabular-nums ring-1 ring-white/15 outline-none placeholder:text-white/25 focus:ring-2 focus:ring-[#0ABFBF]"
                placeholder="00042"
            />
            {error && (
                <p
                    id="kiosk-error"
                    role="alert"
                    className="text-center text-sm text-[#FFB4A8]"
                >
                    {error}
                </p>
            )}

            <div className="grid grid-cols-3 gap-2.5" aria-label="Keypad">
                {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((digit) => (
                    <Key key={digit} onPress={() => press(digit)}>
                        {digit}
                    </Key>
                ))}
                <Key onPress={() => onChange('')} muted>
                    Clear
                </Key>
                <Key onPress={() => press('0')}>0</Key>
                <Key
                    onPress={() => onChange(value.slice(0, -1))}
                    muted
                    label="Delete the last digit"
                >
                    <Delete className="size-6" />
                </Key>
            </div>

            <button
                type="submit"
                disabled={busy || value.trim() === ''}
                className="h-16 rounded-2xl bg-[#0ABFBF] text-xl font-semibold text-[#0F2044] transition-opacity focus-visible:ring-4 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none disabled:opacity-40"
            >
                {busy ? 'Finding you…' : 'Continue'}
            </button>
        </form>
    );
}

function Choose({
    person,
    error,
    now,
    timeZone,
    busy,
    onCancel,
    onPunch,
}: {
    person: KioskPerson;
    error: string | null;
    now: Date;
    timeZone?: string;
    busy: boolean;
    onCancel: () => void;
    onPunch: (type: KioskPunchType, photo: Blob | null) => void;
}) {
    const video = useRef<HTMLVideoElement | null>(null);
    const [camera, setCamera] = useState<'starting' | 'on' | 'off'>('starting');

    // The camera runs only while somebody is choosing.
    useEffect(() => {
        let stream: MediaStream | null = null;
        let cancelled = false;

        if (!navigator.mediaDevices?.getUserMedia) {
            return;
        }

        navigator.mediaDevices
            .getUserMedia({
                video: { facingMode: 'user', width: 640, height: 640 },
                audio: false,
            })
            .then((opened) => {
                if (cancelled) {
                    opened.getTracks().forEach((track) => track.stop());

                    return;
                }

                stream = opened;

                if (video.current) {
                    video.current.srcObject = opened;
                }

                setCamera('on');
            })
            .catch(() => setCamera('off'));

        return () => {
            cancelled = true;
            stream?.getTracks().forEach((track) => track.stop());
        };
    }, []);

    const snap = async (): Promise<Blob | null> => {
        const element = video.current;

        if (camera !== 'on' || !element || element.readyState < 2) {
            return null;
        }

        const side = Math.min(element.videoWidth, element.videoHeight);
        const canvas = document.createElement('canvas');
        canvas.width = 480;
        canvas.height = 480;
        canvas
            .getContext('2d')
            ?.drawImage(
                element,
                (element.videoWidth - side) / 2,
                (element.videoHeight - side) / 2,
                side,
                side,
                0,
                0,
                480,
                480,
            );

        return new Promise((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', 0.85),
        );
    };

    const allowed = [
        ...(person.next_expected ? [person.next_expected] : []),
        ...person.allowed.filter((type) => type !== person.next_expected),
    ];

    return (
        <div className="flex flex-col gap-5">
            <div className="flex items-center gap-4">
                <div className="relative size-20 shrink-0 overflow-hidden rounded-2xl bg-white/10 ring-1 ring-white/15">
                    <video
                        ref={video}
                        autoPlay
                        muted
                        playsInline
                        aria-label="Camera preview"
                        className={cn(
                            'size-full -scale-x-100 object-cover',
                            camera !== 'on' && 'invisible',
                        )}
                    />
                    {camera === 'off' && (
                        <span className="absolute inset-0 flex items-center justify-center text-white/50">
                            <CameraOff className="size-6" />
                        </span>
                    )}
                </div>
                <div className="min-w-0">
                    <p className="text-sm text-[#9FB0CC]">
                        {greeting(now, timeZone)},
                    </p>
                    <p className="truncate text-2xl font-semibold">
                        {person.full_name}
                    </p>
                    {camera === 'on' && (
                        <p className="text-xs text-[#9FB0CC]">
                            Your photo is taken with the punch.
                        </p>
                    )}
                </div>
            </div>

            {error && (
                <p
                    role="alert"
                    className="rounded-xl bg-[#FFB4A8]/10 px-4 py-3 text-sm text-[#FFB4A8]"
                >
                    {error}
                </p>
            )}

            <div className="flex flex-col gap-2.5">
                {allowed.map((type, index) => {
                    const Icon = PUNCHES[type].icon;

                    return (
                        <button
                            key={type}
                            type="button"
                            disabled={busy}
                            onClick={async () => onPunch(type, await snap())}
                            className={cn(
                                'flex h-16 items-center justify-center gap-3 rounded-2xl text-xl font-semibold transition-opacity focus-visible:ring-4 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none disabled:opacity-50',
                                index === 0
                                    ? 'bg-[#0ABFBF] text-[#0F2044]'
                                    : 'bg-white/10 text-white ring-1 ring-white/15',
                            )}
                        >
                            <Icon className="size-6" />
                            {PUNCHES[type].label}
                        </button>
                    );
                })}
            </div>

            <button
                type="button"
                onClick={onCancel}
                className="self-center rounded-lg px-3 py-2 text-sm text-[#9FB0CC] hover:text-white focus-visible:ring-2 focus-visible:ring-white/40 focus-visible:outline-none"
            >
                Not you? Start again
            </button>
        </div>
    );
}

/** The punch, stamped: the time it was recorded, large, like a time card. */
function Stamped({
    step,
    timeZone,
    onDone,
}: {
    step: Extract<Step, { name: 'done' }>;
    timeZone?: string;
    onDone: () => void;
}) {
    const leaving = step.type === 'clock_out';

    return (
        <div
            className="flex flex-col items-center gap-3 py-4 text-center"
            role="status"
        >
            <p className="text-lg text-[#9FB0CC]">
                {PUNCHES[step.type].done}, {step.person.first_name}
            </p>
            <p className="text-7xl font-semibold tracking-tight text-[#0ABFBF] tabular-nums motion-safe:animate-in motion-safe:duration-300 motion-safe:zoom-in-90 motion-safe:fade-in">
                {new Date(step.at).toLocaleTimeString(undefined, {
                    hour: 'numeric',
                    minute: '2-digit',
                    timeZone,
                })}
            </p>
            <p className="text-sm text-[#9FB0CC]">
                {leaving ? 'See you next time.' : 'Have a good shift.'}
                {!step.photo && ' Recorded without a photo.'}
            </p>
            <button
                type="button"
                onClick={onDone}
                className="mt-4 h-12 rounded-2xl bg-white/10 px-8 text-base font-medium ring-1 ring-white/15 focus-visible:ring-4 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none"
            >
                Done
            </button>
        </div>
    );
}

// ── Pieces ───────────────────────────────────────────────────────────────────

function Key({
    children,
    onPress,
    muted = false,
    label,
}: {
    children: React.ReactNode;
    onPress: () => void;
    muted?: boolean;
    label?: string;
}) {
    return (
        <button
            type="button"
            onClick={onPress}
            aria-label={label}
            className={cn(
                'h-16 rounded-2xl text-2xl font-semibold tabular-nums transition-colors focus-visible:ring-4 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none active:bg-white/25',
                muted
                    ? 'flex items-center justify-center bg-transparent text-base text-[#9FB0CC] ring-1 ring-white/10 hover:bg-white/5'
                    : 'bg-white/10 hover:bg-white/15',
            )}
        >
            {children}
        </button>
    );
}

/** A tablet that is not a kiosk yet, or no longer is. */
function Setup({
    title,
    body,
    action,
}: {
    title: string;
    body: string;
    action?: { label: string; run: () => void };
}) {
    return (
        <>
            <Head title="Kiosk" />
            <div className="flex min-h-dvh items-center justify-center bg-[#0F2044] px-6 text-white">
                <div className="max-w-md text-center">
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    <p className="mt-3 text-[#9FB0CC]">{body}</p>
                    {action && (
                        <button
                            type="button"
                            onClick={action.run}
                            className="mt-6 h-12 rounded-2xl bg-white/10 px-6 font-medium ring-1 ring-white/15 focus-visible:ring-4 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none"
                        >
                            {action.label}
                        </button>
                    )}
                </div>
            </div>
        </>
    );
}

/**
 * The clock face: hours and minutes at full size, the seconds and the half of
 * the day beside them, small — the part somebody glances at is the part that
 * is large. On the organisation's clock, never the tablet's.
 */
function Clock({ now, timeZone }: { now: Date; timeZone?: string }) {
    const parts = new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        timeZone,
    }).formatToParts(now);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((entry) => entry.type === type)?.value ?? '';

    return (
        <p
            className="flex items-baseline gap-3 whitespace-nowrap tabular-nums"
            aria-label={now.toLocaleTimeString(undefined, {
                hour: 'numeric',
                minute: '2-digit',
                timeZone,
            })}
        >
            <span className="text-[clamp(4.5rem,11vw,9rem)] leading-none font-semibold tracking-tight">
                {part('hour')}:{part('minute')}
            </span>
            <span className="flex flex-col text-[clamp(1.25rem,2.4vw,2rem)] leading-tight font-medium text-[#9FB0CC]">
                <span>{part('second')}</span>
                <span>{part('dayPeriod')}</span>
            </span>
        </p>
    );
}

function greeting(now: Date, timeZone?: string): string {
    const hour = Number(
        new Intl.DateTimeFormat('en-US', {
            hour: 'numeric',
            hourCycle: 'h23',
            timeZone,
        }).format(now),
    );

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
}
