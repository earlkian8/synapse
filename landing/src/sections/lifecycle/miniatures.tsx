/**
 * One drawn miniature per stage of the lifecycle. Each is a small picture of
 * what that screen actually holds, so they differ in shape on purpose: a
 * pipeline, a paper checklist, a manila folder, a map, and so on. The names and
 * numbers in them are illustrative.
 */
import type { ReactNode } from 'react';

function Tick({ done = true }: { done?: boolean }) {
    return done ? (
        <span className="grid size-4.5 shrink-0 place-items-center rounded-full bg-signal">
            <svg viewBox="0 0 12 12" className="size-2.5">
                <path
                    d="m2.6 6.3 2.2 2.1 4.6-4.8"
                    fill="none"
                    stroke="white"
                    strokeWidth="1.8"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            </svg>
        </span>
    ) : (
        <span className="size-4.5 shrink-0 rounded-full ring-[1.5px] ring-ink/20 ring-inset" />
    );
}

function Paper({
    children,
    className = '',
}: {
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`rounded-[1.1rem] bg-white shadow-[0_1px_2px_rgb(15_32_68/0.06),0_24px_48px_-30px_rgb(15_32_68/0.45)] ring-1 ring-ink/6 ${className}`}
        >
            {children}
        </div>
    );
}

/* 01 Recruit: the pipeline narrowing to a hire. */
export function PipelineMini() {
    const stages = [
        { label: 'Applied', n: 48 },
        { label: 'Screened', n: 21 },
        { label: 'Interview', n: 9 },
        { label: 'Offer', n: 2 },
    ];

    return (
        <div className="w-full max-w-[21rem]">
            <ul className="space-y-2">
                {stages.map((s, i) => (
                    <li key={s.label} className="flex items-center gap-3">
                        <span className="w-[4.6rem] shrink-0 text-[0.78rem] text-ink-soft">
                            {s.label}
                        </span>
                        <span
                            className="h-7 rounded-lg bg-signal"
                            style={{
                                width: `${(s.n / 48) * 100}%`,
                                minWidth: '1.75rem',
                                opacity: 1 - i * 0.2,
                            }}
                        />
                        <span className="text-[0.8rem] font-semibold text-ink tabular">
                            {s.n}
                        </span>
                    </li>
                ))}
            </ul>
            <div className="mt-4 ml-[5.35rem] inline-flex items-center gap-2 rounded-full bg-white py-1.5 pr-3.5 pl-1.5 text-[0.76rem] shadow-sm ring-1 ring-ink/8">
                <span className="grid size-6 place-items-center rounded-full bg-sky/20 text-[0.62rem] font-bold text-signal-deep">
                    AR
                </span>
                <span className="text-ink">Hired, and now an employee</span>
            </div>
        </div>
    );
}

/* 02 Onboard: a day-one checklist on paper, slightly askew. */
export function ChecklistMini() {
    const tasks = [
        { t: 'Sign the contract', done: true },
        { t: 'Laptop and work email', done: true },
        { t: 'SSS, PhilHealth and Pag-IBIG numbers', done: true },
        { t: 'Meet the team', done: false },
        { t: 'Safety orientation', done: false },
    ];

    return (
        <Paper className="w-full max-w-[20rem] -rotate-[1.5deg] p-5">
            <div className="flex items-baseline justify-between">
                <p className="text-[0.86rem] font-semibold text-ink">Day one</p>
                <p className="text-[0.76rem] text-ink-soft tabular">3 of 5</p>
            </div>
            <div className="mt-2.5 h-1.5 rounded-full bg-frost">
                <div className="h-full w-3/5 rounded-full bg-signal" />
            </div>
            <ul className="mt-4 space-y-2.5">
                {tasks.map((task) => (
                    <li
                        key={task.t}
                        className="flex items-center gap-2.5 text-[0.8rem]"
                    >
                        <Tick done={task.done} />
                        <span
                            className={
                                task.done
                                    ? 'text-ink-soft line-through decoration-ink/25'
                                    : 'text-ink'
                            }
                        >
                            {task.t}
                        </span>
                    </li>
                ))}
            </ul>
        </Paper>
    );
}

