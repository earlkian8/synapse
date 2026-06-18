import { Head, router, usePage } from '@inertiajs/react';
import { Archive, ArchiveRestore, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { AwardTypeFormSheet } from '@/features/award-types-config/components/award-type-form-sheet';
import { awardTypesConfigRoutes } from '@/features/award-types-config/routes';
import type { AwardTypeSetupPageProps } from '@/features/award-types-config/types';
import { DEFAULT_AWARD_COLOR } from '@/features/awards/constants';
import type { AwardType } from '@/features/awards/types';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function SetupAwardTypes() {
    const { types, archived, can } = usePage<AwardTypeSetupPageProps>().props;

    const [form, setForm] = useState<{ open: boolean; type: AwardType | null }>(
        { open: false, type: null },
    );
    const [showArchived, setShowArchived] = useState(false);
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

    return (
        <>
            <Head title="Award Types" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Award Types
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The recognitions the organisation gives out.
                            Recognise employees from the Awards module.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {archived.length > 0 && (
                            <Button
                                variant={showArchived ? 'secondary' : 'ghost'}
                                size="sm"
                                onClick={() => setShowArchived((v) => !v)}
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
                        {can.manage && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setForm({ open: true, type: null })
                                }
                            >
                                <Plus className="size-4" />
                                New type
                            </Button>
                        )}
                    </div>
                </div>

                {types.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        No award types yet.
                    </p>
                ) : (
                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                        {types.map((type) => (
                            <TypeRow
                                key={type.id}
                                type={type}
                                canManage={can.manage}
                                onEdit={() => setForm({ open: true, type })}
                                onArchive={() =>
                                    askConfirm({
                                        title: `Archive "${type.name}"?`,
                                        description:
                                            'It is hidden from the give-award picker; existing awards keep it. You can restore it later.',
                                        confirmLabel: 'Archive',
                                        run: () =>
                                            router.delete(
                                                awardTypesConfigRoutes.destroy(
                                                    type.hashid,
                                                ),
                                                withProcessing,
                                            ),
                                    })
                                }
                            />
                        ))}
                    </div>
                )}

                {showArchived && archived.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Archived types
                        </h2>
                        {archived.map((type) => (
                            <div
                                key={type.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                    {type.name}
                                </span>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.patch(
                                                    awardTypesConfigRoutes.restore(
                                                        type.hashid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <ArchiveRestore className="size-4" />
                                            Restore
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                askConfirm({
                                                    title: `Permanently delete "${type.name}"?`,
                                                    description:
                                                        'This cannot be undone. A type that has been given out cannot be permanently deleted.',
                                                    confirmLabel:
                                                        'Delete permanently',
                                                    run: () =>
                                                        router.delete(
                                                            awardTypesConfigRoutes.forceDelete(
                                                                type.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                })
                                            }
                                            aria-label="Delete permanently"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <AwardTypeFormSheet
                type={form.type}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
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

function TypeRow({
    type,
    canManage,
    onEdit,
    onArchive,
}: {
    type: AwardType;
    canManage: boolean;
    onEdit: () => void;
    onArchive: () => void;
}) {
    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <span
                className="flex size-9 shrink-0 items-center justify-center rounded-lg"
                style={{
                    backgroundColor: `${type.color ?? DEFAULT_AWARD_COLOR}1a`,
                }}
            >
                <span
                    className="size-3 rounded-full"
                    style={{
                        backgroundColor: type.color ?? DEFAULT_AWARD_COLOR,
                    }}
                />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">{type.name}</p>
                    {!type.is_active && (
                        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Inactive
                        </span>
                    )}
                </div>
                {type.description && (
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {type.description}
                    </p>
                )}
            </div>
            <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                {type.awards_count} given
            </span>
            {canManage && (
                <div className="flex items-center gap-1">
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
            )}
        </div>
    );
}

SetupAwardTypes.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Award Types', href: '/setup/award-types' },
    ],
};
