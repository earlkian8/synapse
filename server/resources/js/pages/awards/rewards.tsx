import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Archive,
    Check,
    Gift,
    Pencil,
    Plus,
    RotateCcw,
    Scale,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import InputError from '@/components/input-error';
import { PersonAvatar } from '@/components/person-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { AwardsNav } from '@/features/awards/components/awards-nav';
import { awardsRoutes } from '@/features/awards/routes';
import { formatDay } from '@/features/events/constants';
import { ColleaguePicker } from '@/features/recognition/components/colleague-picker';
import { REDEMPTION_STATUS } from '@/features/recognition/constants';
import type {
    Redemption,
    Reward,
    RewardsDeskPageProps,
} from '@/features/recognition/types';

const card =
    'rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border';

/**
 * Awards → Rewards (ADR 0071), HR's desk: requests to hand over or decline,
 * the catalogue points are spent on, who holds the most points (and a way to
 * correct a balance), and what kudos are worth.
 */
export default function RewardsDesk() {
    const {
        rewards,
        redemptions,
        counts,
        balances,
        employees,
        settings,
        pending_nominations,
    } = usePage<RewardsDeskPageProps>().props;
    const [editing, setEditing] = useState<{
        open: boolean;
        reward: Reward | null;
    }>({ open: false, reward: null });
    const [handling, setHandling] = useState<{
        redemption: Redemption;
        action: 'fulfil' | 'decline';
    } | null>(null);
    const [adjusting, setAdjusting] = useState(false);

    return (
        <>
            <Head title="Rewards" />

            <PageBody>
                <PageHeader
                    title="Rewards"
                    description="Requests to hand over, and what points buy. Awards and kudos earn the points."
                    actions={
                        <AwardsNav
                            current="rewards"
                            pending={pending_nominations}
                        />
                    }
                />

                <section
                    aria-labelledby="requests"
                    className="flex flex-col gap-3"
                >
                    <h2 id="requests" className="text-base font-semibold">
                        Requests{' '}
                        <span className="text-muted-foreground tabular-nums">
                            {counts.pending} waiting
                        </span>
                    </h2>
                    {redemptions.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
                            No requests yet. When someone redeems points, it
                            waits here for you to hand over.
                        </p>
                    ) : (
                        <ul className={`divide-y divide-border ${card}`}>
                            {redemptions.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex flex-col gap-3 px-4 py-3 text-sm md:flex-row md:items-center"
                                >
                                    <div className="flex min-w-0 flex-1 items-center gap-3">
                                        <PersonAvatar
                                            name={r.employee?.name ?? '?'}
                                            initials={
                                                r.employee?.initials ?? '?'
                                            }
                                            photo={r.employee?.photo}
                                            className="size-8"
                                        />
                                        <div className="min-w-0">
                                            <p className="truncate">
                                                <span className="font-medium">
                                                    {r.employee?.name ??
                                                        'A former employee'}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {r.reward?.name} ·{' '}
                                                    {r.cost.toLocaleString()}{' '}
                                                    points
                                                </span>
                                            </p>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {formatDay(r.created_at)}
                                                {r.note && ` · “${r.note}”`}
                                                {r.handler && ` · ${r.handler}`}
                                                {r.response_note &&
                                                    ` · ${r.response_note}`}
                                            </p>
                                        </div>
                                    </div>
                                    {r.status === 'pending' && r.is_mine ? (
                                        <p className="shrink-0 text-xs text-muted-foreground">
                                            Your own request — another HR
                                            manager hands it over.
                                        </p>
                                    ) : r.status === 'pending' ? (
                                        <div className="flex shrink-0 gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setHandling({
                                                        redemption: r,
                                                        action: 'fulfil',
                                                    })
                                                }
                                            >
                                                <Check className="size-4" />
                                                Handed over
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setHandling({
                                                        redemption: r,
                                                        action: 'decline',
                                                    })
                                                }
                                            >
                                                <X className="size-4" />
                                                Decline
                                            </Button>
                                        </div>
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            className={
                                                REDEMPTION_STATUS[r.status]
                                                    .className
                                            }
                                        >
                                            {REDEMPTION_STATUS[r.status].label}
                                        </Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section
                    aria-labelledby="catalogue"
                    className="flex flex-col gap-3"
                >
                    <div className="flex items-center justify-between gap-3">
                        <h2 id="catalogue" className="text-base font-semibold">
                            Catalogue
                        </h2>
                        <Button
                            size="sm"
                            onClick={() =>
                                setEditing({ open: true, reward: null })
                            }
                        >
                            <Plus className="size-4" />
                            New reward
                        </Button>
                    </div>
                    {rewards.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-10 text-center">
                            <Gift
                                className="size-8 text-muted-foreground"
                                aria-hidden
                            />
                            <p className="text-sm text-muted-foreground">
                                Add what points can buy: a voucher, a half day
                                off, company merch.
                            </p>
                        </div>
                    ) : (
                        <ul className={`divide-y divide-border ${card}`}>
                            {rewards.map((reward) => (
                                <li
                                    key={reward.id}
                                    className="flex items-center gap-3 px-4 py-3 text-sm"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="flex items-center gap-2 font-medium">
                                            {reward.name}
                                            {reward.is_archived ? (
                                                <Badge variant="outline">
                                                    Archived
                                                </Badge>
                                            ) : !reward.is_active ? (
                                                <Badge variant="outline">
                                                    Not offered
                                                </Badge>
                                            ) : reward.stock === 0 ? (
                                                <Badge variant="outline">
                                                    Out of stock
                                                </Badge>
                                            ) : null}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {reward.stock === null
                                                ? 'Unlimited'
                                                : `${reward.stock} in stock`}{' '}
                                            · redeemed {reward.redeemed ?? 0}×
                                        </p>
                                    </div>
                                    <span className="font-semibold tabular-nums">
                                        {reward.cost.toLocaleString()}
                                    </span>
                                    {reward.is_archived ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.patch(
                                                    awardsRoutes.rewardRestore(
                                                        reward.hashid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RotateCcw className="size-4" />
                                            Restore
                                        </Button>
                                    ) : (
                                        <span className="flex">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8"
                                                aria-label={`Edit ${reward.name}`}
                                                onClick={() =>
                                                    setEditing({
                                                        open: true,
                                                        reward,
                                                    })
                                                }
                                            >
                                                <Pencil className="size-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 text-muted-foreground hover:text-destructive"
                                                aria-label={`Archive ${reward.name}`}
                                                onClick={() =>
                                                    router.delete(
                                                        awardsRoutes.rewardDestroy(
                                                            reward.hashid,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Archive className="size-4" />
                                            </Button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section
                        aria-labelledby="balances"
                        className="flex flex-col gap-3"
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h2
                                id="balances"
                                className="text-base font-semibold"
                            >
                                Most points
                            </h2>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setAdjusting(true)}
                            >
                                <Scale className="size-4" />
                                Adjust points
                            </Button>
                        </div>
                        {balances.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nobody has points yet.
                            </p>
                        ) : (
                            <ol className={`divide-y divide-border ${card}`}>
                                {balances.map((row, i) => (
                                    <li
                                        key={row.employee.id}
                                        className="flex items-center gap-3 px-4 py-2.5 text-sm"
                                    >
                                        <span className="w-5 text-right text-xs text-muted-foreground tabular-nums">
                                            {i + 1}
                                        </span>
                                        <PersonAvatar
                                            name={row.employee.name}
                                            initials={row.employee.initials}
                                            photo={row.employee.photo}
                                            className="size-7"
                                            fallbackClassName="text-[10px]"
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {row.employee.name}
                                        </span>
                                        <span className="font-semibold tabular-nums">
                                            {row.balance.toLocaleString()}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </section>

                    <KudosSettings settings={settings} />
                </div>
            </PageBody>

            <RewardDialog
                key={editing.reward?.id ?? 'new'}
                open={editing.open}
                reward={editing.reward}
                onClose={() => setEditing({ open: false, reward: null })}
            />
            <HandleDialog
                handling={handling}
                onClose={() => setHandling(null)}
            />
            <AdjustDialog
                open={adjusting}
                employees={employees}
                onClose={() => setAdjusting(false)}
            />
        </>
    );
}

function KudosSettings({
    settings,
}: {
    settings: RewardsDeskPageProps['settings'];
}) {
    const { data, setData, post, processing, errors, isDirty } = useForm({
        ...settings,
    });

    return (
        <section
            aria-labelledby="kudos-settings"
            className="flex flex-col gap-3"
        >
            <h2 id="kudos-settings" className="text-base font-semibold">
                Kudos
            </h2>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    post(awardsRoutes.settings, { preserveScroll: true });
                }}
                className={`flex flex-col gap-4 p-4 ${card}`}
            >
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <Label htmlFor="kudos-points" className="mb-1.5 block">
                            Points per kudos
                        </Label>
                        <Input
                            id="kudos-points"
                            type="number"
                            min={0}
                            max={1000}
                            value={data.kudos_points}
                            onChange={(e) =>
                                setData('kudos_points', Number(e.target.value))
                            }
                        />
                        <InputError
                            message={errors.kudos_points}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label htmlFor="kudos-limit" className="mb-1.5 block">
                            With points, per person a month
                        </Label>
                        <Input
                            id="kudos-limit"
                            type="number"
                            min={0}
                            max={100}
                            value={data.kudos_monthly_limit}
                            onChange={(e) =>
                                setData(
                                    'kudos_monthly_limit',
                                    Number(e.target.value),
                                )
                            }
                        />
                        <InputError
                            message={errors.kudos_monthly_limit}
                            className="mt-1.5"
                        />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    Past the monthly number, kudos still go out — without
                    points. 0 points turns kudos points off.
                </p>
                <Button
                    type="submit"
                    size="sm"
                    className="self-end"
                    disabled={processing || !isDirty}
                >
                    {processing && <Spinner />}
                    Save
                </Button>
            </form>
        </section>
    );
}

function RewardDialog({
    open,
    reward,
    onClose,
}: {
    open: boolean;
    reward: Reward | null;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        name: reward?.name ?? '',
        description: reward?.description ?? '',
        cost: reward?.cost ? String(reward.cost) : '',
        stock:
            reward?.stock !== null && reward?.stock !== undefined
                ? String(reward.stock)
                : '',
        is_active: reward?.is_active ?? true,
    });

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {reward ? 'Edit reward' : 'New reward'}
                    </DialogTitle>
                    <DialogDescription>
                        Something people can spend their points on.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(
                            reward
                                ? awardsRoutes.rewardUpdate(reward.hashid)
                                : awardsRoutes.rewardStore,
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                    className="flex flex-col gap-4"
                >
                    <div>
                        <Label htmlFor="reward-name" className="mb-1.5 block">
                            Name
                        </Label>
                        <Input
                            id="reward-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Coffee voucher"
                            required
                        />
                        <InputError message={errors.name} className="mt-1.5" />
                    </div>
                    <div>
                        <Label
                            htmlFor="reward-description"
                            className="mb-1.5 block"
                        >
                            Description
                        </Label>
                        <Input
                            id="reward-description"
                            value={data.description}
                            onChange={(e) =>
                                setData('description', e.target.value)
                            }
                            placeholder="Optional"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label
                                htmlFor="reward-cost"
                                className="mb-1.5 block"
                            >
                                Cost in points
                            </Label>
                            <Input
                                id="reward-cost"
                                type="number"
                                min={1}
                                value={data.cost}
                                onChange={(e) =>
                                    setData('cost', e.target.value)
                                }
                                required
                            />
                            <InputError
                                message={errors.cost}
                                className="mt-1.5"
                            />
                        </div>
                        <div>
                            <Label
                                htmlFor="reward-stock"
                                className="mb-1.5 block"
                            >
                                In stock
                            </Label>
                            <Input
                                id="reward-stock"
                                type="number"
                                min={0}
                                value={data.stock}
                                onChange={(e) =>
                                    setData('stock', e.target.value)
                                }
                                placeholder="Unlimited"
                            />
                            <InputError
                                message={errors.stock}
                                className="mt-1.5"
                            />
                        </div>
                    </div>
                    <label className="flex items-center justify-between gap-4 text-sm">
                        Offered
                        <Switch
                            checked={data.is_active}
                            onCheckedChange={(on) => setData('is_active', on)}
                        />
                    </label>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            {reward ? 'Save changes' : 'Add reward'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function HandleDialog({
    handling,
    onClose,
}: {
    handling: { redemption: Redemption; action: 'fulfil' | 'decline' } | null;
    onClose: () => void;
}) {
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);
    const fulfil = handling?.action === 'fulfil';

    const submit = () =>
        handling &&
        router.post(
            fulfil
                ? awardsRoutes.fulfil(handling.redemption.id)
                : awardsRoutes.decline(handling.redemption.id),
            { note },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setNote('');
                },
                onSuccess: onClose,
            },
        );

    return (
        <Dialog
            open={handling !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {fulfil ? 'Hand over' : 'Decline'}{' '}
                        {handling?.redemption.reward?.name}?
                    </DialogTitle>
                    <DialogDescription>
                        {fulfil
                            ? `${handling?.redemption.employee?.name} is told it’s ready.`
                            : `${handling?.redemption.employee?.name} gets their ${handling?.redemption.cost} points back, and is told.`}
                    </DialogDescription>
                </DialogHeader>
                <Input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    maxLength={1000}
                    placeholder={
                        fulfil
                            ? 'Where to pick it up (optional)'
                            : 'Why (optional)'
                    }
                    aria-label="Note for the employee"
                />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant={fulfil ? 'default' : 'destructive'}
                        onClick={submit}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {fulfil ? 'Handed over' : 'Decline'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function AdjustDialog({
    open,
    employees,
    onClose,
}: {
    open: boolean;
    employees: RewardsDeskPageProps['employees'];
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        employee_id: null as number | null,
        amount: '',
        note: '',
    });

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Adjust points</DialogTitle>
                    <DialogDescription>
                        Add or take away points, with a reason the person sees.
                        It shows in their history.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(awardsRoutes.adjust, {
                            preserveScroll: true,
                            onSuccess: () => {
                                reset();
                                onClose();
                            },
                        });
                    }}
                    className="flex flex-col gap-4"
                >
                    <div>
                        <Label className="mb-1.5 block">Who</Label>
                        <ColleaguePicker
                            colleagues={employees}
                            value={data.employee_id}
                            onChange={(id) => setData('employee_id', id)}
                            invalid={Boolean(errors.employee_id)}
                        />
                        <InputError
                            message={errors.employee_id}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label htmlFor="adjust-amount" className="mb-1.5 block">
                            Points (negative to take away)
                        </Label>
                        <Input
                            id="adjust-amount"
                            type="number"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            placeholder="e.g. 50 or -20"
                        />
                        <InputError
                            message={errors.amount}
                            className="mt-1.5"
                        />
                    </div>
                    <div>
                        <Label htmlFor="adjust-note" className="mb-1.5 block">
                            Reason
                        </Label>
                        <Input
                            id="adjust-note"
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                            maxLength={255}
                            placeholder="e.g. Prize for the safety quiz"
                        />
                        <InputError message={errors.note} className="mt-1.5" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || data.employee_id === null}
                        >
                            {processing && <Spinner />}
                            Adjust
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

RewardsDesk.layout = {
    breadcrumbs: [
        { title: 'Awards & Recognition', href: '/awards' },
        { title: 'Rewards', href: '/awards/rewards' },
    ],
};
