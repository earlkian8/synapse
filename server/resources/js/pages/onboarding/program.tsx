import { Head, Link, router, usePage } from '@inertiajs/react';
import { ListChecks, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    TablePagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { CasesTable } from '@/features/onboarding/components/cases-table';
import { ConfirmDialog } from '@/features/onboarding/components/confirm-dialog';
import { OnboardingStatsCards } from '@/features/onboarding/components/onboarding-stats';
import { StartOnboardingDialog } from '@/features/onboarding/components/start-onboarding-dialog';
import {
    DEFAULT_FILTERS,
    EMPLOYMENT_TYPE_LABELS,
    STATUS_FILTERS,
} from '@/features/onboarding/constants';
import { useCaseFilters } from '@/features/onboarding/hooks/use-case-filters';
import { onboardingRoutes } from '@/features/onboarding/routes';
import type {
    OnboardingCase,
    ProgramPageProps,
} from '@/features/onboarding/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/**
 * Onboarding, second level: the people one program is onboarding (or, under
 * "Unassigned", the cases on no program). A person opens their checklist.
 */
export default function OnboardingProgramPage() {
    const { program, cases, stats, options, can, filters } =
        usePage<ProgramPageProps>().props;
    const url = onboardingRoutes.program(program?.hashid ?? null);
    const table = useCaseFilters(url, filters);

    const [startOpen, setStartOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const name = program?.name ?? 'Unassigned';
    const filtered =
        filters.search !== '' ||
        filters.status !== DEFAULT_FILTERS.status ||
        filters.department !== null;

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const lifecycle = (item: OnboardingCase, action: 'complete' | 'reopen') =>
        router.patch(
            onboardingRoutes.status(item.hashid),
            { action },
            { preserveScroll: true },
        );

    const who = (item: OnboardingCase) =>
        item.employee?.full_name ?? 'this employee';

    const cancel = (item: OnboardingCase) =>
        askConfirm({
            title: `Cancel onboarding for ${who(item)}?`,
            description:
                'The case is marked cancelled and its checklist stops counting as overdue. You can reopen it later.',
            confirmLabel: 'Cancel onboarding',
            run: () =>
                router.patch(
                    onboardingRoutes.status(item.hashid),
                    { action: 'cancel' },
                    withProcessing,
                ),
        });

    const remove = (item: OnboardingCase) =>
        askConfirm({
            title: `Delete onboarding for ${who(item)}?`,
            description:
                'This permanently removes the case and its checklist. It cannot be undone.',
            confirmLabel: 'Delete onboarding',
            run: () =>
                router.delete(
                    onboardingRoutes.destroy(item.hashid),
                    withProcessing,
                ),
        });

    const targeting = program
        ? [
              program.department?.name,
              program.employment_type
                  ? EMPLOYMENT_TYPE_LABELS[program.employment_type]
                  : null,
          ].filter(Boolean)
        : [];

    return (
        <>
            <Head title={`${name} — Onboarding`} />

            <PageBody>
                <PageHeader
                    back={{
                        href: onboardingRoutes.index,
                        label: 'Back to all programs',
                    }}
                    title={name}
                    badges={
                        <>
                            {program?.is_default && (
                                <span className="rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-2 py-0.5 text-[11px] font-medium text-[#0ABFBF]">
                                    Default
                                </span>
                            )}
                            {program && !program.is_active && (
                                <span className="rounded-full border border-border bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                    Inactive
                                </span>
                            )}
                        </>
                    }
                    description={
                        <span className="line-clamp-2">
                            {program
                                ? [
                                      targeting.length > 0
                                          ? `For ${targeting.join(' · ')}`
                                          : 'For all new hires',
                                      `${program.tasks_count} task${program.tasks_count === 1 ? '' : 's'} per checklist`,
                                      program.description,
                                  ]
                                      .filter(Boolean)
                                      .join(' · ')
                                : 'Onboarding started without a program, or whose program was deleted.'}
                        </span>
                    }
                    actions={
                        <>
                            {can.managePrograms && program && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={onboardingRoutes.programs}>
                                        <ListChecks className="size-4" />
                                        Edit program
                                    </Link>
                                </Button>
                            )}
                            {can.manage && program?.is_active && (
                                <Button
                                    size="sm"
                                    onClick={() => setStartOpen(true)}
                                >
                                    <Plus className="size-4" />
                                    Start onboarding
                                </Button>
                            )}
                        </>
                    }
                />

                <OnboardingStatsCards stats={stats} />

                <ListToolbar filtered={filtered} onReset={table.reset}>
                    <SearchInput
                        value={filters.search}
                        onSearch={table.setSearch}
                        placeholder="Search by name or no.…"
                        label="Search employees"
                    />
                    <FilterSelect
                        label="Filter by department"
                        value={
                            filters.department
                                ? String(filters.department)
                                : 'all'
                        }
                        onChange={(value) =>
                            table.setDepartment(
                                value === 'all' ? null : Number(value),
                            )
                        }
                        options={[
                            { value: 'all', label: 'All departments' },
                            ...options.departments.map((department) => ({
                                value: String(department.id),
                                label: department.name,
                            })),
                        ]}
                        className="w-44"
                    />
                    <FilterSelect
                        label="Filter by status"
                        value={filters.status}
                        onChange={table.setStatus}
                        options={STATUS_FILTERS}
                        className="w-36"
                    />
                </ListToolbar>

                <CasesTable
                    cases={cases.data}
                    filters={filters}
                    canManage={can.manage}
                    filtered={filtered}
                    onSort={table.toggleSort}
                    onComplete={(item) => lifecycle(item, 'complete')}
                    onReopen={(item) => lifecycle(item, 'reopen')}
                    onCancel={cancel}
                    onDelete={remove}
                />

                <TablePagination
                    meta={cases.meta}
                    perPage={filters.per_page}
                    onPage={table.setPage}
                    onPerPage={table.setPerPage}
                />
            </PageBody>

            <StartOnboardingDialog
                employees={options.employees}
                programs={options.programs}
                programId={program?.id ?? null}
                open={startOpen}
                onOpenChange={setStartOpen}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

OnboardingProgramPage.layout = (props: ProgramPageProps) => ({
    breadcrumbs: [
        { title: 'Onboarding', href: onboardingRoutes.index },
        {
            title: props.program?.name ?? 'Unassigned',
            href: onboardingRoutes.program(props.program?.hashid ?? null),
        },
    ],
});
