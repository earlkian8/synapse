import {
    motion,
    useInView,
    useReducedMotion,
    useScroll,
    useSpring,
} from 'motion/react';
import {
    type ComponentType,
    type CSSProperties,
    useEffect,
    useRef,
    useState,
} from 'react';
import {
    ChecklistMini,
    ClearanceMini,
    FolderMini,
    GeofenceMini,
    KudosMini,
    LeaveMini,
    PipelineMini,
    ReviewMini,
} from './miniatures';

type Station = {
    title: string;
    body: string;
    Mini: ComponentType;
};

const stations: Station[] = [
    {
        title: 'Recruit',
        body: 'Post openings to your careers page, move candidates through a pipeline and schedule interviews. Hiring someone creates their employee record and starts their onboarding.',
        Mini: PipelineMini,
    },
    {
        title: 'Onboard',
        body: 'Each new hire gets a checklist built from a template, every task assigned and dated, so you can see who is behind and on what.',
        Mini: ChecklistMini,
    },
    {
        title: 'The 201 file',
        body: 'Personal details, government numbers, career history and documents, in one record per person. Government IDs and bank accounts stay masked until someone reveals them.',
        Mini: FolderMini,
    },
    {
        title: 'Attendance',
        body: 'People clock in from their phone inside the fence drawn around each site, with an optional selfie. A punch made without signal is kept and sent later, and each day closes on its own.',
        Mini: GeofenceMini,
    },
    {
        title: 'Leave',
        body: 'Balances follow your leave types, holidays are never charged, and every request waits in one approval inbox. A local holiday applies only at the sites that keep it.',
        Mini: LeaveMini,
    },
    {
        title: 'Performance and training',
        body: '360 reviews, goals with check-ins and calibration sessions. Training runs in sessions with attendance and budgets, and certificates carry their expiry date.',
        Mini: ReviewMini,
    },
    {
        title: 'Recognition',
        body: 'Kudos and nominations on a company wall, awards from your own catalogue, and points people redeem for the rewards you offer.',
        Mini: KudosMini,
    },
    {
        title: 'Exit',
        body: 'A resignation is filed and accepted, clearance runs department by department, then the exit interview. The certificate of employment is generated as a PDF.',
        Mini: ClearanceMini,
    },
];

// How far each station steps in from the left on wide screens, in rem: one
// sweep out and back, so the spine draws an S down the page.
const indents = [0, 4.5, 8, 9, 6.5, 2, 0, 3.5];

type Point = { x: number; y: number };

/** A smooth curve through every point (Catmull-Rom, as cubic Béziers). */
function spinePath(points: Point[]): string {
    if (points.length < 2) {
        return '';
    }

    const [first] = points;
    let d = `M ${first.x} ${first.y}`;

    for (let i = 0; i < points.length - 1; i++) {
        const p0 = points[i - 1] ?? points[i];
        const p1 = points[i];
        const p2 = points[i + 1];
        const p3 = points[i + 2] ?? p2;
        const c1 = { x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6 };
        const c2 = { x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6 };
        d += ` C ${c1.x} ${c1.y}, ${c2.x} ${c2.y}, ${p2.x} ${p2.y}`;
    }

    return d;
}

