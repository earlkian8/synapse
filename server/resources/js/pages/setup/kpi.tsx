import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CalendarRange,
    Layers,
    Pencil,
    Plus,
    Ruler,
    Target,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { CriterionModal } from '@/features/kpi-config/components/criterion-modal';
import { FrameworkModal } from '@/features/kpi-config/components/framework-modal';
import { PeriodModal } from '@/features/kpi-config/components/period-modal';
import { RatingScaleModal } from '@/features/kpi-config/components/rating-scale-modal';
import { kpiConfigRoutes } from '@/features/kpi-config/routes';
import type {
    KpiCriterion,
    KpiSetupPageProps,
    RatingScaleOption,
} from '@/features/kpi-config/types';
import { RatingLadder } from '@/features/performance/components/rating-ladder';
import { PeriodStatusBadge } from '@/features/performance/components/status-badge';
import { formatDate } from '@/features/performance/constants';
import type {
    EvaluationPeriodOption,
    ReviewTemplateOption,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

type Tab = 'frameworks' | 'scales' | 'criteria' | 'cycles';

const TABS: { value: Tab; label: string; icon: typeof Layers }[] = [
    { value: 'frameworks', label: 'Frameworks', icon: Layers },
    { value: 'scales', label: 'Rating scales', icon: Ruler },
    { value: 'criteria', label: 'Criteria', icon: Target },
    { value: 'cycles', label: 'Review cycles', icon: CalendarRange },
];

export default function SetupKpi() {
    const {
        templates,
        archivedTemplates,
        scales,
        archivedScales,
        criteria,
        archivedCriteria,
        periods,
        archivedPeriods,
        audiences,
        tones,
        defaultBands,
        can,
    } = usePage<KpiSetupPageProps>().props;

    const [tab, setTab] = useState<Tab>('frameworks');

    const [frameworkForm, setFrameworkForm] = useState<{
        open: boolean;
        template: ReviewTemplateOption | null;
    }>({ open: false, template: null });
    const [scaleForm, setScaleForm] = useState<{
        open: boolean;
        scale: RatingScaleOption | null;
    }>({ open: false, scale: null });
    const [criterionForm, setCriterionForm] = useState<{
        open: boolean;
        criterion: KpiCriterion | null;
    }>({ open: false, criterion: null });
    const [periodForm, setPeriodForm] = useState<{
        open: boolean;
        period: EvaluationPeriodOption | null;
    }>({ open: false, period: null });

    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const archiveAction = (
        label: string,
        note: string,
        run: () => void,
    ): ConfirmConfig => ({
        title: `Archive “${label}”?`,
        description: note,
        confirmLabel: 'Archive',
        run,
    });

    const deleteAction = (
        label: string,
        note: string,
        run: () => void,
    ): ConfirmConfig => ({
        title: `Permanently delete “${label}”?`,
        description: note,
        confirmLabel: 'Delete permanently',
        run,
    });

    return (
        <>
            <Head title="Performance framework" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Performance framework
                    </h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        How this company reviews its people: the frameworks
                        appraisals are conducted against, the scales they
                        measure on, the criteria they draw from, and the cycles
                        they run in.
                    </p>
                </div>

                {/* Tabs */}
                <div
                    className="flex flex-wrap gap-1 rounded-xl border border-sidebar-border/70 bg-card p-1 dark:border-sidebar-border"
                    role="tablist"
                    aria-label="Performance configuration"
                >
                    {TABS.map(({ value, label, icon: Icon }) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            onClick={() => setTab(value)}
                            className={cn(
                                'inline-flex min-h-9 items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                                tab === value
                                    ? 'bg-[#0F2044] text-white shadow-sm'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )}
                        >
                            <Icon className="size-4" />
                            {label}
                        </button>
                    ))}
                </div>

                {tab === 'frameworks' && (
                    <ConfigSection
                        title="Appraisal frameworks"
                        subtitle="Each one reviews a population against its own criteria, on its own rating model."
                        canManage={can.manage}
                        newLabel="New framework"
                        onNew={() =>
                            setFrameworkForm({ open: true, template: null })
                        }
                        empty={templates.length === 0}
                        emptyLabel="No frameworks yet. Create one to start appraising."
                        archived={archivedTemplates}
                        onRestore={(template) =>
                            router.patch(
                                kpiConfigRoutes.frameworks.restore(
                                    template.hashid,
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        onForceDelete={(template) =>
                            askConfirm(
                                deleteAction(
                                    template.name,
                                    'This cannot be undone. A framework already used for appraisals cannot be permanently deleted.',
                                    () =>
                                        router.delete(
                                            kpiConfigRoutes.frameworks.forceDelete(
                                                template.hashid,
                                            ),
                                            withProcessing,
                                        ),
                                ),
                            )
                        }
                    >
                        {templates.map((template) => (
                            <div key={template.id} className="p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="truncate text-sm font-semibold">
                                                {template.name}
                                            </p>
                                            {template.is_default && (
                                                <Pill>Default</Pill>
                                            )}
                                            {!template.is_active && (
                                                <Pill>Not offered</Pill>
                                            )}
                                            <Pill>
                                                {audienceLabel(template)}
                                            </Pill>
                                        </div>
                                        {template.description && (
                                            <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                                {template.description}
                                            </p>
                                        )}
                                    </div>
                                    {can.manage && (
                                        <RowActions
                                            onEdit={() =>
                                                setFrameworkForm({
                                                    open: true,
                                                    template,
                                                })
                                            }
                                            onArchive={() =>
                                                askConfirm(
                                                    archiveAction(
                                                        template.name,
                                                        'It stops being offered for new appraisals. Appraisals already conducted under it keep their own copy.',
                                                        () =>
                                                            router.delete(
                                                                kpiConfigRoutes.frameworks.destroy(
                                                                    template.hashid,
                                                                ),
                                                                withProcessing,
                                                            ),
                                                    ),
                                                )
                                            }
                                        />
                                    )}
                                </div>

                                <div className="mt-3 flex flex-wrap gap-1.5">
                                    {template.sections.map((section) => (
                                        <span
                                            key={section.key}
                                            className="rounded-md border border-border bg-muted/50 px-2 py-1 text-[11px]"
                                        >
                                            {section.name}
                                            <span className="ml-1.5 font-semibold text-muted-foreground tabular-nums">
                                                {section.weight}%
                                            </span>
                                        </span>
                                    ))}
                                </div>

                                <div className="mt-3 rounded-lg border border-border/60 bg-muted/20 p-3">
                                    <RatingLadder
                                        bands={template.bands}
                                        percent={null}
                                    />
                                </div>

                                <p className="mt-2.5 text-xs text-muted-foreground tabular-nums">
                                    {template.items_count} criteria ·{' '}
                                    {template.evaluations_count} appraisals
                                    {template.section_weight_total !== 100 && (
                                        <span className="text-amber-600 dark:text-amber-400">
                                            {' · sections total '}
                                            {template.section_weight_total}%
                                        </span>
                                    )}
                                </p>
                            </div>
                        ))}
                    </ConfigSection>
                )}

                {tab === 'scales' && (
                    <ConfigSection
                        title="Rating scales"
                        subtitle="The instruments criteria are measured with. Defined once, reused everywhere."
                        canManage={can.manage}
                        newLabel="New scale"
                        onNew={() => setScaleForm({ open: true, scale: null })}
                        empty={scales.length === 0}
                        emptyLabel="No rating scales yet."
                        archived={archivedScales}
                        onRestore={(scale) =>
                            router.patch(
                                kpiConfigRoutes.scales.restore(scale.hashid),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        onForceDelete={(scale) =>
                            askConfirm(
                                deleteAction(
                                    scale.name,
                                    'This cannot be undone. A scale still in use cannot be permanently deleted.',
                                    () =>
                                        router.delete(
                                            kpiConfigRoutes.scales.forceDelete(
                                                scale.hashid,
                                            ),
                                            withProcessing,
                                        ),
                                ),
                            )
                        }
                    >
                        {scales.map((scale) => (
                            <div
                                key={scale.id}
                                className="flex items-center gap-3 px-4 py-3"
                            >
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[11px] font-semibold text-[#0a7d82] tabular-nums dark:text-[#3fd6d6]">
                                    {scale.descriptor.replace(' levels', 'L')}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {scale.name}
                                        </p>
                                        {scale.is_default && (
                                            <Pill>Default</Pill>
                                        )}
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {scale.levels
                                            ? scale.levels
                                                  .map((level) => level.label)
                                                  .join(' · ')
                                            : (scale.description ??
                                              scale.descriptor)}
                                    </p>
                                </div>
                                <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                                    used {scale.usage_count}×
                                </span>
                                {can.manage && (
                                    <RowActions
                                        onEdit={() =>
                                            setScaleForm({ open: true, scale })
                                        }
                                        onArchive={() =>
                                            askConfirm(
                                                archiveAction(
                                                    scale.name,
                                                    'It stops being offered. Appraisals already measured on it keep their own copy.',
                                                    () =>
                                                        router.delete(
                                                            kpiConfigRoutes.scales.destroy(
                                                                scale.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                ),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </ConfigSection>
                )}

                {tab === 'criteria' && (
                    <ConfigSection
                        title="Criteria catalogue"
                        subtitle="The dimensions performance is measured on. Frameworks draw from this list."
                        canManage={can.manage}
                        newLabel="New criterion"
                        onNew={() =>
                            setCriterionForm({ open: true, criterion: null })
                        }
                        empty={criteria.length === 0}
                        emptyLabel="No criteria yet."
                        archived={archivedCriteria}
                        onRestore={(criterion) =>
                            router.patch(
                                kpiConfigRoutes.criteria.restore(
                                    criterion.hashid,
                                ),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        onForceDelete={(criterion) =>
                            askConfirm(
                                deleteAction(
                                    criterion.name,
                                    'This cannot be undone. A criterion used by a framework cannot be permanently deleted.',
                                    () =>
                                        router.delete(
                                            kpiConfigRoutes.criteria.forceDelete(
                                                criterion.hashid,
                                            ),
                                            withProcessing,
                                        ),
                                ),
                            )
                        }
                    >
                        {criteria.map((criterion) => (
                            <div
                                key={criterion.id}
                                className="flex items-center gap-3 px-4 py-3"
                            >
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-sm font-semibold text-[#0a7d82] tabular-nums dark:text-[#3fd6d6]">
                                    {criterion.weight}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {criterion.name}
                                        </p>
                                        {criterion.scale_name && (
                                            <Pill>{criterion.scale_name}</Pill>
                                        )}
                                        {!criterion.is_active && (
                                            <Pill>Inactive</Pill>
                                        )}
                                    </div>
                                    {criterion.description && (
                                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                            {criterion.description}
                                        </p>
                                    )}
                                </div>
                                <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                                    in {criterion.usage_count}{' '}
                                    {criterion.usage_count === 1
                                        ? 'framework'
                                        : 'frameworks'}
                                </span>
                                {can.manage && (
                                    <RowActions
                                        onEdit={() =>
                                            setCriterionForm({
                                                open: true,
                                                criterion,
                                            })
                                        }
                                        onArchive={() =>
                                            askConfirm(
                                                archiveAction(
                                                    criterion.name,
                                                    'It stops being offered when building a framework. Frameworks already using it are untouched.',
                                                    () =>
                                                        router.delete(
                                                            kpiConfigRoutes.criteria.destroy(
                                                                criterion.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                ),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </ConfigSection>
                )}

                {tab === 'cycles' && (
                    <ConfigSection
                        title="Review cycles"
                        subtitle="The windows appraisals are conducted in."
                        canManage={can.manage}
                        newLabel="New cycle"
                        onNew={() =>
                            setPeriodForm({ open: true, period: null })
                        }
                        empty={periods.length === 0}
                        emptyLabel="No review cycles yet."
                        archived={archivedPeriods}
                        onRestore={(period) =>
                            router.patch(
                                kpiConfigRoutes.periods.restore(period.hashid),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        onForceDelete={(period) =>
                            askConfirm(
                                deleteAction(
                                    period.name,
                                    'This cannot be undone. A cycle with appraisals cannot be permanently deleted.',
                                    () =>
                                        router.delete(
                                            kpiConfigRoutes.periods.forceDelete(
                                                period.hashid,
                                            ),
                                            withProcessing,
                                        ),
                                ),
                            )
                        }
                    >
                        {periods.map((period) => (
                            <div
                                key={period.id}
                                className="flex items-center gap-3 px-4 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="truncate text-sm font-medium">
                                            {period.name}
                                        </p>
                                        <PeriodStatusBadge
                                            status={period.status}
                                        />
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground tabular-nums">
                                        {formatDate(period.start_date)} –{' '}
                                        {formatDate(period.end_date)}
                                    </p>
                                </div>
                                <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                                    {period.evaluations_count}{' '}
                                    {period.evaluations_count === 1
                                        ? 'appraisal'
                                        : 'appraisals'}
                                </span>
                                {can.manage && (
                                    <RowActions
                                        onEdit={() =>
                                            setPeriodForm({
                                                open: true,
                                                period,
                                            })
                                        }
                                        onArchive={() =>
                                            askConfirm(
                                                archiveAction(
                                                    period.name,
                                                    'It is hidden from the pickers; existing appraisals are kept. You can restore it later.',
                                                    () =>
                                                        router.delete(
                                                            kpiConfigRoutes.periods.destroy(
                                                                period.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                ),
                                            )
                                        }
                                    />
                                )}
                            </div>
                        ))}
                    </ConfigSection>
                )}
            </div>

            <FrameworkModal
                template={frameworkForm.template}
                scales={scales}
                criteria={criteria}
                audiences={audiences}
                tones={tones}
                defaultBands={defaultBands}
                open={frameworkForm.open}
                onOpenChange={(open) =>
                    setFrameworkForm((prev) => ({ ...prev, open }))
                }
            />
            <RatingScaleModal
                scale={scaleForm.scale}
                open={scaleForm.open}
                onOpenChange={(open) =>
                    setScaleForm((prev) => ({ ...prev, open }))
                }
            />
            <CriterionModal
                criterion={criterionForm.criterion}
                scales={scales}
                open={criterionForm.open}
                onOpenChange={(open) =>
                    setCriterionForm((prev) => ({ ...prev, open }))
                }
            />
            <PeriodModal
                period={periodForm.period}
                open={periodForm.open}
                onOpenChange={(open) =>
                    setPeriodForm((prev) => ({ ...prev, open }))
                }
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

/** Who a framework reviews, in a phrase. */
function audienceLabel(template: ReviewTemplateOption): string {
    if (template.applies_to === 'all') {
        return 'Everyone';
    }

    const noun =
        template.applies_to === 'department'
            ? 'department'
            : template.applies_to === 'position'
              ? 'position'
              : 'employment type';
    const count = template.applies_to_values.length;

    return `${count} ${noun}${count === 1 ? '' : 's'}`;
}

function Pill({ children }: { children: ReactNode }) {
    return (
        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
            {children}
        </span>
    );
}

type ArchivedItem = { id: number; hashid: string; name: string };

function ConfigSection<T extends ArchivedItem>({
    title,
    subtitle,
    canManage,
    newLabel,
    onNew,
    empty,
    emptyLabel,
    archived,
    onRestore,
    onForceDelete,
    children,
}: {
    title: string;
    subtitle: string;
    canManage: boolean;
    newLabel: string;
    onNew: () => void;
    empty: boolean;
    emptyLabel: string;
    archived: T[];
    onRestore: (item: T) => void;
    onForceDelete: (item: T) => void;
    children: ReactNode;
}) {
    const [showArchived, setShowArchived] = useState(false);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <p className="text-xs text-muted-foreground">{subtitle}</p>
                </div>
                <div className="flex items-center gap-2">
                    {archived.length > 0 && (
                        <Button
                            variant={showArchived ? 'secondary' : 'ghost'}
                            size="sm"
                            onClick={() => setShowArchived((value) => !value)}
                            className={cn(
                                !showArchived && 'text-muted-foreground',
                            )}
                        >
                            <Archive className="size-4" />
                            Archived
                            <span className="ml-0.5 tabular-nums">
                                ({archived.length})
                            </span>
                        </Button>
                    )}
                    {canManage && (
                        <Button size="sm" onClick={onNew}>
                            <Plus className="size-4" />
                            {newLabel}
                        </Button>
                    )}
                </div>
            </div>

            {empty ? (
                <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                    {emptyLabel}
                </p>
            ) : (
                <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    {children}
                </div>
            )}

            {showArchived && archived.length > 0 && (
                <div className="space-y-2">
                    {archived.map((item) => (
                        <div
                            key={item.id}
                            className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                        >
                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                {item.name}
                            </span>
                            {canManage && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onRestore(item)}
                                    >
                                        <ArchiveRestore className="size-4" />
                                        Restore
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 text-muted-foreground hover:text-destructive"
                                        onClick={() => onForceDelete(item)}
                                        aria-label={`Permanently delete ${item.name}`}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function RowActions({
    onEdit,
    onArchive,
}: {
    onEdit: () => void;
    onArchive: () => void;
}) {
    return (
        <div className="flex shrink-0 items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                className="size-8"
                onClick={onEdit}
                aria-label="Edit"
            >
                <Pencil className="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="size-8 text-muted-foreground hover:text-destructive"
                onClick={onArchive}
                aria-label="Archive"
            >
                <Archive className="size-4" />
            </Button>
        </div>
    );
}

SetupKpi.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Performance framework', href: '/setup/kpi' },
    ],
};
