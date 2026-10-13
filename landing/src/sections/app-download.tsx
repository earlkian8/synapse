import { CalendarDays, Clock3, House, Palmtree, UserRound } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect, useState } from 'react';
import {
    DownloadApkLink,
    PhoneGlyph,
    PlayGlyph,
    SoonBadge,
} from '../components/actions';
import { absoluteApkUrl, appVersion } from '../lib/links';

const features = [
    'Clock in and out, with location and an optional selfie',
    'Punches made without signal, sent when it returns',
    'Leave balances, filing and cancelling',
    'Your week of shifts and the holidays you keep',
    'Events, kudos, awards and points',
    'Your resignation and exit interview, if it comes to that',
];

const install = [
    'Download the APK on your Android phone.',
    'Open it. If Android asks, allow installs from your browser.',
    "Sign in, or create an account and join with your company's code.",
];

export function AppDownload() {
    return (
        <section
            id="app"
            className="relative scroll-mt-16 overflow-clip py-24 lg:py-36"
        >
            <div className="mx-auto grid max-w-[84rem] gap-16 px-4 sm:px-6 lg:grid-cols-12 lg:gap-10 lg:px-10">
                <div className="lg:col-span-5">
                    {/* The phone stays in view while its features are read. */}
                    <div className="relative flex justify-center lg:sticky lg:top-28 lg:justify-start lg:pl-6">
                        {/* A halo in the mark's blues behind the phone. */}
                        <div
                            aria-hidden="true"
                            className="absolute top-1/2 left-1/2 size-[34rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(circle,rgb(55_179_255/0.28),transparent_62%)] lg:left-[42%]"
                        />
                        <Phone />
                    </div>
                </div>

                <div className="lg:col-span-6 lg:col-start-7 lg:pt-6">
                    <h2 className="text-[clamp(2.1rem,5vw,3.9rem)] leading-[0.98] font-[780] tracking-[-0.03em] text-balance font-wide">
                        Your people clock in from their phones.
                    </h2>
                    <p className="mt-6 max-w-[32rem] text-[1.05rem] leading-[1.65] text-ink-soft">
                        The SYNAPSE app is the employee's side of the same
                        system. It reads and writes the same records HR sees on
                        the web, for the company they work in, or several.
                    </p>

                    <ul className="mt-9 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                        {features.map((f) => (
                            <li
                                key={f}
                                className="flex gap-3 text-[0.94rem] leading-snug text-ink"
                            >
                                <span
                                    aria-hidden="true"
                                    className="mt-[0.55em] h-[2px] w-3 shrink-0 rounded-full bg-signal"
                                />
                                {f}
                            </li>
                        ))}
                    </ul>

                    <DownloadPanel />
                </div>
            </div>
        </section>
    );
}

function DownloadPanel() {
    return (
        <div className="mt-12 rounded-[1.75rem] bg-white p-6 shadow-[0_1px_2px_rgb(15_32_68/0.06),0_30px_60px_-36px_rgb(15_32_68/0.4)] ring-1 ring-ink/6 sm:p-8">
            <div className="flex flex-col gap-8 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <DownloadApkLink tone="ink" label="Download the APK" />
                    <p className="mt-3 pl-1 text-[0.82rem] text-ink-soft tabular">
                        Version {appVersion}, for Android phones
                    </p>

                    <ol className="mt-7 space-y-3">
                        {install.map((step, i) => (
                            <li
                                key={step}
                                className="flex gap-3 text-[0.88rem] leading-snug text-ink-soft"
                            >
                                <span className="grid size-5.5 shrink-0 place-items-center rounded-full bg-frost text-[0.72rem] font-semibold text-ink tabular">
                                    {i + 1}
                                </span>
                                {step}
                            </li>
                        ))}
                    </ol>
                </div>

                {/* On a computer, the phone is one scan away. */}
                <figure className="hidden shrink-0 flex-col items-center gap-3 sm:flex">
                    <div className="rounded-2xl bg-ice p-3.5 ring-1 ring-ink/6">
                        <QRCodeSVG
                            value={absoluteApkUrl()}
                            size={124}
                            level="M"
                            fgColor="#0F2044"
                            bgColor="transparent"
                            marginSize={0}
                            title="QR code for the SYNAPSE APK"
                            imageSettings={{
                                src: '/favicon.svg',
                                height: 26,
                                width: 26,
                                excavate: true,
                            }}
                        />
                    </div>
                    <figcaption className="max-w-[9rem] text-center text-[0.76rem] leading-snug text-ink-soft">
                        Scan with your phone to download
                    </figcaption>
                </figure>
            </div>

            <div className="mt-8 grid gap-3 border-t border-ink/6 pt-6 sm:grid-cols-2">
                <SoonBadge store="Google Play" glyph={<PlayGlyph />} />
                <SoonBadge store="the App Store" glyph={<PhoneGlyph />} />
            </div>
        </div>
    );
}

