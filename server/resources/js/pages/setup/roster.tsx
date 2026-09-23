import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { AssignScheduleDialog } from '@/features/attendance/components/assign-schedule-dialog';
import { RosterEntryDialog } from '@/features/roster/components/roster-entry-dialog';
import type { RosterTarget } from '@/features/roster/components/roster-entry-dialog';
import {
    AssignScheduleButton,
    RosterGrid,
} from '@/features/roster/components/roster-grid';
import { RosterToolbar } from '@/features/roster/components/roster-toolbar';
import { rosterRoutes } from '@/features/roster/routes';
import type { RosterFilters, RosterPageProps } from '@/features/roster/types';

export default function SetupRoster() {
    const { roster, options, can, filters } = usePage<RosterPageProps>().props;

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
            <Head title="Shift Roster" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Shift Roster
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Who is due to work what, day by day, and why that
                            shift applies. Attendance judges each day against
                            it.
                        </p>
                    </div>
                    {can.manage && (
                        <AssignScheduleButton
                            onClick={() => setAssignOpen(true)}
                        />
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
            </div>

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

SetupRoster.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Shift Roster', href: '/setup/roster' },
    ],
};
