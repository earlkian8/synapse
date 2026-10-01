import { router } from '@inertiajs/react';
import { LayoutGrid, List, ListChecks, Plus, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useProgramsView } from '../hooks/use-programs-view';
import { offboardingRoutes } from '../routes';
import type { OffboardingProgram, ProgramsPageProps } from '../types';
import { ConfirmDialog } from './confirm-dialog';
import { ProgramCard } from './program-card';
import { ProgramFormSheet } from './program-form-sheet';
import { ProgramTable } from './program-table';

type Props = ProgramsPageProps & {
    /** What sits beside the actions — the screen's title, or a step's section heading. */
    heading?: ReactNode;
};

/**
 * The clearance templates exits run through, with every action Company Setup
 * offers on them — create, edit (items, routing, audience, default) and delete.
 * Rendered by the Offboarding Programs screen and by the setup wizard's step for
 * it, from the same props.
 */
export function OffboardingProgramsManager({
    programs,
    options,
    can,
    heading,
}: Props) {
    const { view, changeView } = useProgramsView();

    const [search, setSearch] = useState('');
    const [formProgram, setFormProgram] = useState<OffboardingProgram | null>(
        null,
    );
    const [formOpen, setFormOpen] = useState(false);
    const [target, setTarget] = useState<OffboardingProgram | null>(null);
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

    const openEdit = (program: OffboardingProgram) => {
        setFormProgram(program);
        setFormOpen(true);
    };

    const askDelete = (program: OffboardingProgram) => {
        setTarget(program);
        setConfirmOpen(true);
    };

    const remove = () => {
        if (!target) {
            return;
        }

        router.delete(offboardingRoutes.program(target.hashid), {
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
                        New template
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
                                placeholder="Search templates…"
                                className="pl-9"
                                aria-label="Search templates"
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
                                template
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
                description="In-flight exits keep their clearance items; only the template is removed."
                confirmLabel="Delete template"
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
            <p className="text-sm font-medium">No templates yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Create a template to define the clearance checklist departing
                employees are offboarded with.
            </p>
        </div>
    );
}

function NoResults() {
    return (
        <div className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-12 text-center text-sm text-muted-foreground dark:border-sidebar-border">
            No templates match your search.
        </div>
    );
}