/* 03 The 201 file: the manila folder every Philippine HR office keeps, with
   the government numbers masked on the sheet inside. */
export function FolderMini() {
    return (
        <div className="relative w-full max-w-[21rem]">
            <svg
                viewBox="0 0 340 256"
                className="w-full drop-shadow-[0_26px_30px_rgb(120_86_20/0.22)]"
                aria-hidden="true"
            >
                {/* Back of the folder, with its tab. */}
                <path
                    d="M14 26h86c6 0 9 3 12 8l8 12h204c6 0 10 4 10 10v186c0 6-4 10-10 10H14c-6 0-10-4-10-10V36c0-6 4-10 10-10Z"
                    fill="var(--color-manila-deep)"
                />
                <text
                    x="28"
                    y="43"
                    fontSize="13"
                    fontWeight="700"
                    fill="#6b4f17"
                    style={{ fontStretch: '125%' }}
                >
                    201
                </text>

                {/* Two sheets inside. */}
                <g transform="rotate(-3 170 140)">
                    <rect
                        x="40"
                        y="56"
                        width="250"
                        height="170"
                        rx="6"
                        fill="#f4f7fc"
                    />
                </g>
                <rect
                    x="30"
                    y="62"
                    width="268"
                    height="176"
                    rx="6"
                    fill="white"
                />
                <text
                    x="48"
                    y="90"
                    fontSize="12.5"
                    fontWeight="700"
                    fill="var(--color-ink)"
                >
                    Personal data sheet
                </text>
                <rect
                    x="48"
                    y="100"
                    width="150"
                    height="5"
                    rx="2.5"
                    fill="var(--color-frost)"
                />
                {[
                    ['TIN', '•••-•••-482'],
                    ['SSS', '••-•••••47-8'],
                    ['PhilHealth', '••-•••••••3-1'],
                ].map(([k, v], i) => (
                    <g key={k} transform={`translate(48 ${127 + i * 20})`}>
                        <text fontSize="11" fill="var(--color-ink-soft)">
                            {k}
                        </text>
                        <text
                            x="84"
                            fontSize="11"
                            fontWeight="600"
                            fill="var(--color-ink)"
                            style={{ fontVariantNumeric: 'tabular-nums' }}
                        >
                            {v}
                        </text>
                    </g>
                ))}

                {/* Front flap, cut low so the sheet shows. */}
                <path
                    d="M4 188c0-6 4-10 10-10h312c6 0 10 4 10 10v54c0 6-4 10-10 10H14c-6 0-10-4-10-10v-54Z"
                    fill="var(--color-manila)"
                />
                <path d="M4 180h332" stroke="#f3dca8" strokeWidth="2" />
                <rect
                    x="22"
                    y="192"
                    width="160"
                    height="32"
                    rx="4"
                    fill="#fdf8ec"
                />
                <text
                    x="34"
                    y="213"
                    fontSize="13"
                    fontWeight="700"
                    fill="#5d4614"
                    letterSpacing="0.6"
                >
                    REYES, Ana M.
                </text>
                <text x="236" y="240" fontSize="10.5" fill="#6b4f17">
                    Hired Mar 2023
                </text>
            </svg>
        </div>
    );
}

