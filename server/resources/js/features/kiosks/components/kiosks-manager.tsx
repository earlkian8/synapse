import { Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    Copy,
    KeyRound,
    LogOut,
    MapPin,
    MonitorSmartphone,
    Pencil,
    Plus,
    ShieldAlert,
    Tablet,
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
import { useClipboard } from '@/hooks/use-clipboard';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import { cn } from '@/lib/utils';
import type { Auth } from '@/types/auth';
import { requestPairingCode } from '../api';
import { ago, day, time } from '../format';
import { kioskAdminRoutes } from '../routes';
import type {
    IssuedPin,
    KiosksPageProps,
    ManagedKiosk,
    PairingCode,
    PinHolder,
} from '../types';
import { IssuePinsDialog } from './issue-pins-dialog';
import { IssuedPinsDialog } from './issued-pins-dialog';
import { KioskFormDialog } from './kiosk-form-dialog';
import { PairingCodeDialog } from './pairing-code-dialog';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

/**
 * Attendance → Kiosks (ADR 0092): the kiosks, and beside them the people who
 * clock in at them — each list with the actions that change it. Pairing codes
 * and PINs are asked for here and shown once.
 */
export function KiosksManager({
    kiosks,
    pins,
    options,
    policiesWithoutKiosk,
    kioskUrl,
    can,
}: KiosksPageProps) {
    const organization =
        usePage<{ auth: Auth }>().props.auth.organization?.name ?? '';
    const [form, setForm] = useState<{
        open: boolean;
        kiosk: ManagedKiosk | null;
    }>({ open: false, kiosk: null });
    const [pairing, setPairing] = useState<{
        open: boolean;
        kiosk: ManagedKiosk | null;
        code: PairingCode | null;
        error: string | null;
    }>({ open: false, kiosk: null, code: null, error: null });
    const [issue, setIssue] = useState<{ open: boolean; preselect: number[] }>({
        open: false,
        preselect: [],
    });
    const [issued, setIssued] = useState<IssuedPin[]>([]);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const remove = (url: string) =>
        router.delete(url, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmOpen(false);
            },
        });

    // Asked for on the click, not by the dialog: each ask spends the last code.
    const pair = async (kiosk: ManagedKiosk) => {
        setPairing({ open: true, kiosk, code: null, error: null });
        const outcome = await requestPairingCode(kiosk.hashid);
        setPairing((current) =>
            current.kiosk?.hashid === kiosk.hashid
                ? {
                      ...current,
                      code: outcome.ok ? outcome.data : null,
                      error: outcome.ok ? null : outcome.message,
                  }
                : current,
        );
    };

    return (
        <>
            {policiesWithoutKiosk.length > 0 && (
                <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                    <ShieldAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    <p>
                        {policiesWithoutKiosk
                            .map((name) => `“${name}”`)
                            .join(', ')}{' '}
                        {policiesWithoutKiosk.length === 1
                            ? 'doesn’t allow'
                            : 'don’t allow'}{' '}
                        punching at a kiosk, so anyone judged by{' '}
                        {policiesWithoutKiosk.length === 1 ? 'it' : 'them'} is
                        turned away here even with a PIN.
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

            <TableCard
                title="Kiosks"
                count={kiosks.length}
                description={<KioskAddress url={kioskUrl} />}
                actions={
                    <Button
                        size="sm"
                        onClick={() => setForm({ open: true, kiosk: null })}
                    >
                        <Plus className="size-4" />
                        Add kiosk
                    </Button>
                }
            >
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Kiosk</TableHead>
                            <TableHead>Device</TableHead>
                            <TableHead className="text-right">Today</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {kiosks.length === 0 && (
                            <EmptyTableRow
                                colSpan={4}
                                icon={Tablet}
                                title="No kiosks yet"
                                description="Add one for each tablet or computer you set up at a site, then pair it with a code."
                                action={
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setForm({ open: true, kiosk: null })
                                        }
                                    >
                                        <Plus className="size-4" />
                                        Add the first kiosk
                                    </Button>
                                }
                            />
                        )}
                        {kiosks.map((kiosk) => (
                            <KioskRow
                                key={kiosk.hashid}
                                kiosk={kiosk}
                                onPair={() => void pair(kiosk)}
                                onEdit={() => setForm({ open: true, kiosk })}
                                onUnpair={() =>
                                    askConfirm({
                                        title: `Sign the device out of “${kiosk.name}”?`,
                                        description:
                                            'It stops clocking people in at once and goes back to asking for a pairing code. The kiosk stays, ready for a device.',
                                        confirmLabel: 'Sign out device',
                                        run: () =>
                                            remove(
                                                kioskAdminRoutes.unpair(
                                                    kiosk.hashid,
                                                ),
                                            ),
                                    })
                                }
                                onRemove={() =>
                                    askConfirm({
                                        title: `Remove “${kiosk.name}”?`,
                                        description:
                                            'Its device is signed out and nobody can clock in at it. Punches made there keep saying where they were made.',
                                        confirmLabel: 'Remove kiosk',
                                        run: () =>
                                            remove(
                                                kioskAdminRoutes.destroy(
                                                    kiosk.hashid,
                                                ),
                                            ),
                                    })
                                }
                            />
                        ))}
                    </TableBody>
                </DataTable>
            </TableCard>

            <TableCard
                title="Who clocks in at a kiosk"
                count={pins.length}
                description="Their employee number and a PIN are all they need. They choose their own PIN the first time."
                actions={
                    <Button
                        size="sm"
                        onClick={() => setIssue({ open: true, preselect: [] })}
                    >
                        <KeyRound className="size-4" />
                        Give PINs
                    </Button>
                }
            >
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Person</TableHead>
                            <TableHead>PIN</TableHead>
                            <TableHead>Last used</TableHead>
                            <TableHead>Issued</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {pins.length === 0 && (
                            <EmptyTableRow
                                colSpan={5}
                                icon={KeyRound}
                                title="Nobody has a kiosk PIN yet"
                                description="Give one to the people who clock in without a phone."
                                action={
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setIssue({
                                                open: true,
                                                preselect: [],
                                            })
                                        }
                                    >
                                        <KeyRound className="size-4" />
                                        Give the first PINs
                                    </Button>
                                }
                            />
                        )}
                        {pins.map((pin) => (
                            <PinRow
                                key={pin.id}
                                pin={pin}
                                onReissue={() =>
                                    setIssue({
                                        open: true,
                                        preselect: [pin.employee.id],
                                    })
                                }
                                onWithdraw={() =>
                                    askConfirm({
                                        title: `Withdraw ${pin.employee.full_name}’s PIN?`,
                                        description:
                                            'They can no longer clock in at any kiosk. Their punches so far stay as they are.',
                                        confirmLabel: 'Withdraw PIN',
                                        run: () =>
                                            remove(
                                                kioskAdminRoutes.withdrawPin(
                                                    pin.id,
                                                ),
                                            ),
                                    })
                                }
                            />
                        ))}
                    </TableBody>
                </DataTable>
            </TableCard>

            <KioskFormDialog
                kiosk={form.kiosk}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                locations={options.locations}
            />

            <PairingCodeDialog
                kiosk={pairing.kiosk}
                pairing={pairing.code}
                error={pairing.error}
                open={pairing.open}
                onOpenChange={(open) =>
                    setPairing((prev) => ({ ...prev, open }))
                }
            />

            <IssuePinsDialog
                open={issue.open}
                onOpenChange={(open) => setIssue((prev) => ({ ...prev, open }))}
                employees={options.employees}
                departments={options.departments}
                preselect={issue.preselect}
                onIssued={(list) => {
                    setIssue({ open: false, preselect: [] });
                    setIssued(list);
                }}
            />

            <IssuedPinsDialog
                issued={issued}
                organization={organization}
                onClose={() => {
                    setIssued([]);
                    router.reload({ only: ['pins', 'options'] });
                }}
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

/** The kiosk page's address, to open on the tablet — copyable. */
function KioskAddress({ url }: { url: string }) {
    const [copied, copy] = useClipboard();

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            Open
            <button
                type="button"
                onClick={() => void copy(url)}
                className="inline-flex items-center gap-1 rounded font-medium text-[#0a8b91] hover:underline dark:text-[#0ABFBF]"
                title="Copy the address"
            >
                {url.replace(/^https?:\/\//, '')}
                {copied === url ? (
                    <Check className="size-3" />
                ) : (
                    <Copy className="size-3" />
                )}
            </button>
            on the tablet, then pair it with a code from here.
        </span>
    );
}

/** One kiosk: where it stands, which device it is, and its day so far. */
function KioskRow({
    kiosk,
    onPair,
    onEdit,
    onUnpair,
    onRemove,
}: {
    kiosk: ManagedKiosk;
    onPair: () => void;
    onEdit: () => void;
    onUnpair: () => void;
    onRemove: () => void;
}) {
    const timeZone = useOrganizationTimeZone();

    return (
        <TableRow>
            <TableCell className="max-w-72">
                <p className="truncate font-medium">{kiosk.name}</p>
                <p className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                    <MapPin className="size-3 shrink-0" />
                    {kiosk.location
                        ? `${kiosk.location.name}${kiosk.location.archived ? ' (archived site)' : ''}`
                        : 'Not at a site'}
                </p>
            </TableCell>
            <TableCell>
                {kiosk.status === 'paired' ? (
                    <div className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className={cn(
                                'size-2 shrink-0 rounded-full',
                                kiosk.online
                                    ? 'bg-emerald-500'
                                    : 'bg-slate-400',
                            )}
                        />
                        <div className="min-w-0">
                            <p className="truncate text-sm">
                                {kiosk.device_label ?? 'A paired device'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {kiosk.online
                                    ? 'On line now'
                                    : `Last seen ${ago(kiosk.last_seen_at)}`}
                            </p>
                        </div>
                    </div>
                ) : kiosk.status === 'pairing' ? (
                    <div className="flex items-center gap-2">
                        <span
                            aria-hidden
                            className="size-2 shrink-0 rounded-full bg-amber-500"
                        />
                        <p className="text-sm text-muted-foreground">
                            Waiting for its code until{' '}
                            {time(kiosk.pairing_expires_at, timeZone)}
                        </p>
                    </div>
                ) : (
                    <Button size="sm" variant="outline" onClick={onPair}>
                        <MonitorSmartphone className="size-4" />
                        Pair a device
                    </Button>
                )}
            </TableCell>
            <TableCell className="text-right text-sm whitespace-nowrap tabular-nums">
                {kiosk.punches_today > 0 ? (
                    `${kiosk.punches_today} ${kiosk.punches_today === 1 ? 'punch' : 'punches'}`
                ) : (
                    <span className="text-muted-foreground">None</span>
                )}
            </TableCell>
            <TableCell>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <RowMenuTrigger label={`Actions for ${kiosk.name}`} />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuItem onSelect={onPair}>
                            <MonitorSmartphone className="size-4" />
                            {kiosk.status === 'paired'
                                ? 'Pair a new device'
                                : 'Pair a device'}
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={onEdit}>
                            <Pencil className="size-4" />
                            Edit
                        </DropdownMenuItem>
                        {kiosk.status === 'paired' && (
                            <DropdownMenuItem onSelect={onUnpair}>
                                <LogOut className="size-4" />
                                Sign out the device
                            </DropdownMenuItem>
                        )}
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
            </TableCell>
        </TableRow>
    );
}

const PIN_BADGE: Record<PinHolder['status'], string> = {
    new: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    locked: 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300',
};

/** One person who clocks in at a kiosk, and how their PIN stands. */
function PinRow({
    pin,
    onReissue,
    onWithdraw,
}: {
    pin: PinHolder;
    onReissue: () => void;
    onWithdraw: () => void;
}) {
    const timeZone = useOrganizationTimeZone();
    const label = pin.left
        ? 'Has left'
        : pin.status === 'locked'
          ? `Locked until ${time(pin.locked_until, timeZone)}`
          : pin.status === 'new'
            ? 'Not chosen yet'
            : 'Their own';

    return (
        <TableRow>
            <TableCell className="max-w-72">
                <div className="flex items-center gap-2.5">
                    <PersonAvatar
                        name={pin.employee.full_name}
                        initials={pin.employee.initials}
                        photo={pin.employee.photo}
                        className="size-8"
                    />
                    <div className="min-w-0">
                        <p className="truncate font-medium">
                            {pin.employee.full_name}
                        </p>
                        <p className="flex gap-2 truncate text-xs text-muted-foreground">
                            <span>{pin.employee.employee_no}</span>
                            {pin.employee.department && (
                                <span className="truncate">
                                    {pin.employee.department}
                                </span>
                            )}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell>
                <span
                    className={cn(
                        'inline-flex rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                        pin.left
                            ? 'border-border text-muted-foreground'
                            : PIN_BADGE[pin.status],
                    )}
                    title={
                        pin.status === 'new'
                            ? 'They haven’t used the PIN you gave them yet. The first time they do, they choose their own.'
                            : undefined
                    }
                >
                    {label}
                </span>
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                {pin.last_used_at ? ago(pin.last_used_at) : 'Not yet'}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                {day(pin.issued_at, timeZone)}
                {pin.issued_by && (
                    <span className="block text-xs text-muted-foreground">
                        by {pin.issued_by}
                    </span>
                )}
            </TableCell>
            <TableCell>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <RowMenuTrigger
                            label={`Actions for ${pin.employee.full_name}`}
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52">
                        <DropdownMenuItem
                            onSelect={onReissue}
                            disabled={pin.left}
                        >
                            <KeyRound className="size-4" />
                            Give a new PIN
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={onWithdraw}
                        >
                            <Trash2 className="size-4" />
                            Withdraw PIN
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </TableCell>
        </TableRow>
    );
}
