import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Trophy } from 'lucide-react';
import { useMemo, useState } from 'react';
import { GiveAwardDialog } from '@/features/awards/components/give-award-dialog';
import { NominationCard } from '@/features/awards/components/nomination-card';
import { SIGNAL_COLORS } from '@/features/awards/constants';
import { awardsRoutes } from '@/features/awards/routes';
import type {
    AwardNominationsPageProps,
    AwardPreset,
    AwardTypeOption,
    NominationSignalKey,
} from '@/features/awards/types';

const LEGEND: { key: NominationSignalKey; label: string }[] = [
    { key: 'performance', label: 'Performance' },
    { key: 'attendance', label: 'Attendance' },
    { key: 'training', label: 'Training' },
    { key: 'tenure', label: 'Tenure' },
    { key: 'gap', label: 'Recognition gap' },
    { key: 'forecast', label: 'ML forecast' },
];

export default function AwardNominations() {
    const { board, employees, ai_available, can } =
        usePage<AwardNominationsPageProps>().props;

    const [preset, setPreset] = useState<AwardPreset | null>(null);
    const [giveOpen, setGiveOpen] = useState(false);

    // The dialog's award-type options, derived from the board itself.
    const types = useMemo<AwardTypeOption[]>(
        () =>
            board.map((entry) => ({
                id: entry.type.id,
                name: entry.type.name,
                color: entry.type.color,
            })),
        [board],
    );

    const openGive = (employeeId: number, typeId: number) => {
        setPreset({ employeeId, typeId });
        setGiveOpen(true);
    };

    return (
        <>
            <Head title="Awards — Nomination Board" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={awardsRoutes.index}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All recognitions
                </Link>

                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Nomination board
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        Who deserves each award right now — ranked from the
                        signals the ERP already tracks. Each award weighs the
                        signals that match what it celebrates; expand a nominee
                        to see exactly why they rank.
                    </p>
                </div>

                {/* Signal legend — the same hues every breakdown uses */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
                    {LEGEND.map((signal) => (
                        <span
                            key={signal.key}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className="size-2 rounded-full"
                                style={{
                                    backgroundColor: SIGNAL_COLORS[signal.key],
                                }}
                            />
                            {signal.label}
                        </span>
                    ))}
                </div>

                {board.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                        {board.map((nomination) => (
                            <NominationCard
                                key={nomination.type.id}
                                nomination={nomination}
                                canManage={can.manage}
                                onGive={openGive}
                            />
                        ))}
                    </div>
                )}
            </div>

            <GiveAwardDialog
                open={giveOpen}
                onOpenChange={setGiveOpen}
                types={types}
                employees={employees}
                award={null}
                preset={preset}
                aiAvailable={ai_available}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Trophy className="size-5" />
            </span>
            <p className="text-sm font-medium">No active award types</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Add award types under Company Setup → Award Types and the board
                will rank who deserves each one.
            </p>
        </div>
    );
}

AwardNominations.layout = {
    breadcrumbs: [
        { title: 'Awards', href: '/awards' },
        { title: 'Nominations', href: '/awards/nominations' },
    ],
};
