import { router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Clock,
    Hexagon,
    MapPinned,
    Pencil,
    Plus,
    ShieldAlert,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { locationRoutes } from '../routes';
import type { LocationsPageProps, WorkLocation } from '../types';
import { SitesMap } from './fence-map';
import { LocationFormModal } from './location-form-modal';
import { LocationPeopleDialog } from './location-people-dialog';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

type Props = LocationsPageProps & {
    /** What sits beside the actions — the screen's title, or a step's section heading. */
    heading?: ReactNode;
};

/**
 * The company's sites (ADR 0040) with every action Company Setup offers on
 * them — draw a fence, edit it, say who is based there, archive, restore and
 * delete — beside the map. Rendered by the Locations screen and by the setup
 * wizard's step for it, from the same props.
 */
export function LocationsManager({
    locations,
    archivedLocations,
    options,
    checkingPolicies,
    can,
    heading,
}: Props) {
    const [form, setForm] = useState<{
        open: boolean;
        location: WorkLocation | null;
    }>({ open: false, location: null });
    const [people, setPeople] = useState<{
        open: boolean;
        location: WorkLocation | null;
    }>({ open: false, location: null });
    const [selectedId, setSelectedId] = useState<number | null>(null);
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

    const noSiteYet =
        checkingPolicies.length > 0 &&
        !locations.some((location) => location.is_active);

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                {heading}
                <div className="ml-auto flex items-center gap-2">
                    {archivedLocations.length > 0 && (
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
                                ({archivedLocations.length})
                            </span>
                        </Button>
                    )}
                    {can.manage && (
                        <Button
                            size="sm"
                            onClick={() =>
                                setForm({ open: true, location: null })
                            }
                        >
                            <Plus className="size-4" />
                            New location
                        </Button>
                    )}
                </div>
            </div>

            {noSiteYet && (
                <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p>
                        {checkingPolicies
                            .map((policy) => `“${policy.name}”`)
                            .join(', ')}{' '}
                        {checkingPolicies.length === 1 ? 'checks' : 'check'}{' '}
                        where people punch, but there is no site to check
                        against yet, so nothing is being checked. Add the first
                        location.
                    </p>
                </div>
            )}

            {locations.length === 0 ? (
                <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-5 py-8 dark:border-sidebar-border">
                    <p className="max-w-xl text-sm text-muted-foreground">
                        No locations yet. Punches are recorded wherever they are
                        made, and nothing is checked. Draw a site to see who
                        punched on it, and let a policy flag or refuse punches
                        away from it.
                    </p>
                    {can.manage && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setForm({ open: true, location: null })
                            }
                        >
                            <MapPinned className="size-4" />
                            Draw the first site
                        </Button>
                    )}
                </div>
            ) : (
                <div className="flex flex-col gap-4">
                    <div className="overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <SitesMap
                            sites={locations}
                            selectedId={selectedId}
                            onSelect={setSelectedId}
                            className="h-72 w-full lg:h-96"
                        />
                    </div>

                    <TableCard>
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Site</TableHead>
                                    <TableHead>Fence</TableHead>
                                    <TableHead>Clock</TableHead>
                                    <TableHead className="text-right">
                                        People
                                    </TableHead>
                                    <TableHead>Defaults</TableHead>
                                    {can.manage && (
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {locations.map((location) => (
                                    <LocationRow
                                        key={location.id}
                                        location={location}
                                        selected={location.id === selectedId}
                                        canManage={can.manage}
                                        organizationTimezone={
                                            options.organizationTimezone
                                        }
                                        onSelect={() =>
                                            setSelectedId(location.id)
                                        }
                                        onEdit={() =>
                                            setForm({ open: true, location })
                                        }
                                        onPeople={() =>
                                            setPeople({ open: true, location })
                                        }
                                        onArchive={() =>
                                            askConfirm({
                                                title: `Archive "${location.name}"?`,
                                                description:
                                                    'Punches stop being checked against it, and its people stop defaulting to its schedule and policy. Punches already made keep saying they were made there.',
                                                confirmLabel: 'Archive',
                                                run: () =>
                                                    router.delete(
                                                        locationRoutes.destroy(
                                                            location.hashid,
                                                        ),
                                                        withProcessing,
                                                    ),
                                            })
                                        }
                                    />
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>
                </div>
            )}

            {showArchived && archivedLocations.length > 0 && (
                <div className="space-y-2">
                    {archivedLocations.map((location) => (
                        <div
                            key={location.id}
                            className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                        >
                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                {location.name}
                            </span>
                            {can.manage && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.patch(
                                                locationRoutes.restore(
                                                    location.hashid,
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
                                        aria-label="Delete permanently"
                                        onClick={() =>
                                            askConfirm({
                                                title: `Permanently delete "${location.name}"?`,
                                                description:
                                                    'This cannot be undone. A location punches were made at is kept, so they keep saying where they were.',
                                                confirmLabel:
                                                    'Delete permanently',
                                                run: () =>
                                                    router.delete(
                                                        locationRoutes.forceDelete(
                                                            location.hashid,
                                                        ),
                                                        withProcessing,
                                                    ),
                                            })
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <LocationFormModal
                location={form.location}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                schedules={options.schedules}
                policies={options.policies}
                timezones={options.timezones}
                organizationTimezone={options.organizationTimezone}
            />

            <LocationPeopleDialog
                location={people.location}
                open={people.open}
                onOpenChange={(open) =>
                    setPeople((prev) => ({ ...prev, open }))
                }
                employees={options.employees}
                departments={options.departments}
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

/** One site: its fence, its clock, who is based there, what they default to. */
function LocationRow({
    location,
    selected,
    canManage,
    organizationTimezone,
    onSelect,
    onEdit,
    onPeople,
    onArchive,
}: {
    location: WorkLocation;
    selected: boolean;
    canManage: boolean;
    organizationTimezone: string;
    onSelect: () => void;
    onEdit: () => void;
    onPeople: () => void;
    onArchive: () => void;
}) {
    const defaults = [
        location.schedule_name && `Schedule: ${location.schedule_name}`,
        location.policy_name && `Policy: ${location.policy_name}`,
    ].filter(Boolean);

    return (
        <TableRow
            {...rowOpens(onSelect)}
            aria-selected={selected}
            className={cn(
                rowOpens(onSelect).className,
                selected && 'bg-[#0ABFBF]/[0.06] hover:bg-[#0ABFBF]/[0.08]',
            )}
        >
            <TableCell className="max-w-72">
                <div className="flex items-center gap-2">
                    <p className="truncate font-medium">{location.name}</p>
                    {!location.is_active && (
                        <span className="shrink-0 rounded-full border border-border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Not checking punches
                        </span>
                    )}
                </div>
                {location.address && (
                    <p className="truncate text-xs text-muted-foreground">
                        {location.address}
                    </p>
                )}
            </TableCell>
            <TableCell className="whitespace-nowrap">
                <span className="inline-flex items-center gap-1.5 text-sm tabular-nums">
                    {location.fence_type === 'polygon' ? (
                        <Hexagon className="size-3.5 text-[#0a8b91] dark:text-[#0ABFBF]" />
                    ) : (
                        <MapPinned className="size-3.5 text-[#0a8b91] dark:text-[#0ABFBF]" />
                    )}
                    {location.fence_label}
                </span>
            </TableCell>
            <TableCell className="whitespace-nowrap">
                {location.timezone ? (
                    <span className="inline-flex items-center gap-1.5 text-sm">
                        <Clock className="size-3.5 text-muted-foreground" />
                        {location.timezone.replace(/_/g, ' ')}
                    </span>
                ) : (
                    <span
                        className="text-sm text-muted-foreground"
                        title={organizationTimezone.replace(/_/g, ' ')}
                    >
                        Company’s
                    </span>
                )}
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {location.employees_count}
            </TableCell>
            <TableCell className="max-w-64">
                <p className="truncate text-xs text-muted-foreground">
                    {defaults.length > 0 ? defaults.join(' · ') : '—'}
                </p>
            </TableCell>
            {canManage && (
                <TableCell>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <RowMenuTrigger
                                label={`Actions for ${location.name}`}
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuItem onSelect={onPeople}>
                                <Users className="size-4" />
                                People based here
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onArchive}
                            >
                                <Archive className="size-4" />
                                Archive
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </TableCell>
            )}
        </TableRow>
    );
}