/* 04 Attendance: a site's fence on a street grid, and a punch inside it. */
export function GeofenceMini() {
    return (
        <div className="w-full max-w-[20rem]">
            <div className="relative aspect-[4/3] overflow-hidden rounded-[1.4rem] bg-[#e9eff8] ring-1 ring-ink/8">
                <svg
                    viewBox="0 0 320 240"
                    className="absolute inset-0 size-full"
                    aria-hidden="true"
                >
                    {/* Blocks and streets. */}
                    <g fill="#dde6f3">
                        <rect x="-10" y="-10" width="96" height="74" rx="6" />
                        <rect x="104" y="-10" width="120" height="74" rx="6" />
                        <rect x="242" y="-10" width="90" height="74" rx="6" />
                        <rect x="-10" y="84" width="96" height="86" rx="6" />
                        <rect x="242" y="84" width="90" height="86" rx="6" />
                        <rect x="-10" y="190" width="160" height="60" rx="6" />
                        <rect x="170" y="190" width="160" height="60" rx="6" />
                    </g>
                    <path
                        d="M-10 178 330 168"
                        stroke="#f7f9fc"
                        strokeWidth="9"
                    />
                    <path
                        d="M96 -10 98 250M234 -10 232 250"
                        stroke="#f7f9fc"
                        strokeWidth="9"
                    />
                    {/* The fence, drawn as a shape around the site. */}
                    <path
                        d="M112 84 214 76 226 150 172 168 106 158Z"
                        fill="rgb(28 115 232 / 0.12)"
                        stroke="var(--color-signal)"
                        strokeWidth="2"
                        strokeDasharray="6 5"
                        strokeLinejoin="round"
                    />
                    <rect
                        x="138"
                        y="100"
                        width="56"
                        height="44"
                        rx="5"
                        fill="#c9d6ea"
                    />
                </svg>
                {/* The punch. */}
                <div className="absolute top-[48%] left-[53%] -translate-x-1/2 -translate-y-full">
                    <span className="geo-ping absolute bottom-0 left-1/2 size-8 -translate-x-1/2 translate-y-1/2 rounded-full bg-signal/30" />
                    <svg
                        viewBox="0 0 24 30"
                        className="relative size-7 drop-shadow-md"
                    >
                        <path
                            d="M12 29s9-9.3 9-16.2A9 9 0 0 0 3 12.8C3 19.7 12 29 12 29Z"
                            fill="var(--color-ink)"
                        />
                        <circle cx="12" cy="12.5" r="3.6" fill="white" />
                    </svg>
                </div>
                <p className="absolute bottom-3 left-3 rounded-full bg-white/90 px-3 py-1 text-[0.72rem] font-medium text-ink shadow-sm">
                    Inside the fence, 18 m from the door
                </p>
            </div>
            <div className="mt-3 flex items-center gap-2.5 px-1 text-[0.78rem] text-ink-soft">
                <svg
                    viewBox="0 0 20 20"
                    className="size-4 text-watch"
                    aria-hidden="true"
                >
                    <path
                        d="M2.5 7.5a11 11 0 0 1 15 0M5 10.5a7 7 0 0 1 10 0M8 13.4a2.8 2.8 0 0 1 4 0"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.7"
                        strokeLinecap="round"
                    />
                    <path
                        d="M3 3l14 14"
                        stroke="currentColor"
                        strokeWidth="1.7"
                        strokeLinecap="round"
                    />
                </svg>
                No signal? Two punches are waiting to send.
            </div>
        </div>
    );
}

