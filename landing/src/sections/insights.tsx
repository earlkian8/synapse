import { motion, useInView, useReducedMotion } from 'motion/react';
import { useRef } from 'react';
import { Mark } from '../components/mark';

type Tier = 'stable' | 'watch' | 'high';

// Illustrative people; the factors are the model's real inputs.
const roster: {
    name: string;
    dept: string;
    score: number;
    tier: Tier;
    factor: string;
}[] = [
    {
        name: 'Jose Santos',
        dept: 'Operations',
        score: 78,
        tier: 'high',
        factor: 'Never promoted in 4 years',
    },
    {
        name: 'Maria Cruz',
        dept: 'Finance',
        score: 64,
        tier: 'watch',
        factor: '11 late arrivals in 90 days',
    },
    {
        name: 'Paolo Garcia',
        dept: 'Sales',
        score: 57,
        tier: 'watch',
        factor: 'Probationary, 7 months in',
    },
    {
        name: 'Kristine Bautista',
        dept: 'People',
        score: 31,
        tier: 'stable',
        factor: 'Promoted last year',
    },
    {
        name: 'Ramon Villanueva',
        dept: 'IT',
        score: 18,
        tier: 'stable',
        factor: 'Steady attendance',
    },
];

const tierStyle: Record<Tier, { label: string; bar: string; chip: string }> = {
    stable: {
        label: 'Stable',
        bar: 'bg-[#3ccf96]',
        chip: 'bg-[#3ccf96]/14 text-[#7ee6bd]',
    },
    watch: {
        label: 'At watch',
        bar: 'bg-[#f5a524]',
        chip: 'bg-[#f5a524]/14 text-[#ffc86b]',
    },
    high: {
        label: 'High risk',
        bar: 'bg-[#ff6b5e]',
        chip: 'bg-[#ff6b5e]/14 text-[#ff9d93]',
    },
};

const models = [
    {
        name: 'Promotion readiness',
        body: 'A readiness score for each employee, from their appraisal record. Anyone it cannot assess is listed with the reason, never scored from a guess.',
    },
    {
        name: 'Performance forecast',
        body: "Next period's rating for each person, with a likely range and how confident the model is.",
    },
    {
        name: 'Attrition risk',
        body: 'A risk from 0 to 100 for every active employee, with the factors behind it. No demographic or protected attribute is ever used.',
    },
];

const ease = [0.22, 1, 0.36, 1] as const;

