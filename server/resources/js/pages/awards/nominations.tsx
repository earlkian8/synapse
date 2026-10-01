import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { GiveAwardDialog } from '@/features/awards/components/give-award-dialog';
import { NominationTypesTable } from '@/features/awards/components/nomination-types-table';
import { NomineesTable } from '@/features/awards/components/nominees-table';
import { awardColorStyle, SIGNAL_COLORS } from '@/features/awards/constants';
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

/** The award type the URL names (`?type=`), if any. */
function typeFromUrl(url: string): number | null {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const value = Number(new URLSearchParams(query).get('type'));

    return Number.isInteger(value) && value > 0 ? value : null;
}

/**
 * The nomination board in two levels, like the rest of the workforce modules:
 * every award type and who leads it, then — `?type=` — one award's ranked
 * shortlist with the transparent breakdown behind each score.
 */
export default function AwardNominations() {
    const { props, url } = usePage<AwardNominationsPageProps>();
    const { board, employees, ai_available, can } = props;
    const typeId = typeFromUrl(url);
    const selected = board.find((entry) => entry.type.id === typeId) ?? null;

    const openType = (id: number | null) =>
        router.get(awardsRoutes.nominations, id === null ? {} : { type: id }, {
            preserveState: true,
            preserveScroll: false,
        });

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

            <PageBody>
                {selected ? (
                    <PageHeader
                        back={{
                            href: awardsRoutes.nominations,
                            label: 'Back to every award',
                        }}
                        title={selected.type.name}
                        badges={
                            <span
                                className="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                style={awardColorStyle(selected.type.color)}
                                title={selected.profile.hint}
                            >
                                {selected.profile.label}
                            </span>
                        }
                        description={
                            selected.type.description ??
                            'Who deserves this award right now, ranked from the signals the ERP tracks.'
                        }
                    />
                ) : (
                    <PageHeader
                        back={{
                            href: awardsRoutes.index,
                            label: 'Back to all recognitions',
                        }}
                        title="Nomination board"
                        description="Who deserves each award right now — ranked from the signals the ERP already tracks. Open an award to see its shortlist and why each person ranks."
                    />
                )}

                {/* Signal legend — the same hues every bar uses */}
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

                {selected ? (
                    <NomineesTable
                        key={selected.type.id}
                        nomination={selected}
                        canManage={can.manage}
                        onGive={openGive}
                    />
                ) : (
                    <NominationTypesTable
                        board={board}
                        canManage={can.manage}
                        onOpen={openType}
                        onGive={openGive}
                    />
                )}
            </PageBody>

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

AwardNominations.layout = {
    breadcrumbs: [
        { title: 'Awards', href: '/awards' },
        { title: 'Nominations', href: '/awards/nominations' },
    ],
};