export function Lifecycle() {
    const listRef = useRef<HTMLOListElement>(null);
    const nodeRefs = useRef<(HTMLSpanElement | null)[]>([]);
    const [spine, setSpine] = useState({ d: '', width: 0, height: 0 });
    const reduce = useReducedMotion();

    const { scrollYProgress } = useScroll({
        target: listRef,
        offset: ['start 62%', 'end 62%'],
    });
    const drawn = useSpring(scrollYProgress, {
        stiffness: 140,
        damping: 30,
        restDelta: 0.001,
    });

    // Measure where the nodes really are, and run the spine through them.
    useEffect(() => {
        const list = listRef.current;

        if (!list) {
            return;
        }

        const measure = () => {
            const box = list.getBoundingClientRect();
            const centres = nodeRefs.current.flatMap((node) => {
                if (!node) {
                    return [];
                }

                const r = node.getBoundingClientRect();

                return [
                    {
                        x: r.left - box.left + r.width / 2,
                        y: r.top - box.top + r.height / 2,
                    },
                ];
            });

            if (centres.length === 0) {
                return;
            }

            const head = { x: centres[0].x, y: 0 };

            setSpine({
                d: spinePath([head, ...centres]),
                width: box.width,
                height: box.height,
            });
        };

        const observer = new ResizeObserver(measure);
        observer.observe(list);
        void document.fonts?.ready.then(measure);

        return () => observer.disconnect();
    }, []);

    return (
        <section
            id="lifecycle"
            className="relative scroll-mt-20 pt-24 pb-20 lg:pt-36 lg:pb-32"
        >
            <div className="mx-auto max-w-[84rem] px-4 sm:px-6 lg:px-10">
                <header className="grid gap-6 lg:grid-cols-12 lg:items-end">
                    <h2 className="text-[clamp(2.1rem,5.4vw,4.1rem)] leading-[0.98] font-[780] tracking-[-0.03em] text-balance font-wide lg:col-span-7">
                        Each stage hands its record to the next.
                    </h2>
                    <p className="max-w-[30rem] text-[1.05rem] leading-[1.65] text-ink-soft lg:col-span-4 lg:col-start-9">
                        A hire becomes an employee without anyone retyping a
                        name. Their days and leave feed their reviews, and when
                        they leave, the clearance and the certificate draw on
                        what is already kept.
                    </p>
                </header>

                <ol
                    ref={listRef}
                    className="relative mt-16 lg:mt-24"
                    aria-label="The stages of employment in SYNAPSE"
                >
                    {spine.d && (
                        <svg
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-0 overflow-visible"
                            width={spine.width}
                            height={spine.height}
                            viewBox={`0 0 ${spine.width} ${spine.height}`}
                        >
                            <path
                                d={spine.d}
                                fill="none"
                                stroke="var(--color-line)"
                                strokeWidth={2}
                                strokeDasharray="2 7"
                                strokeLinecap="round"
                            />
                            <motion.path
                                d={spine.d}
                                fill="none"
                                stroke="url(#spine-ink)"
                                strokeWidth={3}
                                strokeLinecap="round"
                                style={{ pathLength: reduce ? 1 : drawn }}
                            />
                            <defs>
                                <linearGradient
                                    id="spine-ink"
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2={spine.height}
                                    gradientUnits="userSpaceOnUse"
                                >
                                    <stop stopColor="#37B3FF" />
                                    <stop offset="0.55" stopColor="#1C73E8" />
                                    <stop offset="1" stopColor="#0F2044" />
                                </linearGradient>
                            </defs>
                        </svg>
                    )}

                    {stations.map((station, i) => (
                        <StationRow
                            key={station.title}
                            station={station}
                            index={i}
                            reduce={reduce}
                            nodeRef={(el) => {
                                nodeRefs.current[i] = el;
                            }}
                        />
                    ))}
                </ol>
            </div>
        </section>
    );
}

function StationRow({
    station,
    index,
    reduce,
    nodeRef,
}: {
    station: Station;
    index: number;
    reduce: boolean | null;
    nodeRef: (el: HTMLSpanElement | null) => void;
}) {
    const rowRef = useRef<HTMLLIElement>(null);
    // Lit once the spine's drawing line (62% down the screen) has reached it.
    const reached = useInView(rowRef, {
        once: true,
        margin: '0px 0px -38% 0px',
    });
    const lit = reduce || reached;
    const { Mini } = station;

    return (
        <li
            ref={rowRef}
            style={{ '--indent': `${indents[index]}rem` } as CSSProperties}
            className="relative grid gap-8 py-12 pl-11 sm:pl-14 lg:ml-[var(--indent)] lg:grid-cols-[minmax(0,27rem)_1fr] lg:items-center lg:gap-12 lg:py-16 lg:pl-16"
        >
            <span
                ref={nodeRef}
                aria-hidden="true"
                className="absolute top-[3.55rem] left-0 grid size-5 -translate-x-1/2 place-items-center sm:left-0 lg:top-[4.6rem]"
            >
                <span
                    className={`size-5 rounded-full ring-[3px] ring-ice transition-[background-color,transform] duration-500 ${
                        lit ? 'scale-100 bg-signal' : 'scale-75 bg-line'
                    }`}
                />
                <span
                    className={`absolute inset-0 rounded-full bg-sky/40 transition-[transform,opacity] duration-700 ${
                        lit ? 'scale-[2.1] opacity-0' : 'scale-50 opacity-100'
                    }`}
                />
            </span>

            <div>
                <p className="text-[0.9rem] font-semibold text-signal tabular">
                    {String(index + 1).padStart(2, '0')}
                </p>
                <h3 className="mt-1.5 text-[clamp(1.6rem,3.2vw,2.35rem)] leading-[1.02] font-[760] tracking-[-0.025em] text-ink font-semiwide">
                    {station.title}
                </h3>
                <p className="mt-4 max-w-[27rem] text-[1rem] leading-[1.65] text-ink-soft">
                    {station.body}
                </p>
            </div>

            <div
                className="lg:justify-self-center lg:pl-4 xl:pl-10"
                aria-hidden="true"
            >
                <Mini />
            </div>
        </li>
    );
}
