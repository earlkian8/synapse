import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { AssignScheduleDialog } from '@/features/attendance/components/assign-schedule-dialog';
import { rosterRoutes } from '../routes';
import type { RosterFilters, RosterPageProps } from '../types';
import { RosterEntryDialog } from './roster-entry-dialog';
import type { RosterTarget } from './roster-entry-dialog';
import { AssignScheduleButton, RosterGrid } from './roster-grid';
import { RosterToolbar } from './roster-toolbar';

type Props = RosterPageProps & {
    /** What sits beside the actions — the screen's title, or a step's section heading. */
    heading?: ReactNode;
    /**
     * Where a change of week, department or search is read from, and which props
     * it refreshes. The Shift Roster screen reloads its own; a wizard step
     * reloads the step it is on.
     */
    reload?: { url: string; only: string[] };
};

/**
 * The shift roster (ADR 0037) with every action Company Setup offers on it —
 * move between weeks, narrow to a department or a person, override one day's
 * shift, and put people on a schedule from a date. Rendered by the Shift Roster
 * screen and by the setup wizard's step for it, from the same props.
 */
export function RosterManager({
    roster,
    options,
    can,
    filters,
    heading,
    reload = { url: rosterRoutes.index, only: ['roster', 'filters'] },
}: Props) {
    const [target, setTarget] = useState<RosterTarget | null>(null);
    const [entryOpen, setEntryOpen] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);

    const apply = useCallback(
        (overrides: Partial<RosterFilters>) => {
            const next = { ...filters, ...overrides };
            const query: Record<string, string | number> = { date: next.date };

            if (next.search.trim()) {
                query.search = next.search.trim();
            }

            if (next.department) {
                query.department = next.department;
            }

            router.get(reload.url, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: reload.only,
            });
        },
        [filters, reload.url, reload.only],
    );

    return (
        <>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                {heading}
                {can.manage && (
                    <div className="sm:ml-auto">
                        <AssignScheduleButton
                            onClick={() => setAssignOpen(true)}
                        />
                    </div>
                )}
            </div>

            <RosterToolbar
                filters={filters}
                departments={options.departments}
                onDate={(date) => apply({ date })}
                onSearch={(search) => apply({ search })}
                onDepartment={(department) => apply({ department })}
            />

            <RosterGrid
                roster={roster}
                canManage={can.manage}
                onPickCell={(row, cell) => {
                    setTarget({ employee: row.employee, cell });
                    setEntryOpen(true);
                }}
            />

            <RosterEntryDialog
                target={target}
                schedules={options.schedules}
                open={entryOpen}
                onOpenChange={setEntryOpen}
            />

            <AssignScheduleDialog
                employees={roster.rows.map((row) => row.employee)}
                schedules={options.schedules}
                policies={options.policies}
                action={rosterRoutes.assign}
                open={assignOpen}
                onOpenChange={setAssignOpen}
            />
        </>
    );
}
