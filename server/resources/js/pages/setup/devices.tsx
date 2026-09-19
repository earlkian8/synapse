import { Head, router, usePage } from '@inertiajs/react';
import {
    FileUp,
    KeyRound,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    DEVICE_TYPE_LABELS,
    DeviceFormModal,
    DeviceIcon,
    ImportDialog,
    KeyDialog,
} from '@/features/devices/components/device-dialogs';
import { deviceRoutes } from '@/features/devices/routes';
import type {
    AttendanceDevice,
    DevicesPageProps,
    ImportResult,
    IssuedKey,
} from '@/features/devices/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    run: () => void;
};

/** A device that has not been heard from in a day is worth a look. */
const QUIET_AFTER_MS = 24 * 60 * 60 * 1000;

/**
 * Company Setup → Devices (ADR 0040): the kiosks and biometric scanners that
 * send punches. A device is a key, shown once; its punches are recorded as it
 * sent them and flagged when they do not add up, never refused.
 */
export default function SetupDevices() {
    const { devices, locations, endpoints, can } =
        usePage<DevicesPageProps>().props;

    const [form, setForm] = useState<{
        open: boolean;
        device: AttendanceDevice | null;
    }>({ open: false, device: null });
    const [issued, setIssued] = useState<IssuedKey | null>(null);
    const [importing, setImporting] = useState<AttendanceDevice | null>(null);
    const [imported, setImported] = useState<ImportResult | null>(null);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // The key and an import's outcome arrive as one-time flash data, as the
    // toasts do; they live in this page's memory and nowhere else.
    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash as
                    | { device_key?: IssuedKey; device_import?: ImportResult }
                    | undefined;

                if (flash?.device_key) {
                    setIssued(flash.device_key);
                }

                if (flash?.device_import) {
                    setImported(flash.device_import);
                }
            }),
        [],
    );

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
            <Head title="Devices" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex max-w-2xl flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Devices
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Kiosks and biometric scanners that send punches. A
                            device’s punches are recorded exactly as it sent
                            them; anything that doesn’t add up is flagged for
                            sign-off rather than lost.
                        </p>
                    </div>
                    {can.manage && (
                        <Button
                            size="sm"
                            onClick={() =>
                                setForm({ open: true, device: null })
                            }
                        >
                            <Plus className="size-4" />
                            Register a device
                        </Button>
                    )}
                </div>

                {devices.length === 0 ? (
                    <div className="flex flex-col items-start gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-5 py-8 dark:border-sidebar-border">
                        <p className="max-w-xl text-sm text-muted-foreground">
                            No devices yet. People punch from the web and the
                            mobile app. Register a scanner to take its punches,
                            or a kiosk to turn a tablet at the door into a
                            clock.
                        </p>
                        {can.manage && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setForm({ open: true, device: null })
                                }
                            >
                                <Plus className="size-4" />
                                Register the first device
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                        {devices.map((device) => (
                            <DeviceRow
                                key={device.id}
                                device={device}
                                canManage={can.manage}
                                onEdit={() => setForm({ open: true, device })}
                                onImport={() => {
                                    setImported(null);
                                    setImporting(device);
                                }}
                                onRotate={() =>
                                    askConfirm({
                                        title: `Replace ${device.name}’s key?`,
                                        description:
                                            'The current key stops working at once. The device sends nothing until it has the new one.',
                                        confirmLabel: 'Replace key',
                                        run: () =>
                                            router.post(
                                                deviceRoutes.rotateKey(
                                                    device.hashid,
                                                ),
                                                {},
                                                withProcessing,
                                            ),
                                    })
                                }
                                onToggle={() =>
                                    router.post(
                                        deviceRoutes.update(device.hashid),
                                        {
                                            name: device.name,
                                            work_location_id:
                                                device.work_location_id,
                                            is_active: !device.is_active,
                                        },
                                        { preserveScroll: true },
                                    )
                                }
                                onRemove={() =>
                                    askConfirm({
                                        title: `Remove ${device.name}?`,
                                        description:
                                            'Its key stops working. The punches it sent are kept.',
                                        confirmLabel: 'Remove',
                                        destructive: true,
                                        run: () =>
                                            router.delete(
                                                deviceRoutes.destroy(
                                                    device.hashid,
                                                ),
                                                withProcessing,
                                            ),
                                    })
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            <DeviceFormModal
                device={form.device}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                locations={locations}
            />

            <KeyDialog
                issued={issued}
                endpoints={endpoints}
                onClose={() => setIssued(null)}
            />

            <ImportDialog
                device={importing}
                result={imported}
                onClose={() => setImporting(null)}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive={confirm.destructive}
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

/** One device: what and where it is, and whether it is being heard from. */
function DeviceRow({
    device,
    canManage,
    onEdit,
    onImport,
    onRotate,
    onToggle,
    onRemove,
}: {
    device: AttendanceDevice;
    canManage: boolean;
    onEdit: () => void;
    onImport: () => void;
    onRotate: () => void;
    onToggle: () => void;
    onRemove: () => void;
}) {
    const [now] = useState(() => Date.now());
    const quiet =
        device.is_active &&
        device.last_seen_at !== null &&
        now - new Date(device.last_seen_at).getTime() > QUIET_AFTER_MS;

    const health = !device.is_active
        ? { dot: 'bg-slate-400', text: 'Deactivated' }
        : device.last_seen_at === null
          ? {
                dot: 'bg-slate-300 dark:bg-slate-600',
                text: 'Not heard from yet',
            }
          : quiet
            ? {
                  dot: 'bg-amber-500',
                  text: `Last heard from ${device.last_seen_human}`,
              }
            : {
                  dot: 'bg-emerald-500',
                  text: `Heard from ${device.last_seen_human}`,
              };

    return (
        <div className="flex items-start gap-3 px-4 py-3">
            <span
                className={cn(
                    'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg',
                    device.is_active
                        ? 'bg-[#0ABFBF]/10 text-[#0ABFBF]'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <DeviceIcon type={device.type} className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p
                        className={cn(
                            'truncate text-sm font-medium',
                            !device.is_active && 'text-muted-foreground',
                        )}
                    >
                        {device.name}
                    </p>
                    <span className="rounded-full border border-border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                        {DEVICE_TYPE_LABELS[device.type]}
                    </span>
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {device.location_name ?? 'Not at a location'} · key ending{' '}
                    <span className="font-mono">{device.key_hint}</span> ·{' '}
                    <span className="tabular-nums">{device.punches_count}</span>{' '}
                    {device.punches_count === 1 ? 'punch' : 'punches'}
                </p>
                <p className="mt-1 inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span
                        aria-hidden
                        className={cn('size-1.5 rounded-full', health.dot)}
                    />
                    {health.text}
                </p>
            </div>
            {canManage && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Actions for ${device.name}`}
                        >
                            <MoreHorizontal className="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={onEdit}>
                            <Pencil className="size-4" />
                            Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={onImport}
                            disabled={!device.is_active}
                        >
                            <FileUp className="size-4" />
                            Import a CSV export
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={onRotate}>
                            <KeyRound className="size-4" />
                            Replace key
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={onToggle}>
                            <Power className="size-4" />
                            {device.is_active ? 'Deactivate' : 'Reactivate'}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={onRemove}
                            className="text-destructive focus:text-destructive"
                        >
                            <Trash2 className="size-4" />
                            Remove
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

SetupDevices.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Devices', href: '/setup/devices' },
    ],
};