/* 05 Leave: balances, and a week where the holiday isn't charged. */
export function LeaveMini() {
    const balances = [
        { label: 'Vacation', left: 7.5, of: 15, tone: 'bg-signal' },
        { label: 'Sick', left: 10, of: 15, tone: 'bg-sky' },
        { label: 'Service incentive', left: 5, of: 5, tone: 'bg-ink' },
    ];
    const week = [
        { d: 'Mon', n: 30, k: 'holiday' },
        { d: 'Tue', n: 1, k: 'leave' },
        { d: 'Wed', n: 2, k: 'leave' },
        { d: 'Thu', n: 3, k: 'leave' },
        { d: 'Fri', n: 4, k: 'work' },
        { d: 'Sat', n: 5, k: 'rest' },
    ];

    return (
        <div className="w-full max-w-[21rem] space-y-5">
            <ul className="space-y-3">
                {balances.map((b) => (
                    <li key={b.label}>
                        <div className="flex items-baseline justify-between text-[0.78rem]">
                            <span className="text-ink-soft">{b.label}</span>
                            <span className="font-semibold text-ink tabular">
                                {b.left} of {b.of} days
                            </span>
                        </div>
                        <div className="mt-1.5 h-2.5 rounded-full bg-frost">
                            <div
                                className={`h-full rounded-full ${b.tone}`}
                                style={{ width: `${(b.left / b.of) * 100}%` }}
                            />
                        </div>
                    </li>
                ))}
            </ul>
            <div className="flex gap-1.5">
                {week.map((day) => (
                    <div
                        key={day.d}
                        className={`flex flex-1 flex-col items-center rounded-xl py-2 text-[0.7rem] ${
                            day.k === 'leave'
                                ? 'bg-signal text-white'
                                : day.k === 'holiday'
                                  ? 'bg-manila/45 text-[#5d4614] ring-1 ring-manila-deep/40'
                                  : 'bg-white text-ink-soft ring-1 ring-ink/8'
                        }`}
                    >
                        <span className="opacity-80">{day.d}</span>
                        <span className="text-[0.9rem] font-semibold tabular">
                            {day.n}
                        </span>
                    </div>
                ))}
            </div>
            <p className="-mt-2 text-[0.76rem] text-ink-soft">
                Nov 30 to Dec 3 is charged as 3 days. Nov 30 is Bonifacio Day.
            </p>
        </div>
    );
}

/* 06 Performance & training: a 360 review wheel and a certificate's clock. */
export function ReviewMini() {
    const sources = [
        { label: 'Self', score: 4.0, angle: -90 },
        { label: 'Manager', score: 4.5, angle: 0 },
        { label: 'Peers', score: 4.4, angle: 90 },
        { label: 'Reports', score: 4.1, angle: 180 },
    ];
    const point = (score: number, angle: number) => {
        const r = (score / 5) * 74;
        const a = (angle * Math.PI) / 180;
        return [110 + r * Math.cos(a), 104 + r * Math.sin(a)];
    };
    const shape = sources
        .map((s) => point(s.score, s.angle).join(' '))
        .join(' L ');

    return (
        <div className="flex w-full max-w-[26rem] flex-col items-start gap-4 sm:flex-row sm:items-center">
            <svg
                viewBox="-24 0 268 210"
                className="w-[14rem] shrink-0"
                aria-hidden="true"
            >
                {[1, 2, 3, 4, 5].map((ring) => (
                    <circle
                        key={ring}
                        cx="110"
                        cy="104"
                        r={(ring / 5) * 74}
                        fill="none"
                        stroke="var(--color-line)"
                        strokeDasharray={ring === 5 ? undefined : '2 4'}
                    />
                ))}
                <path d="M110 30v148M36 104h148" stroke="var(--color-line)" />
                <path
                    d={`M ${shape} Z`}
                    fill="rgb(55 179 255 / 0.22)"
                    stroke="var(--color-signal)"
                    strokeWidth="2"
                    strokeLinejoin="round"
                />
                {sources.map((s) => {
                    const [x, y] = point(s.score, s.angle);
                    const [lx, ly] = point(6.4, s.angle);

                    return (
                        <g key={s.label}>
                            <circle
                                cx={x}
                                cy={y}
                                r="4"
                                fill="var(--color-signal)"
                            />
                            <text
                                x={lx}
                                y={ly + 4}
                                textAnchor="middle"
                                fontSize="13"
                                fill="var(--color-ink-soft)"
                            >
                                {s.label}
                            </text>
                        </g>
                    );
                })}
                <text
                    x="110"
                    y="109"
                    textAnchor="middle"
                    fontSize="15"
                    fontWeight="800"
                    fill="var(--color-ink)"
                >
                    4.3
                </text>
            </svg>
            <Paper className="flex shrink-0 items-center gap-3 px-4 py-3 whitespace-nowrap">
                <svg
                    viewBox="0 0 28 34"
                    className="h-8 w-7 shrink-0"
                    aria-hidden="true"
                >
                    <circle cx="14" cy="12" r="10" fill="var(--color-manila)" />
                    <circle
                        cx="14"
                        cy="12"
                        r="6"
                        fill="none"
                        stroke="#a9853c"
                        strokeWidth="1.6"
                    />
                    <path
                        d="M8 20l-3 12 5-3 3 4 2-11M20 20l3 12-5-3-3 4-2-11"
                        fill="var(--color-signal)"
                    />
                </svg>
                <div>
                    <p className="text-[0.8rem] font-semibold text-ink">
                        First aid certificate
                    </p>
                    <p className="text-[0.74rem] text-watch">
                        Expires in 30 days
                    </p>
                </div>
            </Paper>
        </div>
    );
}

