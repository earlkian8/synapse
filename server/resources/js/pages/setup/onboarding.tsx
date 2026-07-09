import { Head, router, usePage } from '@inertiajs/react';
import { LayoutGrid, List, ListChecks, Plus, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ConfirmDialog } from '@/features/onboarding/components/confirm-dialog';
import { ProgramCard } from '@/features/onboarding/components/program-card';
import { ProgramFormSheet } from '@/features/onboarding/components/program-form-sheet';
import { ProgramTable } from '@/features/onboarding/components/program-table';
import { useProgramsView } from '@/features/onboarding/hooks/use-programs-view';
import { onboardingRoutes } from '@/features/onboarding/routes';
import type {
    OnboardingProgram,
    ProgramsPageProps,
} from '@/features/onboarding/types';

export default function SetupOnboarding() {
    const { programs, options, can } = usePage<ProgramsPageProps>().props;
    const { view, changeView } = useProgramsView();

    const [search, setSearch] = useState('');
    const [formProgram, setFormProgram] = useState<OnboardingProgram | null>(
        null,
    );
    const [formOpen, setFormOpen] = useState(false);
    const [target, setTarget] = useState<OnboardingProgram | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        if (!term) {
            return programs;
        }

        return programs.filter((program) =>
            [program.name, program.description, program.department?.name]
                .filter(Boolean)
                .some((field) => field!.toLowerCase().includes(term)),
        );
    }, [programs, search]);

    const openCreate = () => {
        setFormProgram(null);
        setFormOpen(true);
    };

    const openEdit = (program: OnboardingProgram) => {
        setFormProgram(program);
        setFormOpen(true);
    };

    const askDelete = (program: OnboardingProgram) => {
        setTarget(program);
        setConfirmOpen(true);
    };

    const remove = () => {
        if (!target) {
            return;
        }

        router.delete(onboardingRoutes.program(target.hashid), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmOpen(false);
            },
        });
    };

    return (
        <>
            <Head title="Onboarding Programs" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Onboarding Programs
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Reusable checklists that seed each new hire's
                            onboarding. Applied automatically when someone is
                            hired.
                        </p>
                    </div>

                    {can.managePrograms && (
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            New program
                        </Button>
                    )}
                </div>

                {programs.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="relative w-full sm:w-72">
                                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search programs…"
                                    className="pl-9"
                                    aria-label="Search programs"
                                />
                                {search && (
                                    <button
                                        type="button"
                                        onClick={() => setSearch('')}
                                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                                        aria-label="Clear search"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </div>

                            <div className="flex items-center gap-3">
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {search
                                        ? `${visible.length} of ${programs.length}`
                                        : programs.length}{' '}
                                    program
                                    {programs.length === 1 && !search
                                        ? ''
                                        : 's'}
                                </span>
                                <ToggleGroup
                                    type="single"
                                    value={view}
                                    onValueChange={(value) =>
                                        value &&
                                        changeView(value as typeof view)
                                    }
                                    variant="outline"
                                    size="sm"
                                    aria-label="Switch layout"
                                >
                                    <ToggleGroupItem
                                        value="table"
                                        aria-label="Table view"
                                    >
                                        <List className="size-4" />
                                    </ToggleGroupItem>
                                    <ToggleGroupItem
                                        value="grid"
                                        aria-label="Card view"
                                    >
                                        <LayoutGrid className="size-4" />
                                    </ToggleGroupItem>
                                </ToggleGroup>
                            </div>
                        </div>

                        {visible.length === 0 ? (
                            <NoResults />
                        ) : view === 'grid' ? (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {visible.map((program) => (
                                    <ProgramCard
                                        key={program.id}
                                        program={program}
                                        canManage={can.managePrograms}
                                        onEdit={openEdit}
                                        onDelete={askDelete}
                                    />
                                ))}
                            </div>
                        ) : (
                            <ProgramTable
                                programs={visible}
                                canManage={can.managePrograms}
                                onEdit={openEdit}
                                onDelete={askDelete}
                            />
                        )}
                    </div>
                )}
            </div>

            <ProgramFormSheet
                program={formProgram}
                departments={options.departments}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={`Delete "${target?.name}"?`}
                description="In-flight onboarding keeps its tasks; only the template is removed."
                confirmLabel="Delete program"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <ListChecks className="size-5" />
            </span>
            <p className="text-sm font-medium">No programs yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Create a program to define the checklist new hires are onboarded
                with.
            </p>
        </div>
    );
}

function NoResults() {
    return (
        <div className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-12 text-center text-sm text-muted-foreground dark:border-sidebar-border">
            No programs match your search.
        </div>
    );
}

SetupOnboarding.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Onboarding Programs', href: '/setup/onboarding' },
    ],
};
