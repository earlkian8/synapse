import { Maximize, Minimize, Power, WifiOff, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { CSSProperties } from 'react';
import { KIOSK_TOKENS } from '@/features/kiosk/constants';
import {
    clockTime,
    useKioskClock,
    zoned,
} from '@/features/kiosk/hooks/use-kiosk-clock';
import { useWakeLock } from '@/features/kiosk/hooks/use-wake-lock';
import { useQrCode } from '../hooks/use-qr-code';
import type { QrScreenState } from '../hooks/use-qr-code';
import type { QrDisplayProps, QrPunchType } from '../types';
import { QrMatrix } from './qr-matrix';

/** A time card's inks, as the kiosk stamps them (ADR 0092). */
const PUNCH_INK: Record<QrPunchType, { word: string; ink: string }> = {
    clock_in: { word: 'In', ink: 'var(--k-signal)' },
    break_start: { word: 'Break', ink: '#F5A524' },
    break_end: { word: 'Back', ink: '#F5A524' },
    clock_out: { word: 'Out', ink: '#FF8095' },
};

/**
 * A QR station's screen (ADR 0103): the code people scan with the SYNAPSE app
 * (or, at a station that takes any phone, with their phone's own camera),
 * on a paper plate big enough to read across a room, a bar along its foot that
 * runs out as the code does, the time, how to scan, and the last few people it
 * clocked in — so the line can see their punch land, and whoever minds the
 * screen can see a name appear for somebody who isn't standing there.
 *
 * It keeps the kiosk's own look (navy ground, paper card) whatever the
 * browser's theme: it is a device on a wall, not a page of the app.
 */
export function QrDisplayScreen({
    station,
    organization,
    back_url,
}: QrDisplayProps) {
    const { screen, feed, offset } = useQrCode(station.code_url);
    const now = useKioskClock(offset);
    const t = zoned(now, station.timezone);

    useWakeLock(true);

    return (
        <div
            style={KIOSK_TOKENS}
            className="flex min-h-dvh flex-col bg-(--k-ground) text-white antialiased"
        >
            <header className="flex items-center gap-3 px-5 pt-5 sm:px-10 sm:pt-7">
                {organization.logo_url ? (
                    <img
                        src={organization.logo_url}
                        alt=""
                        className="size-10 shrink-0 rounded-lg bg-white object-contain p-1"
                    />
                ) : null}
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">
                        {organization.name}
                    </p>
                    <p className="truncate text-sm text-white/60">
                        {station.site
                            ? `${station.name}, ${station.site}`
                            : station.name}
                    </p>
                </div>
                <div className="ml-auto flex items-center gap-1">
                    <FullscreenButton />
                    <a
                        href={back_url}
                        className="inline-flex size-11 items-center justify-center rounded-xl text-white/60 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
                        aria-label="Stop showing the code"
                        title="Stop showing the code"
                    >
                        <X className="size-5" />
                    </a>
                </div>
            </header>

            <main className="grid flex-1 content-center items-center gap-10 px-5 py-8 sm:px-10 md:landscape:grid-cols-[auto_minmax(18rem,26rem)] md:landscape:justify-center md:landscape:gap-16">
                <Plate
                    screen={screen}
                    offset={offset}
                    stationName={station.name}
                />

                <aside className="mx-auto w-full max-w-[26rem] space-y-9 md:landscape:mx-0">
                    <div>
                        <p className="sr-only">
                            {t.hour}:{t.minute} {t.period}, {t.weekday} {t.date}
                        </p>
                        <p
                            aria-hidden
                            className="text-[clamp(3.25rem,7vw,5.5rem)] leading-none font-medium tracking-[-0.04em] tabular-nums"
                        >
                            {t.hour}
                            <span className="text-(--k-signal)">:</span>
                            {t.minute}
                            <span className="ml-2 text-[0.32em] font-semibold tracking-normal">
                                {t.period}
                            </span>
                        </p>
                        <p aria-hidden className="mt-3 text-lg text-white/65">
                            {t.weekday}, {t.date}
                        </p>
                    </div>

                    <ol className="space-y-3.5">
                        {(station.scan_mode === 'any'
                            ? [
                                  'Open your phone’s camera and point it at this code.',
                                  'Tap the link that appears.',
                                  'Type your employee number and PIN.',
                              ]
                            : [
                                  'Open SYNAPSE on your phone.',
                                  'Go to Clock and tap the scan button.',
                                  'Point your camera at this code.',
                              ]
                        ).map((step, index) => (
                            <li
                                key={step}
                                className="flex items-baseline gap-3.5 text-[1.05rem]"
                            >
                                <span className="inline-flex size-7 shrink-0 translate-y-[-1px] items-center justify-center rounded-full border border-white/25 text-sm font-semibold text-white/80 tabular-nums">
                                    {index + 1}
                                </span>
                                <span className="text-white/85">{step}</span>
                            </li>
                        ))}
                    </ol>

                    <Feed feed={feed} timeZone={station.timezone} />
                </aside>
            </main>
        </div>
    );
}

/** The paper plate the code is printed on, or what stands in for it. */
function Plate({
    screen,
    offset,
    stationName,
}: {
    screen: QrScreenState;
    offset: number;
    stationName: string;
}) {
    const side = 'w-[min(78vw,62vh)] md:landscape:w-[min(46vw,68vh)]';

    return (
        <div className={`mx-auto ${side}`}>
            <div className="relative overflow-hidden rounded-[1.75rem] bg-(--k-paper) text-(--k-ink) shadow-[0_30px_80px_-30px_rgba(0,0,0,0.6)]">
                <div className="aspect-square p-[7%]">
                    {screen.kind === 'live' ? (
                        <QrMatrix
                            key={screen.key}
                            matrix={screen.matrix}
                            label={`The ${stationName} attendance code. It changes every ${screen.rotateSeconds} seconds.`}
                            className="size-full animate-[qr-in_220ms_ease-out]"
                        />
                    ) : (
                        <Standby screen={screen} />
                    )}
                </div>

                <div className="flex items-center justify-between gap-4 border-t border-dashed border-(--k-rule) px-[7%] py-4">
                    <p className="text-[clamp(0.95rem,1.6vw,1.2rem)] font-semibold">
                        Scan to clock in
                    </p>
                    {screen.kind === 'live' && (
                        <p className="text-sm text-(--k-muted)">
                            New code every {screen.rotateSeconds} s
                        </p>
                    )}
                </div>

                {screen.kind === 'live' && (
                    <Drain
                        key={screen.key}
                        expiresAt={screen.expiresAt}
                        rotateSeconds={screen.rotateSeconds}
                        offset={offset}
                    />
                )}
            </div>
        </div>
    );
}

/**
 * The bar along the plate's foot, running out with the code. Purely a cue: the
 * screen asks for the next code on its own time.
 */
function Drain({
    expiresAt,
    rotateSeconds,
    offset,
}: {
    expiresAt: number;
    rotateSeconds: number;
    offset: number;
}) {
    // Measured once, when the code arrives; the animation does the rest.
    const [start] = useState(() => {
        const left = Math.max(0, expiresAt - (Date.now() + offset));

        return { left, from: Math.min(1, left / (rotateSeconds * 1000)) };
    });

    return (
        <>
            <style>{`@keyframes qr-drain{from{transform:scaleX(var(--from))}to{transform:scaleX(0)}}@keyframes qr-in{from{opacity:.25}to{opacity:1}}`}</style>
            <span
                aria-hidden
                className="absolute inset-x-0 bottom-0 h-1.5 origin-left bg-(--k-in) motion-reduce:hidden"
                style={
                    {
                        '--from': start.from,
                        transform: `scaleX(${start.from})`,
                        animation: `qr-drain ${start.left}ms linear forwards`,
                    } as CSSProperties
                }
            />
        </>
    );
}

/** What the plate says when there is no code to scan. */
function Standby({
    screen,
}: {
    screen: Exclude<QrScreenState, { kind: 'live' }>;
}) {
    const content = {
        loading: { icon: null, title: 'Getting the code…', body: null },
        offline: {
            icon: WifiOff,
            title: 'Reconnecting',
            body: 'This screen can’t reach SYNAPSE. The code comes back on its own once it does.',
        },
        inactive: {
            icon: Power,
            title: 'This station is switched off',
            body: 'Nobody can clock in with it until HR switches it on.',
        },
        no_site: {
            icon: Power,
            title: 'This station has no site',
            body: 'HR sets where it stands under Attendance → QR stations.',
        },
        signed_out: {
            icon: Power,
            title: 'Signed out',
            body: 'Sign in again on this screen to show the code.',
        },
    }[screen.kind];

    const Icon = content.icon;

    return (
        <div className="flex size-full flex-col items-center justify-center gap-4 rounded-2xl bg-[repeating-linear-gradient(45deg,transparent_0_14px,rgba(15,32,68,0.04)_14px_28px)] p-6 text-center">
            {Icon ? (
                <Icon className="size-10 text-(--k-muted)" />
            ) : (
                <span className="size-10 animate-spin rounded-full border-4 border-(--k-rule) border-t-(--k-in) motion-reduce:animate-none" />
            )}
            <p className="text-xl font-semibold">{content.title}</p>
            {content.body && (
                <p className="max-w-xs text-(--k-muted)">{content.body}</p>
            )}
            {screen.kind === 'signed_out' && (
                <a
                    href="/login"
                    className="rounded-xl bg-(--k-ink) px-4 py-2.5 font-medium text-white"
                >
                    Sign in
                </a>
            )}
        </div>
    );
}

/** The last people the station clocked in, a time card's lines. */
function Feed({
    feed,
    timeZone,
}: {
    feed: {
        id: number;
        name: string;
        type: QrPunchType | null;
        at: string | null;
    }[];
    timeZone: string;
}) {
    return (
        <section aria-label="Just scanned" aria-live="polite">
            <h2 className="mb-2 text-sm font-medium text-white/55">
                Just scanned
            </h2>
            {feed.length === 0 ? (
                <p className="border-t border-white/10 pt-3 text-white/45">
                    Punches made here show up as they land.
                </p>
            ) : (
                <ul className="divide-y divide-white/10 border-t border-white/10">
                    {feed.map((entry) => {
                        const ink = entry.type ? PUNCH_INK[entry.type] : null;

                        return (
                            <li
                                key={entry.id}
                                className="flex items-center gap-3 py-2.5"
                            >
                                <span className="w-[4.5rem] shrink-0 text-white/60 tabular-nums">
                                    {entry.at
                                        ? clockTime(entry.at, timeZone)
                                        : ''}
                                </span>
                                <span
                                    className="w-12 shrink-0 text-sm font-semibold"
                                    style={{ color: ink?.ink }}
                                >
                                    {ink?.word}
                                </span>
                                <span className="truncate font-medium">
                                    {entry.name}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}

function FullscreenButton() {
    const [full, setFull] = useState(false);
    const can =
        typeof document !== 'undefined' && document.fullscreenEnabled === true;

    useEffect(() => {
        const sync = () => setFull(document.fullscreenElement !== null);
        document.addEventListener('fullscreenchange', sync);

        return () => document.removeEventListener('fullscreenchange', sync);
    }, []);

    if (!can) {
        return null;
    }

    return (
        <button
            type="button"
            onClick={() =>
                full
                    ? void document.exitFullscreen().catch(() => undefined)
                    : void document.documentElement
                          .requestFullscreen()
                          .catch(() => undefined)
            }
            className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl text-white/60 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-4 focus-visible:ring-(--k-signal)/50 focus-visible:outline-none"
            aria-label={full ? 'Leave full screen' : 'Full screen'}
        >
            {full ? (
                <Minimize className="size-5" />
            ) : (
                <Maximize className="size-5" />
            )}
        </button>
    );
}
