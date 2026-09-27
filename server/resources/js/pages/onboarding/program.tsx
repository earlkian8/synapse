import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ListChecks, Plus, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CasesTable } from '@/features/onboarding/components/cases-table';
import { ConfirmDialog } from '@/features/onboarding/components/confirm-dialog';
import { OnboardingPagination } from '@/features/onboarding/components/onboarding-pagination';
import { OnboardingStatsCards } from '@/features/onboarding/components/onboarding-stats';
import { SearchInput } from '@/features/onboarding/components/search-input';
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

            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-9 shrink-0"
                            asChild
                        >
                            <Link
                                href={onboardingRoutes.index}
                                aria-label="Back to all programs"
                            >
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="truncate text-xl font-semibold tracking-tight">
                                    {name}
                                </h1>
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
                            </div>
                            <p className="mt-0.5 line-clamp-2 text-sm text-muted-foreground">
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
                            </p>
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
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
                    </div>
                </div>

                <OnboardingStatsCards stats={stats} />

                <div className="flex flex-col gap-3">
                    {/* Filters */}
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchInput
                            value={filters.search}
                            onSearch={table.setSearch}
                            placeholder="Search by name or no.…"
                            label="Search employees"
                        />

                        <Select
                            value={
                                filters.department
                                    ? String(filters.department)
                                    : 'all'
                            }
                            onValueChange={(value) =>
                                table.setDepartment(
                                    value === 'all' ? null : Number(value),
                                )
                            }
                        >
                            <SelectTrigger
                                className="h-9 w-[170px]"
                                aria-label="Filter by department"
                            >
                                <SelectValue placeholder="Department" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All departments
                                </SelectItem>
                                {options.departments.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.status}
                            onValueChange={table.setStatus}
                        >
                            <SelectTrigger
                                className="h-9 w-[150px]"
                                aria-label="Filter by status"
                            >
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                {STATUS_FILTERS.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {filtered && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={table.reset}
                                className="text-muted-foreground"
                            >
                                <RotateCcw className="size-4" />
                                Reset
                            </Button>
                        )}
                    </div>

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

                    <OnboardingPagination
                        meta={cases.meta}
                        perPage={filters.per_page}
                        onPage={table.setPage}
                        onPerPage={table.setPerPage}
                    />
                </div>
            </div>

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
