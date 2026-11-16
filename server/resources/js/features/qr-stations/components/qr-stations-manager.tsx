import { Link, router } from '@inertiajs/react';
import {
    ExternalLink,
    MapPin,
    MapPinned,
    Pencil,
    Plus,
    Power,
    QrCode,
    RefreshCcw,
    ShieldAlert,
    ShieldCheck,
    Smartphone,
    Timer,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
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
import { ago, time } from '@/features/kiosks/format';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import { qrStationRoutes } from '../routes';
import type {
    QrPunchType,
    QrScan,
    QrStation,
    QrStationsPageProps,
} from '../types';
import { QrStationFormDialog } from './qr-station-form-dialog';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive: boolean;
    run: () => void;
};

const PUNCH_WORDS: Record<QrPunchType, string> = {
    clock_in: 'Clocked in',
    break_start: 'Started a break',
    break_end: 'Back from break',
    clock_out: 'Clocked out',
};

/**
 * The four checks every scan passes, said once at the top so HR knows what the
 * station guards against — and what it leaves to them.
 */
const GUARDS: { icon: typeof Timer; title: string; body: string }[] = [
    {
        icon: Timer,
        title: 'The code keeps changing',
        body: 'A photo or screenshot stops working within seconds, and every code stops within three minutes of the screen closing.',
    },
    {
        icon: MapPinned,
        title: 'Only at the site',
        body: 'The phone must be inside the station’s site, with a precise location, when it scans.',
    },
    {
        icon: ShieldCheck,
        title: 'No faked locations',
        body: 'A phone that reports a simulated location is turned away and written down.',
    },
    {
        icon: Smartphone,
        title: 'One phone, one person',
        body: 'A phone that clocked somebody in can’t clock in anybody else for 12 hours.',
    },
];

/**
 * Attendance → QR stations (ADR 0103): the stations with how each is doing, and
 * below them every recent scan — the refusals and why included, which is where
 * somebody trying a forwarded code or a fake location shows up.
 */
