import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { AssignScheduleDialog } from '@/features/attendance/components/assign-schedule-dialog';
import { rosterRoutes } from '../routes';
import type { RosterFilters, RosterPageProps } from '../types';
import { RosterEntryDialog } from './roster-entry-dialog';
import type { RosterTarget } from './roster-entry-dialog';
import { AssignScheduleButton, RosterGrid } from './roster-grid';
import { RosterToolbar } from './roster-toolbar';

/**
 * The shift roster (ADR 0037) with every action Attendance offers on it (ADR
 * 0090) — move between weeks, narrow to a department or a person, override one
 * day's shift, and put people on a schedule from a date.
 */
export function RosterManager({
    roster,
    options,
    can,
    filters,
}: RosterPageProps) {
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

            router.get(rosterRoutes.index, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['roster', 'filters'],
            });
        },
        [filters],
    );

    return (
        <>
            <RosterToolbar
                actions={
                    can.manage && (
                        <AssignScheduleButton
                            onClick={() => setAssignOpen(true)}
                        />
                    )
                }
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
