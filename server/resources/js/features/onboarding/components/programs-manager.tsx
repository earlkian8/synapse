import { router } from '@inertiajs/react';
import { LayoutGrid, List, ListChecks, Plus, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useProgramsView } from '../hooks/use-programs-view';
import { onboardingRoutes } from '../routes';
import type { OnboardingProgram, ProgramsPageProps } from '../types';
import { ConfirmDialog } from './confirm-dialog';
import { ProgramCard } from './program-card';
import { ProgramFormDialog } from './program-form-dialog';
import { ProgramTable } from './program-table';

type Props = ProgramsPageProps & {
    /** What sits beside the actions — the screen's title, or a step's section heading. */
    heading?: ReactNode;
};

/**
 * The onboarding checklists new hires start with, with every action Company
 * Setup offers on them — create, edit (tasks, audience, default) and delete.
 * Rendered by the Onboarding Programs screen and by the setup wizard's step for
 * it, from the same props.
 */
export function OnboardingProgramsManager({
    programs,
    options,
    can,
    heading,
}: Props) {
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
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                {heading}

                {can.managePrograms && (
                    <Button
                        size="sm"
                        className="lg:ml-auto"
                        onClick={openCreate}
                    >
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
                                {programs.length === 1 && !search ? '' : 's'}
                            </span>
                            <ToggleGroup
                                type="single"
                                value={view}
                                onValueChange={(value) =>
                                    value && changeView(value as typeof view)
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

            <ProgramFormDialog
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
