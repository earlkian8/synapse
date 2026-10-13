import { motion, useReducedMotion } from 'motion/react';
import type { ReactNode } from 'react';
import { DownloadApkLink, OpenErpLink } from '../components/actions';
import { FiringMark } from '../components/firing-mark';

const ease = [0.22, 1, 0.36, 1] as const;

const headline = ['HR that sees', "what's coming."];

export function Hero() {
    const reduce = useReducedMotion();

    return (
        <section
            id="top"
            className="relative isolate pt-28 pb-16 lg:flex lg:min-h-[100svh] lg:items-center lg:pt-24 lg:pb-12"
        >
            {/* The street grid, faded out toward the copy. */}
            <div
                aria-hidden="true"
                className="absolute inset-0 -z-10 bg-grid [mask-image:radial-gradient(ellipse_70%_75%_at_78%_50%,black,transparent_75%)] opacity-70"
            />

            <div className="mx-auto grid w-full max-w-[84rem] px-4 sm:px-6 lg:grid-cols-12 lg:items-center lg:px-10">
                <div className="relative z-10 lg:col-span-7">
                    <h1 className="text-[clamp(2.2rem,9.2vw,3.6rem)] leading-[0.94] font-[800] tracking-[-0.035em] text-ink lg:text-[clamp(3.4rem,5.6vw,5.2rem)]">
                        {headline.map((line, i) => (
                            <span
                                key={line}
                                className="block overflow-hidden pb-[0.06em]"
                            >
                                <motion.span
                                    className="block whitespace-nowrap font-wide"
                                    initial={
                                        reduce
                                            ? false
                                            : { y: '105%', fontStretch: '88%' }
                                    }
                                    animate={{ y: '0%', fontStretch: '125%' }}
                                    transition={{
                                        y: {
                                            duration: 0.9,
                                            delay: 0.15 + i * 0.09,
                                            ease,
                                        },
                                        fontStretch: {
                                            duration: 1.5,
                                            delay: 0.35 + i * 0.09,
                                            ease,
                                        },
                                    }}
                                >
                                    {line}
                                </motion.span>
                            </span>
                        ))}
                    </h1>

                    <Rise delay={0.55} reduce={reduce}>
                        <p className="mt-7 max-w-[34rem] text-[1.08rem] leading-[1.6] text-ink-soft sm:text-[1.15rem]">
                            SYNAPSE is the HR system for Philippine
                            organisations. It keeps every record from hiring to
                            exit, and its models flag who is ready for promotion
                            and who may leave while there is still time to act.
                        </p>
                    </Rise>

                    <Rise delay={0.7} reduce={reduce}>
                        <div className="mt-9 flex flex-wrap items-center gap-3">
                            <OpenErpLink />
                            <DownloadApkLink />
                        </div>
                        <p className="mt-5 max-w-[30rem] text-[0.88rem] leading-relaxed text-ink-soft/80">
                            Employees clock in from the Android app. The iPhone
                            app and the Play Store listing are coming soon.
                        </p>
                    </Rise>
                </div>

                {/* The mark, as big as the window allows and never cut off: it
                    reaches into the right-hand gutter (up to 7rem, never closer than
                    1rem to the edge),
                    and its height is capped by the window's (it is 0.8616 as
                    wide as it is tall). */}
                <div className="relative mt-14 lg:col-span-5 lg:mt-0 lg:-mr-[calc(min(max(2.5rem,50vw-39.5rem),8rem)-1rem)]">
                    <div className="relative mx-auto w-[min(78vw,26rem)] lg:mr-0 lg:w-full lg:max-w-[min(44rem,calc((100svh-7rem)*0.8616))]">
                        <FiringMark className="h-auto w-full" />
                        <Specimens reduce={reduce} />
                    </div>
                </div>
            </div>
        </section>
    );
}

function Rise({
    children,
    delay,
    reduce,
}: {
    children: ReactNode;
    delay: number;
    reduce: boolean | null;
}) {
    return (
        <motion.div
            initial={reduce ? false : { opacity: 0, y: 18 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, delay, ease }}
        >
            {children}
        </motion.div>
    );
}

/** Three moments from a working day, pinned to the mark's three dots. */
function Specimens({ reduce }: { reduce: boolean | null }) {
    const pop = (delay: number) =>
        reduce
            ? {}
            : {
                  initial: { opacity: 0, y: 10, scale: 0.96 },
                  animate: { opacity: 1, y: 0, scale: 1 },
                  transition: { duration: 0.7, delay, ease },
              };

    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0"
        >
            <motion.div
                {...pop(1.7)}
                className="absolute top-[3%] left-[-12%] sm:left-[-4%]"
            >
                <Chip>
                    <ClockRing />
                    <div>
                        <p className="text-[0.82rem] font-semibold text-ink">
                            Timed in at 8:02 AM
                        </p>
                        <p className="text-[0.74rem] text-ink-soft">
                            Inside the Makati office fence
                        </p>
                    </div>
                </Chip>
            </motion.div>

            <motion.div
                {...pop(1.95)}
                className="absolute top-[47%] right-[12%] hidden sm:block"
            >
                <Chip>
                    <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-ok/10">
                        <svg viewBox="0 0 20 20" className="size-4.5">
                            <path
                                d="m5 10.5 3.2 3L15 6.5"
                                fill="none"
                                stroke="var(--color-ok)"
                                strokeWidth="2.2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                        </svg>
                    </span>
                    <div>
                        <p className="text-[0.82rem] font-semibold text-ink">
                            Vacation leave approved
                        </p>
                        <p className="text-[0.74rem] text-ink-soft tabular">
                            Oct 20 to 21, with 5.5 days left
                        </p>
                    </div>
                </Chip>
            </motion.div>

            <motion.div
                {...pop(2.2)}
                className="absolute top-[84%] left-[26%] sm:left-[30%]"
            >
                <Chip>
                    <RiskMeter />
                    <div>
                        <p className="text-[0.82rem] font-semibold text-ink">
                            Attrition risk: at watch
                        </p>
                        <p className="text-[0.74rem] text-ink-soft">
                            No promotion in 3 years
                        </p>
                    </div>
                </Chip>
            </motion.div>
        </div>
    );
}

function Chip({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-center gap-3 rounded-2xl bg-white/90 py-2.5 pr-4 pl-2.5 whitespace-nowrap shadow-[0_1px_2px_rgb(15_32_68/0.08),0_18px_40px_-18px_rgb(21_96_219/0.45)] ring-1 ring-ink/6 backdrop-blur">
            {children}
        </div>
    );
}

/** A worked-shift ring, as the app's Clock screen draws it. */
function ClockRing() {
    return (
        <svg viewBox="0 0 36 36" className="size-9 shrink-0 -rotate-90">
            <circle
                cx="18"
                cy="18"
                r="14"
                fill="none"
                stroke="var(--color-frost)"
                strokeWidth="4"
            />
            <circle
                cx="18"
                cy="18"
                r="14"
                fill="none"
                stroke="var(--color-signal)"
                strokeWidth="4"
                strokeLinecap="round"
                strokeDasharray="88"
                strokeDashoffset="66"
            />
        </svg>
    );
}

/** Stable, at watch, high risk: the second band lit. */
function RiskMeter() {
    return (
        <span className="flex h-9 shrink-0 items-end gap-[3px] rounded-xl bg-watch/10 px-2 pb-2">
            <span className="h-2 w-1.5 rounded-full bg-ok/35" />
            <span className="h-3.5 w-1.5 rounded-full bg-watch" />
            <span className="h-5 w-1.5 rounded-full bg-risk/25" />
        </span>
    );
}