export function QrStationsManager({
    stations,
    scans,
    options,
    policiesWithoutQr,
    pins,
    can,
}: QrStationsPageProps) {
    const [form, setForm] = useState<{
        open: boolean;
        station: QrStation | null;
    }>({ open: false, station: null });
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [refusedOnly, setRefusedOnly] = useState(false);

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const send = (method: 'patch' | 'delete', url: string) => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmOpen(false);
            },
        };

        if (method === 'delete') {
            router.delete(url, options);
        } else {
            router.patch(url, {}, options);
        }
    };

    const refusedCount = scans.filter(
        (scan) => scan.outcome === 'refused',
    ).length;
    const listed = refusedOnly
        ? scans.filter((scan) => scan.outcome === 'refused')
        : scans;

    return (
        <>
            {can.manage && policiesWithoutQr.length > 0 && (
                <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p>
                        {policiesWithoutQr
                            .map((name) => `“${name}”`)
                            .join(', ')}{' '}
                        {policiesWithoutQr.length === 1
                            ? 'doesn’t take'
                            : 'don’t take'}{' '}
                        punches from a QR station, so anyone judged by{' '}
                        {policiesWithoutQr.length === 1 ? 'it' : 'them'} is
                        turned away even after a good scan.
                        {can.viewPolicies && (
                            <>
                                {' '}
                                <Link
                                    href="/setup/attendance-policies"
                                    className="font-medium underline underline-offset-2"
                                >
                                    Open attendance policies
                                </Link>
                            </>
                        )}
                    </p>
                </div>
            )}

            <section
                aria-label="What every scan is checked for"
                className="grid gap-px overflow-hidden rounded-xl border bg-border sm:grid-cols-2 xl:grid-cols-4"
            >
                {GUARDS.map((guard) => (
                    <div key={guard.title} className="flex gap-3 bg-card p-4">
                        <guard.icon className="mt-0.5 size-4 shrink-0 text-[#0a8b91] dark:text-[#0ABFBF]" />
                        <div className="min-w-0 space-y-0.5">
                            <p className="text-sm font-medium">{guard.title}</p>
                            <p className="text-xs leading-relaxed text-muted-foreground">
                                {guard.body}
                            </p>
                        </div>
                    </div>
                ))}
            </section>

            <TableCard
                title="QR stations"
                count={stations.length}
                description="Show a station on a screen at its site: a TV, a monitor or a tablet. People scan it with the SYNAPSE app, or with any phone’s camera where a station allows it."
                actions={
                    can.manage ? (
                        <Button
                            size="sm"
                            onClick={() =>
                                setForm({ open: true, station: null })
                            }
                        >
                            <Plus className="size-4" />
                            Add station
                        </Button>
                    ) : undefined
                }
            >
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Station</TableHead>
                            <TableHead>Screen</TableHead>
                            <TableHead className="text-right">Today</TableHead>
                            <TableHead className="w-px">
                                <span className="sr-only">Show the code</span>
                            </TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {stations.length === 0 && (
                            <EmptyTableRow
                                colSpan={5}
                                icon={QrCode}
                                title="No QR stations yet"
                                description={
                                    can.manage
                                        ? 'Add one for each screen you put up at a site. Its code is what people scan to clock in.'
                                        : 'HR hasn’t set up a station for you to show yet.'
                                }
                                action={
                                    can.manage && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setForm({
                                                    open: true,
                                                    station: null,
                                                })
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Add the first station
                                        </Button>
                                    )
                                }
                            />
                        )}
                        {stations.map((station) => (
                            <StationRow
                                key={station.hashid}
                                station={station}
                                canDisplay={can.display}
                                canManage={can.manage}
                                onEdit={() => setForm({ open: true, station })}
                                onToggle={() =>
                                    station.is_active
                                        ? askConfirm({
                                              title: `Switch off “${station.name}”?`,
                                              description:
                                                  'Its screen stops showing a code and no scan of it is accepted until you switch it on again.',
                                              confirmLabel: 'Switch off',
                                              destructive: true,
                                              run: () =>
                                                  send(
                                                      'patch',
                                                      qrStationRoutes.toggle(
                                                          station.hashid,
                                                      ),
                                                  ),
                                          })
                                        : send(
                                              'patch',
                                              qrStationRoutes.toggle(
                                                  station.hashid,
                                              ),
                                          )
                                }
                                onReset={() =>
                                    askConfirm({
                                        title: `Reset the codes of “${station.name}”?`,
                                        description:
                                            'Every code it has shown stops working at once, and the screen moves on to new ones. Use it if the screen was left somewhere it shouldn’t be, or somebody may have copied a code.',
                                        confirmLabel: 'Reset codes',
                                        destructive: false,
                                        run: () =>
                                            send(
                                                'patch',
                                                qrStationRoutes.reset(
                                                    station.hashid,
                                                ),
                                            ),
                                    })
                                }
                                onRemove={() =>
                                    askConfirm({
                                        title: `Remove “${station.name}”?`,
                                        description:
                                            'Nobody can clock in with its code any more. Punches scanned there keep saying where they were made.',
                                        confirmLabel: 'Remove station',
                                        destructive: true,
                                        run: () =>
                                            send(
                                                'delete',
                                                qrStationRoutes.destroy(
                                                    station.hashid,
                                                ),
                                            ),
                                    })
                                }
                            />
                        ))}
                    </TableBody>
                </DataTable>
            </TableCard>

            {can.manage && (
                <TableCard
                    title="Latest scans"
                    count={listed.length}
                    description="Every scan of a station’s code, newest first. A refusal in red looks like somebody getting round the station; it is in the activity log too."
                    actions={
                        scans.length > 0 ? (
                            <div
                                role="group"
                                aria-label="Which scans to list"
                                className="inline-flex rounded-lg border bg-card p-0.5"
                            >
                                {[
                                    { key: false, label: 'All' },
                                    {
                                        key: true,
                                        label: `Refused (${refusedCount})`,
                                    },
                                ].map((option) => (
                                    <button
                                        key={String(option.key)}
                                        type="button"
                                        aria-pressed={
                                            refusedOnly === option.key
                                        }
                                        onClick={() =>
                                            setRefusedOnly(option.key)
                                        }
                                        className={cn(
                                            'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                            refusedOnly === option.key
                                                ? 'bg-[#0ABFBF]/10 text-[#0a8b91] dark:text-[#0ABFBF]'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        ) : undefined
                    }
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Person</TableHead>
                                <TableHead>Result</TableHead>
                                <TableHead>Where the phone was</TableHead>
                                <TableHead className="text-right">
                                    When
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {listed.length === 0 && (
                                <EmptyTableRow
                                    colSpan={4}
                                    icon={
                                        refusedOnly ? ShieldCheck : Smartphone
                                    }
                                    title={
                                        refusedOnly
                                            ? 'No refused scans'
                                            : 'Nobody has scanned yet'
                                    }
                                    description={
                                        refusedOnly
                                            ? 'Every recent scan was accepted.'
                                            : 'Scans appear here as people clock in at a station.'
                                    }
                                />
                            )}
                            {listed.map((scan) => (
                                <ScanRow key={scan.id} scan={scan} />
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>
            )}

            <QrStationFormDialog
                station={form.station}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                locations={options.locations}
                rotateChoices={options.rotateChoices}
                pins={pins}
                canManagePins={can.managePins}
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

/** One station: where it stands, whether a screen shows it, its day. */
function StationRow({
    station,
    canDisplay,
    canManage,
    onEdit,
    onToggle,
    onReset,
    onRemove,
}: {
    station: QrStation;
    canDisplay: boolean;
    canManage: boolean;
    onEdit: () => void;
    onToggle: () => void;
    onReset: () => void;
    onRemove: () => void;
}) {
    const screen = !station.is_active
        ? { dot: 'bg-slate-400', text: 'Switched off' }
        : !station.usable
          ? { dot: 'bg-amber-500', text: 'Needs a site' }
          : station.showing
            ? {
                  dot: 'bg-emerald-500',
                  text: `Showing now${station.last_shown_by ? `, on ${station.last_shown_by}’s screen` : ''}`,
              }
            : station.last_shown_at
              ? {
                    dot: 'bg-slate-400',
                    text: `Not showing. Last shown ${ago(station.last_shown_at)}`,
                }
              : { dot: 'bg-slate-400', text: 'Not shown on a screen yet' };

    return (
        <TableRow className={cn(!station.is_active && 'opacity-70')}>
            <TableCell className="max-w-72">
                <p className="truncate font-medium">{station.name}</p>
                <p className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                    <MapPin className="size-3 shrink-0" />
                    {station.location
                        ? `${station.location.name}${station.location.archived ? ' (site archived)' : ''}, new code every ${station.rotate_seconds} s`
                        : `No site, new code every ${station.rotate_seconds} s`}
                </p>
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {station.scan_mode === 'any'
                        ? 'Any phone’s camera, with number and PIN'
                        : 'SYNAPSE app only'}
                    {station.same_network && ', office Wi-Fi only'}
                </p>
            </TableCell>
            <TableCell>
                <div className="flex items-center gap-2">
                    <span
                        aria-hidden
                        className={cn(
                            'size-2 shrink-0 rounded-full',
                            screen.dot,
                        )}
                    />
                    <p className="text-sm">{screen.text}</p>
                </div>
            </TableCell>
            <TableCell className="text-right text-sm whitespace-nowrap tabular-nums">
                {station.accepted_today === 0 && station.refused_today === 0 ? (
                    <span className="text-muted-foreground">No scans</span>
                ) : (
                    <>
                        <span>{station.accepted_today} accepted</span>
                        {station.refused_today > 0 && (
                            <span className="block text-xs text-rose-600 dark:text-rose-400">
                                {station.refused_today} refused
                            </span>
                        )}
                    </>
                )}
            </TableCell>
            <TableCell>
                {canDisplay && station.usable && (
                    <Button size="sm" variant="outline" asChild>
                        <a
                            href={station.display_url}
                            target="_blank"
                            rel="noopener"
                        >
                            <QrCode className="size-4" />
                            Show code
                            <ExternalLink className="size-3.5 text-muted-foreground" />
                        </a>
                    </Button>
                )}
            </TableCell>
            <TableCell>
                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <RowMenuTrigger
                                label={`Actions for ${station.name}`}
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onToggle}>
                                <Power className="size-4" />
                                {station.is_active ? 'Switch off' : 'Switch on'}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onReset}>
                                <RefreshCcw className="size-4" />
                                Reset codes
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={onRemove}
                            >
                                <Trash2 className="size-4" />
                                Remove
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}

function ScanRow({ scan }: { scan: QrScan }) {
    const timeZone = useOrganizationTimeZone();
    const accepted = scan.outcome === 'accepted';

    return (
        <TableRow>
            <TableCell className="max-w-64">
                {scan.employee ? (
                    <div className="flex items-center gap-2.5">
                        <PersonAvatar
                            name={scan.employee.full_name}
                            initials={scan.employee.initials}
                            photo={scan.employee.photo}
                            className="size-8"
                        />
                        <div className="min-w-0">
                            <p className="truncate font-medium">
                                {scan.employee.full_name}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {scan.station ?? 'A removed station'}
                                {scan.client === 'browser' && ', in a browser'}
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="min-w-0">
                        <p className="truncate text-muted-foreground">
                            {scan.reason === 'wrong_pin' ||
                            scan.reason === 'invalid' ||
                            scan.reason === 'not_showing'
                                ? 'Not identified'
                                : 'Somebody no longer on file'}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {scan.station ?? 'A removed station'}
                        </p>
                    </div>
                )}
            </TableCell>
            <TableCell>
                <span
                    className={cn(
                        'inline-flex rounded-full border px-2 py-0.5 text-xs font-medium',
                        accepted
                            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                            : scan.suspicious
                              ? 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300'
                              : 'border-border text-muted-foreground',
                    )}
                >
                    {accepted
                        ? scan.punch_type
                            ? PUNCH_WORDS[scan.punch_type]
                            : 'Scanned, no punch made'
                        : scan.reason_label}
                </span>
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap text-muted-foreground tabular-nums">
                {scan.reason === 'mocked'
                    ? 'Simulated location'
                    : scan.distance_meters !== null && scan.distance_meters > 0
                      ? `${formatMeters(scan.distance_meters)} away`
                      : scan.accuracy !== null
                        ? `On site, within ${scan.accuracy} m`
                        : '—'}
            </TableCell>
            <TableCell className="text-right text-sm whitespace-nowrap">
                {time(scan.at, timeZone)}
                <span className="block text-xs text-muted-foreground">
                    {ago(scan.at)}
                </span>
            </TableCell>
        </TableRow>
    );
}

function formatMeters(meters: number): string {
    return meters < 1000 ? `${meters} m` : `${(meters / 1000).toFixed(1)} km`;
}