/* 07 Recognition: kudos on the wall, and the points it carries. */
export function KudosMini() {
    return (
        <div className="relative w-full max-w-[20rem] pt-3 pl-3">
            <Paper className="absolute top-0 left-0 h-full w-[92%] rotate-[3deg] bg-sky/15" />
            <Paper className="relative p-5">
                <div className="flex items-center gap-2.5">
                    <span className="grid size-8 place-items-center rounded-full bg-ink text-[0.66rem] font-bold text-white">
                        MS
                    </span>
                    <p className="text-[0.8rem] text-ink-soft">
                        <span className="font-semibold text-ink">Marco</span> to{' '}
                        <span className="font-semibold text-ink">Ana</span>
                    </p>
                    <span className="ml-auto rounded-full bg-ok/10 px-2.5 py-1 text-[0.72rem] font-semibold text-ok tabular">
                        +50 points
                    </span>
                </div>
                <p className="mt-3.5 text-[0.9rem] leading-snug text-ink">
                    Covered the Cebu audit on a day's notice, and the report was
                    early.
                </p>
                <div className="mt-4 flex gap-1.5">
                    {['Teamwork', 'Ownership'].map((tag) => (
                        <span
                            key={tag}
                            className="rounded-full bg-frost px-2.5 py-1 text-[0.7rem] text-ink-soft"
                        >
                            {tag}
                        </span>
                    ))}
                </div>
            </Paper>
        </div>
    );
}

/* 08 Exit: clearance department by department, then the papers. */
export function ClearanceMini() {
    const items = [
        { dept: 'IT', what: 'Laptop returned', done: true },
        { dept: 'Finance', what: 'Cash advance settled', done: true },
        { dept: 'Admin', what: 'ID and keys surrendered', done: false },
    ];

    return (
        <div className="w-full max-w-[21rem] space-y-3">
            <Paper className="divide-y divide-ink/6">
                {items.map((item) => (
                    <div
                        key={item.dept}
                        className="flex items-center gap-3 px-4 py-3"
                    >
                        <Tick done={item.done} />
                        <span className="w-[3.6rem] shrink-0 text-[0.78rem] font-semibold text-ink">
                            {item.dept}
                        </span>
                        <span className="text-[0.78rem] text-ink-soft">
                            {item.what}
                        </span>
                    </div>
                ))}
            </Paper>
            <div className="ml-6 flex items-center gap-3 rounded-[1rem] bg-ink py-3 pr-4 pl-3 text-white">
                <span className="grid h-10 w-8 place-items-center rounded-md bg-white/10 text-[0.55rem] font-bold tracking-wide">
                    PDF
                </span>
                <div>
                    <p className="text-[0.8rem] font-semibold">
                        Certificate of Employment
                    </p>
                    <p className="text-[0.72rem] text-white/60">
                        Ready when clearance is done
                    </p>
                </div>
            </div>
        </div>
    );
}