export function Insights() {
    return (
        <section
            id="insights"
            className="relative scroll-mt-12 bg-ink text-white"
        >
            {/* The band's top edge is the mark's sweep, carrying its dots. */}
            <svg
                aria-hidden="true"
                viewBox="0 0 1440 120"
                preserveAspectRatio="none"
                className="absolute bottom-full left-0 h-[clamp(3rem,8vw,7.5rem)] w-full"
            >
                <path
                    d="M0 120V92C220 92 330 30 560 26C820 22 900 98 1140 98C1280 98 1370 70 1440 48V120Z"
                    fill="var(--color-ink)"
                />
            </svg>

            <div className="mx-auto max-w-[84rem] px-4 pt-16 pb-24 sm:px-6 lg:px-10 lg:pt-20 lg:pb-36">
                <div className="grid gap-14 lg:grid-cols-12 lg:gap-10">
                    <div className="lg:col-span-5">
                        <h2 className="text-[clamp(2.1rem,5vw,3.9rem)] leading-[0.98] font-[780] tracking-[-0.03em] text-balance font-wide">
                            Know who is ready, and who may leave.
                        </h2>
                        <p className="mt-6 max-w-[30rem] text-[1.05rem] leading-[1.65] text-white/65">
                            Three models score your people from the records
                            SYNAPSE already keeps. Each starts general and moves
                            to one trained on your own organisation once there
                            are enough of its records.
                        </p>

                        <dl className="mt-12 divide-y divide-white/10 border-y border-white/10">
                            {models.map((m) => (
                                <div
                                    key={m.name}
                                    className="grid gap-1.5 py-5 sm:grid-cols-[11rem_1fr] sm:gap-6"
                                >
                                    <dt className="text-[0.98rem] font-semibold text-sky-lift">
                                        {m.name}
                                    </dt>
                                    <dd className="text-[0.92rem] leading-[1.6] text-white/60">
                                        {m.body}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>

                    <div className="relative lg:col-span-7 lg:-mr-[max(2.5rem,calc((100vw-84rem)/2+2.5rem))]">
                        <Roster />
                    </div>
                </div>

                <Assistant />
            </div>
        </section>
    );
}

function Roster() {
    const total = 86;
    const counts = { stable: 61, watch: 18, high: 7 };

    return (
        <div
            aria-hidden="true"
            className="overflow-hidden rounded-[1.75rem] bg-[#16295a] ring-1 ring-white/8 lg:rounded-r-none"
        >
            <div className="flex flex-wrap items-end justify-between gap-4 px-6 pt-6 sm:px-8 sm:pt-8">
                <div>
                    <p className="text-[0.82rem] text-white/50">
                        Attrition risk
                    </p>
                    <p className="mt-1 text-[1.3rem] font-[720] font-semiwide">
                        October assessment
                    </p>
                </div>
                <p className="text-[0.82rem] text-white/50 tabular">
                    {total} people assessed
                </p>
            </div>

            {/* How the organisation splits across the three tiers. */}
            <div className="px-6 pt-6 sm:px-8">
                <div className="flex h-3 gap-1 overflow-hidden rounded-full">
                    {(['stable', 'watch', 'high'] as const).map((t) => (
                        <div
                            key={t}
                            className={`${tierStyle[t].bar} rounded-full`}
                            style={{ width: `${(counts[t] / total) * 100}%` }}
                        />
                    ))}
                </div>
                <div className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-[0.78rem] text-white/60 tabular">
                    {(['stable', 'watch', 'high'] as const).map((t) => (
                        <span
                            key={t}
                            className="inline-flex items-center gap-2"
                        >
                            <span
                                className={`size-2 rounded-full ${tierStyle[t].bar}`}
                            />
                            {tierStyle[t].label}, {counts[t]}
                        </span>
                    ))}
                </div>
            </div>

            <ul className="mt-6 divide-y divide-white/6 border-t border-white/6">
                {roster.map((p) => {
                    const tier = tierStyle[p.tier];

                    return (
                        <li
                            key={p.name}
                            className="grid grid-cols-[1fr_auto] items-center gap-x-5 gap-y-2 px-6 py-4 sm:grid-cols-[minmax(0,13rem)_minmax(6rem,1fr)_auto] sm:px-8 xl:grid-cols-[minmax(0,13rem)_minmax(6rem,1fr)_auto_minmax(0,14rem)]"
                        >
                            <div className="col-start-1 row-start-1 min-w-0">
                                <p className="truncate text-[0.92rem] font-semibold">
                                    {p.name}
                                </p>
                                <p className="text-[0.76rem] text-white/45">
                                    {p.dept}
                                </p>
                            </div>
                            <div className="col-span-2 flex items-center gap-3 sm:col-span-1">
                                <div className="h-1.5 flex-1 rounded-full bg-white/8">
                                    <div
                                        className={`h-full rounded-full ${tier.bar}`}
                                        style={{ width: `${p.score}%` }}
                                    />
                                </div>
                                <span className="w-6 text-right text-[0.86rem] font-semibold tabular">
                                    {p.score}
                                </span>
                            </div>
                            <span
                                className={`col-start-2 row-start-1 justify-self-end rounded-full px-2.5 py-1 text-[0.72rem] font-semibold sm:col-start-auto sm:row-start-auto ${tier.chip}`}
                            >
                                {tier.label}
                            </span>
                            <p className="col-span-2 text-[0.78rem] text-white/50 sm:col-span-3 xl:col-span-1">
                                {p.factor}
                            </p>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

const steps = [
    'Found Ana Reyes in Finance',
    'Checked her balance: 7.5 vacation days',
    'Oct 20 and 21 are working days, so 2 days',
];

function Assistant() {
    const ref = useRef<HTMLDivElement>(null);
    const seen = useInView(ref, { once: true, margin: '0px 0px -25% 0px' });
    const reduce = useReducedMotion();
    const show = reduce || seen;

    return (
        <div className="mt-24 grid items-center gap-14 lg:mt-28 lg:grid-cols-12 lg:gap-10">
            <div
                ref={ref}
                aria-hidden="true"
                className="order-2 lg:order-1 lg:col-span-6 lg:-ml-[max(2.5rem,calc((100vw-84rem)/2+2.5rem))]"
            >
                <div className="rounded-[1.75rem] bg-white p-6 text-ink shadow-[0_40px_80px_-40px_rgb(0_0_0/0.6)] sm:p-8 lg:rounded-l-none lg:pl-[max(2.5rem,calc((100vw-84rem)/2+2.5rem))]">
                    <div className="flex justify-end">
                        <p className="max-w-[22rem] rounded-[1.25rem] rounded-br-md bg-ink px-4 py-3 text-[0.92rem] leading-snug text-white">
                            File 2 days of vacation leave for Ana Reyes, Oct 20
                            and 21.
                        </p>
                    </div>

                    <div className="mt-6 flex gap-3">
                        <Mark
                            className="mt-0.5 h-7 w-auto shrink-0"
                            title={null}
                        />
                        <div className="min-w-0 flex-1">
                            <ol className="space-y-2.5">
                                {steps.map((step, i) => (
                                    <motion.li
                                        key={step}
                                        initial={false}
                                        animate={
                                            show
                                                ? { opacity: 1, x: 0 }
                                                : { opacity: 0, x: -8 }
                                        }
                                        transition={{
                                            duration: 0.5,
                                            delay:
                                                show && !reduce
                                                    ? 0.3 + i * 0.45
                                                    : 0,
                                            ease,
                                        }}
                                        className="flex items-center gap-2.5 text-[0.86rem] text-ink-soft"
                                    >
                                        <span className="grid size-4.5 shrink-0 place-items-center rounded-full bg-ok/12">
                                            <svg
                                                viewBox="0 0 12 12"
                                                className="size-2.5"
                                            >
                                                <path
                                                    d="m2.6 6.3 2.2 2.1 4.6-4.8"
                                                    fill="none"
                                                    stroke="var(--color-ok)"
                                                    strokeWidth="1.9"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                />
                                            </svg>
                                        </span>
                                        {step}
                                    </motion.li>
                                ))}
                            </ol>

                            <motion.div
                                initial={false}
                                animate={
                                    show
                                        ? { opacity: 1, y: 0 }
                                        : { opacity: 0, y: 10 }
                                }
                                transition={{
                                    duration: 0.6,
                                    delay: show && !reduce ? 1.75 : 0,
                                    ease,
                                }}
                                className="mt-5 rounded-2xl bg-ice p-4 ring-1 ring-ink/6"
                            >
                                <p className="text-[0.9rem] font-semibold">
                                    File vacation leave for Ana Reyes
                                </p>
                                <p className="mt-1 text-[0.82rem] text-ink-soft tabular">
                                    Oct 20 to 21, 2 days. She keeps 5.5.
                                </p>
                                <div className="mt-4 flex gap-2">
                                    <span className="rounded-full bg-ink px-4 py-2 text-[0.82rem] font-semibold text-white">
                                        Confirm
                                    </span>
                                    <span className="rounded-full px-4 py-2 text-[0.82rem] font-semibold text-ink-soft ring-1 ring-ink/12 ring-inset">
                                        Cancel
                                    </span>
                                </div>
                            </motion.div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="order-1 lg:order-2 lg:col-span-5 lg:col-start-8">
                <h3 className="text-[clamp(1.8rem,4vw,3rem)] leading-[1] font-[760] tracking-[-0.03em] text-balance font-wide">
                    Ask it, or have it do the work.
                </h3>
                <p className="mt-6 max-w-[29rem] text-[1.05rem] leading-[1.65] text-white/65">
                    The assistant answers questions about your workspace from
                    your own records. Ask it to do something and it works
                    through the same steps as the screens, only where your role
                    allows. Consequential changes wait for you to confirm them.
                </p>
            </div>
        </div>
    );
}
