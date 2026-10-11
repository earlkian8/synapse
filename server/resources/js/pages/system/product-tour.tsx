import { Head, router, usePage } from '@inertiajs/react';
import {
    CircleCheck,
    DoorOpen,
    PlayCircle,
    Route,
    SkipForward,
} from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    StatTiles,
    TableCard,
} from '@/components/data-table';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

type Platform = 'web' | 'mobile';

type TourStepRow = {
    id: string;
    label: string;
    shown: number;
    reached: number;
    left_here: number;
};

type ProductTourPageProps = {
    totals: {
        started: number;
        people: number;
        completed: number;
        skipped: number;
        abandoned: number;
        running: number;
        by_mode: Record<'first' | 'whats_new' | 'replay', number>;
    };
    steps: TourStepRow[];
    filters: { platform: Platform; version: number; months: number };
    versions: number[];
    periods: number[];
};

const PATH = '/system/product-tour';

/** The one bar colour: the brand teal stepped for contrast on either surface. */
const BAR = 'bg-[#08959b] dark:bg-[#0aa7ad]';

function runs(count: number): string {
    return `${count} ${count === 1 ? 'run' : 'runs'}`;
}

function percent(part: number, whole: number): string {
    return whole > 0 ? `${Math.round((part / whole) * 100)}%` : '—';
}

/**
 * System → Product Tour (ADR 0080): how this workspace's people move through
 * the tour, on the web or in the app — how many start, finish, skip or leave
 * it, and, stop by stop, how many reach each one and where they stop.
 */
export default function ProductTourAnalytics() {
    const { totals, steps, filters, versions, periods } =
        usePage<ProductTourPageProps>().props;

    const go = (next: Partial<ProductTourPageProps['filters']>) => {
        const merged = { ...filters, ...next };

        router.get(
            PATH,
            {
                platform: merged.platform,
                months: merged.months,
                // A platform change starts from its current version.
                ...(next.platform ? {} : { version: merged.version }),
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const left = totals.skipped + totals.abandoned;

    return (
        <>
            <Head title="Product Tour" />

            <PageBody>
                <PageHeader
                    title="Product Tour"
                    description="How your people move through the tour of the app — who finishes it, and where the ones who leave stop."
                />

                <ListToolbar
                    filtered={
                        filters.platform !== 'web' ||
                        filters.months !== 3 ||
                        filters.version !== versions[0]
                    }
                    onReset={() =>
                        router.get(PATH, {}, { preserveScroll: true })
                    }
                >
                    <FilterSelect
                        label="Where"
                        value={filters.platform}
                        onChange={(value) =>
                            go({ platform: value as Platform })
                        }
                        options={[
                            { value: 'web', label: 'Web app' },
                            { value: 'mobile', label: 'Mobile app' },
                        ]}
                        className="w-36"
                    />
                    <FilterSelect
                        label="Tour version"
                        value={String(filters.version)}
                        onChange={(value) => go({ version: Number(value) })}
                        options={versions.map((version, index) => ({
                            value: String(version),
                            label:
                                index === 0
                                    ? `Version ${version} (current)`
                                    : `Version ${version}`,
                        }))}
                        className="w-48"
                    />
                    <FilterSelect
                        label="Period"
                        value={String(filters.months)}
                        onChange={(value) => go({ months: Number(value) })}
                        options={periods.map((months) => ({
                            value: String(months),
                            label:
                                months === 1
                                    ? 'Last month'
                                    : `Last ${months} months`,
                        }))}
                        className="w-40"
                    />
                </ListToolbar>

                <StatTiles
                    tiles={[
                        {
                            key: 'started',
                            label: 'Runs started',
                            value: totals.started,
                            hint: `${totals.people} ${totals.people === 1 ? 'person' : 'people'}`,
                            icon: PlayCircle,
                            accent: 'sky',
                        },
                        {
                            key: 'completed',
                            label: 'Walked to the end',
                            value: percent(totals.completed, totals.started),
                            hint: runs(totals.completed),
                            icon: CircleCheck,
                            accent: 'emerald',
                        },
                        {
                            key: 'skipped',
                            label: 'Skipped',
                            value: percent(totals.skipped, totals.started),
                            hint: runs(totals.skipped),
                            icon: SkipForward,
                            accent: 'amber',
                        },
                        {
                            key: 'abandoned',
                            label: 'Left part-way',
                            value: percent(totals.abandoned, totals.started),
                            hint: 'closed with no answer',
                            icon: DoorOpen,
                            accent: 'rose',
                        },
                    ]}
                />

                <p className="text-sm text-muted-foreground">
                    {totals.by_mode.first} first{' '}
                    {totals.by_mode.first === 1 ? 'run' : 'runs'},{' '}
                    {totals.by_mode.whats_new} “What’s new”,{' '}
                    {totals.by_mode.replay}{' '}
                    {totals.by_mode.replay === 1 ? 'replay' : 'replays'}
                    {totals.running > 0 && `, ${totals.running} under way`}.
                </p>

                <TableCard
                    title="Stop by stop"
                    description="Reached is of the runs that showed the stop — a role that cannot open a section never sees its stop. Left here counts runs skipped or abandoned on it."
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10 text-right">
                                    #
                                </TableHead>
                                <TableHead>Stop</TableHead>
                                <TableHead className="text-right">
                                    Shown
                                </TableHead>
                                <TableHead className="w-[38%]">
                                    Reached
                                </TableHead>
                                <TableHead className="text-right">
                                    Left here
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {steps.length === 0 && (
                                <EmptyTableRow
                                    colSpan={5}
                                    icon={Route}
                                    title="Nobody has taken this tour yet"
                                    description="Runs appear here as people are offered the tour, take a “What’s new”, or replay it from Help."
                                />
                            )}
                            {steps.map((step, index) => (
                                <TableRow
                                    key={step.id}
                                    title={`${step.label}: reached in ${step.reached} of ${step.shown} runs; ${step.left_here} left here`}
                                >
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {index + 1}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {step.label}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {step.shown}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={cn(
                                                        'h-full rounded-full',
                                                        BAR,
                                                    )}
                                                    style={{
                                                        width:
                                                            step.shown > 0 &&
                                                            step.reached > 0
                                                                ? `${Math.max(2, (step.reached / step.shown) * 100)}%`
                                                                : '0%',
                                                    }}
                                                />
                                            </div>
                                            <span className="w-10 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                                                {percent(
                                                    step.reached,
                                                    step.shown,
                                                )}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right tabular-nums',
                                            step.left_here > 0 &&
                                                left > 0 &&
                                                step.left_here / left >= 0.25 &&
                                                'font-semibold text-rose-600 dark:text-rose-400',
                                        )}
                                    >
                                        {step.left_here}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>
            </PageBody>
        </>
    );
}

ProductTourAnalytics.layout = {
    breadcrumbs: [{ title: 'Product Tour', href: PATH }],
};
