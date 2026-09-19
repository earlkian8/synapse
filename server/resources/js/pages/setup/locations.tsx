import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
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
import { Button } from '@/components/ui/button';
import { SitesMap } from '@/features/locations/components/fence-map';
import { LocationFormModal } from '@/features/locations/components/location-form-modal';
import { LocationPeopleDialog } from '@/features/locations/components/location-people-dialog';
import { locationRoutes } from '@/features/locations/routes';
import type {
    LocationsPageProps,
    WorkLocation,
} from '@/features/locations/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/**
 * Company Setup → Locations (ADR 0040): the company's sites, each a fence on a
 * map. A punch from the web or the app is placed against them; whether being
 * off site is flagged or refused is each attendance policy's call.
 */
export default function SetupLocations() {
    const { locations, archivedLocations, options, checkingPolicies, can } =
        usePage<LocationsPageProps>().props;

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
            <Head title="Locations" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex max-w-2xl flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Locations
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Where your people work. Each site is a fence on the
                            map; web and app punches are placed against it, and
                            every punch keeps where it was made.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
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
                            against yet, so nothing is being checked. Add the
                            first location.
                        </p>
                    </div>
                )}

                {locations.length === 0 ? (
                    <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-5 py-8 dark:border-sidebar-border">
                        <p className="max-w-xl text-sm text-muted-foreground">
                            No locations yet. Punches are recorded wherever they
                            are made, and nothing is checked. Draw a site to see
                            who punched on it, and let a policy flag or refuse
                            punches away from it.
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
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                        <div className="divide-y divide-border self-start overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                            {locations.map((location) => (
                                <LocationRow
                                    key={location.id}
                                    location={location}
                                    selected={location.id === selectedId}
                                    canManage={can.manage}
                                    onSelect={() => setSelectedId(location.id)}
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
                        </div>

                        <div className="order-first overflow-hidden rounded-xl border border-sidebar-border/70 lg:sticky lg:top-4 lg:order-none lg:self-start dark:border-sidebar-border">
                            <SitesMap
                                sites={locations}
                                selectedId={selectedId}
                                onSelect={setSelectedId}
                                className="h-72 w-full lg:h-[28rem]"
                            />
                        </div>
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
            </div>

            <LocationFormModal
                location={form.location}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                schedules={options.schedules}
                policies={options.policies}
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

/** One site: its fence, who is based there, what they default to. */
function LocationRow({
    location,
    selected,
    canManage,
    onSelect,
    onEdit,
    onPeople,
    onArchive,
}: {
    location: WorkLocation;
    selected: boolean;
    canManage: boolean;
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
        <div
            className={cn(
                'flex items-start gap-3 px-4 py-3 transition-colors',
                selected && 'bg-[#0ABFBF]/[0.06]',
            )}
        >
            <button
                type="button"
                onClick={onSelect}
                aria-label={`Show ${location.name} on the map`}
                className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF] transition-colors hover:bg-[#0ABFBF]/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <MapPinned className="size-4" />
            </button>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={onSelect}
                        className="truncate text-left text-sm font-medium hover:underline"
                    >
                        {location.name}
                    </button>
                    {!location.is_active && (
                        <span className="rounded-full border border-border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Not checking punches
                        </span>
                    )}
                </div>
                {location.address && (
                    <p className="truncate text-xs text-muted-foreground">
                        {location.address}
                    </p>
                )}
                <p className="mt-1 text-xs text-muted-foreground">
                    <span className="tabular-nums">
                        {location.radius_meters} m fence
                    </span>
                    {' · '}
                    {location.employees_count === 0
                        ? 'Nobody based here'
                        : `${location.employees_count} ${location.employees_count === 1 ? 'person' : 'people'} based here`}
                    {location.devices_count > 0 &&
                        ` · ${location.devices_count} ${location.devices_count === 1 ? 'device' : 'devices'}`}
                </p>
                {defaults.length > 0 && (
                    <p className="mt-0.5 text-xs text-muted-foreground/80">
                        {defaults.join(' · ')}
                    </p>
                )}
            </div>
            {canManage && (
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        onClick={onPeople}
                        aria-label="People based here"
                        title="People based here"
                    >
                        <Users className="size-4" />
                    </Button>
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

SetupLocations.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Locations', href: '/setup/locations' },
    ],
};