/* ── The phone: the app's Clock screen, on Manila's clock ─────────────────── */

const TIMED_IN = 8 * 60 + 2; // 8:02 AM
const SHIFT_END = 17 * 60; // 5:00 PM

function manilaNow(): { minutes: number; seconds: number; label: string } {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(new Date());
    const get = (type: string) =>
        Number(parts.find((p) => p.type === type)?.value ?? 0);
    const h = get('hour');
    const m = get('minute');
    const s = get('second');

    return { minutes: h * 60 + m, seconds: s, label: clock(h * 60 + m) };
}

function clock(minutes: number): string {
    const h = Math.floor(minutes / 60) % 24;
    const m = minutes % 60;

    return `${h % 12 || 12}:${String(m).padStart(2, '0')}`;
}

function meridiem(minutes: number): string {
    return Math.floor(minutes / 60) % 24 < 12 ? 'AM' : 'PM';
}

function span(totalSeconds: number): string {
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);

    return `${h}h ${String(m).padStart(2, '0')}m`;
}

function useManilaClock() {
    const [now, setNow] = useState(manilaNow);

    useEffect(() => {
        const id = window.setInterval(() => setNow(manilaNow()), 1000);

        return () => window.clearInterval(id);
    }, []);

    return now;
}

function Phone() {
    const now = useManilaClock();
    const before = now.minutes < TIMED_IN;
    const after = now.minutes >= SHIFT_END;
    const workedSeconds = before
        ? 0
        : after
          ? (SHIFT_END - TIMED_IN) * 60
          : (now.minutes - TIMED_IN) * 60 + now.seconds;
    const progress = workedSeconds / ((SHIFT_END - TIMED_IN) * 60);
    const circumference = 2 * Math.PI * 104;

    return (
        <div
            role="img"
            aria-label="The SYNAPSE app's Clock screen: the time in Manila, the hours worked today, and the button to time in or out."
            className="relative w-[18.5rem] rotate-[-4deg] rounded-[3.1rem] bg-ink p-[0.6rem] shadow-[0_50px_90px_-40px_rgb(15_32_68/0.7),inset_0_0_0_1.5px_rgb(255_255_255/0.08)] sm:w-[20rem] lg:rotate-[-6deg]"
        >
            <div className="relative overflow-hidden rounded-[2.55rem] bg-[#f6f8fc]">
                {/* Status bar and the island. */}
                <div className="flex items-center justify-between px-7 pt-3.5 text-[0.72rem] font-semibold text-ink">
                    <span className="tabular">{now.label}</span>
                    <span className="h-[1.35rem] w-[5.4rem] rounded-full bg-ink" />
                    <span className="flex items-center gap-1">
                        <svg viewBox="0 0 18 12" className="h-2.5 w-4">
                            {[0, 1, 2, 3].map((i) => (
                                <rect
                                    key={i}
                                    x={i * 4.6}
                                    y={9 - i * 3}
                                    width="3.2"
                                    height={3 + i * 3}
                                    rx="1"
                                    fill="currentColor"
                                />
                            ))}
                        </svg>
                        <span className="h-2.5 w-5 rounded-[0.2rem] ring-1 ring-ink/50">
                            <span className="m-[1.5px] block h-[calc(100%-3px)] w-3/4 rounded-[0.1rem] bg-ink" />
                        </span>
                    </span>
                </div>

                <div className="px-5 pt-5 pb-3">
                    <p className="text-[1.45rem] font-[760] tracking-[-0.02em] text-ink font-semiwide">
                        Clock
                    </p>
                    <p className="text-[0.74rem] text-ink-soft">
                        Day shift, 8:00 AM to 5:00 PM
                    </p>

                    {/* The ring fills as the shift is worked. */}
                    <div className="relative mx-auto mt-4 size-[13.5rem]">
                        <svg
                            viewBox="0 0 240 240"
                            className="size-full -rotate-90"
                        >
                            <defs>
                                <linearGradient
                                    id="phone-ring"
                                    x1="0"
                                    y1="0"
                                    x2="1"
                                    y2="1"
                                >
                                    <stop stopColor="#37B3FF" />
                                    <stop offset="1" stopColor="#1560DB" />
                                </linearGradient>
                            </defs>
                            <circle
                                cx="120"
                                cy="120"
                                r="104"
                                fill="none"
                                stroke="#e3eaf5"
                                strokeWidth="14"
                            />
                            <circle
                                cx="120"
                                cy="120"
                                r="104"
                                fill="none"
                                stroke="url(#phone-ring)"
                                strokeWidth="14"
                                strokeLinecap="round"
                                strokeDasharray={circumference}
                                strokeDashoffset={
                                    circumference * (1 - progress)
                                }
                                className="transition-[stroke-dashoffset] duration-1000 ease-linear"
                            />
                        </svg>
                        <div className="absolute inset-0 flex flex-col items-center justify-center">
                            <p className="text-[2.6rem] leading-none font-[780] tracking-[-0.03em] text-ink font-semiwide tabular">
                                {now.label}
                            </p>
                            <p className="mt-1 text-[0.72rem] font-semibold text-ink-soft">
                                {meridiem(now.minutes)} in Manila
                            </p>
                            <p className="mt-3 rounded-full bg-white px-3 py-1 text-[0.72rem] font-semibold text-signal tabular shadow-sm">
                                {span(workedSeconds)} worked
                            </p>
                        </div>
                    </div>

                    <div
                        className={`mt-4 rounded-2xl py-3.5 text-center text-[0.92rem] font-semibold ${
                            after
                                ? 'bg-frost text-ink-soft'
                                : 'bg-ink text-white'
                        }`}
                    >
                        {before
                            ? 'Time in'
                            : after
                              ? 'Done for today'
                              : 'Time out'}
                    </div>

                    <div className="mt-3 grid grid-cols-3 rounded-2xl bg-white py-2.5 text-center ring-1 ring-ink/5">
                        {[
                            ['In', before ? '--' : '8:02'],
                            ['Out', after ? '5:00' : '--'],
                            ['Worked', span(workedSeconds)],
                        ].map(([k, v]) => (
                            <div key={k}>
                                <p className="text-[0.62rem] text-ink-soft">
                                    {k}
                                </p>
                                <p className="text-[0.8rem] font-semibold text-ink tabular">
                                    {v}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>

                {/* Tab bar, Clock selected. */}
                <div className="grid grid-cols-5 border-t border-ink/6 bg-white/90 px-2 pt-2 pb-5 text-[0.56rem] text-ink-soft">
                    {[
                        { icon: House, label: 'Home' },
                        { icon: CalendarDays, label: 'Attendance' },
                        { icon: Clock3, label: 'Clock', on: true },
                        { icon: Palmtree, label: 'Leave' },
                        { icon: UserRound, label: 'Profile' },
                    ].map(({ icon: Icon, label, on }) => (
                        <span
                            key={label}
                            className={`flex flex-col items-center gap-1 ${on ? 'text-signal' : ''}`}
                        >
                            <Icon
                                className="size-[1.1rem]"
                                strokeWidth={on ? 2.4 : 1.9}
                            />
                            {label}
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}
