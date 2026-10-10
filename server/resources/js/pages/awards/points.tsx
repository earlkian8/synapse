import { Head, router, usePage } from '@inertiajs/react';
import { Gift, HandHeart, Hourglass, PackageX, Wallet } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader, StatTiles } from '@/components/data-table';
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
import { Spinner } from '@/components/ui/spinner';
import { AwardsNav } from '@/features/awards/components/awards-nav';
import { formatDay } from '@/features/events/constants';
import {
    LEDGER_KIND,
    REDEMPTION_STATUS,
    formatPoints,
} from '@/features/recognition/constants';
import { recognitionRoutes } from '@/features/recognition/routes';
import type { MyRewardsPageProps, Reward } from '@/features/recognition/types';
import { cn } from '@/lib/utils';

/**
 * Points & rewards (ADR 0071): the person's balance, what it can buy, their
 * requests, and every line that explains the balance.
 */
export default function MyRewards() {
    const { me, rewards, redemptions, history } =
        usePage<MyRewardsPageProps>().props;
    const [choosing, setChoosing] = useState<Reward | null>(null);

    return (
        <>
            <Head title="My points" />

            <PageBody>
                <PageHeader
                    title="My points"
                    description="Awards and kudos earn points. Spend them on a reward; HR hands it over."
                    actions={<AwardsNav current="points" />}
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'balance',
                            label: 'Points to spend',
                            value: me.balance.toLocaleString(),
                            icon: Wallet,
                            accent: 'teal',
                        },
                        {
                            key: 'reach',
                            label: 'Rewards in reach',
                            value: rewards.filter(
                                (r) => r.cost <= me.balance && r.stock !== 0,
                            ).length,
                            icon: Gift,
                            accent: 'emerald',
                            hint: `of ${rewards.length}`,
                        },
                        {
                            key: 'waiting',
                            label: 'Requests waiting',
                            value: redemptions.filter(
                                (r) => r.status === 'pending',
                            ).length,
                            icon: Hourglass,
                            accent: 'amber',
                        },
                        {
                            key: 'kudos',
                            label: 'Kudos with points left',
                            value: me.kudos_left,
                            icon: HandHeart,
                            accent: 'rose',
                            hint: `of ${me.kudos_monthly_limit} this month`,
                        },
                    ]}
                />

                <section
                    aria-labelledby="catalogue"
                    className="flex flex-col gap-3"
                >
                    <h2 id="catalogue" className="text-base font-semibold">
                        Rewards
                    </h2>
                    {rewards.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
                            HR hasn’t put any rewards up yet. Your points keep
                            until they do.
                        </p>
                    ) : (
                        <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {rewards.map((reward) => {
                                const out = reward.stock === 0;
                                const short = reward.cost - me.balance;

                                return (
                                    <li
                                        key={reward.id}
                                        className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <h3 className="font-medium">
                                                    {reward.name}
                                                </h3>
                                                {reward.description && (
                                                    <p className="mt-0.5 line-clamp-3 text-sm text-muted-foreground">
                                                        {reward.description}
                                                    </p>
                                                )}
                                            </div>
                                            <span className="shrink-0 text-lg font-semibold tabular-nums">
                                                {reward.cost.toLocaleString()}
                                            </span>
                                        </div>
                                        <div className="mt-auto flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                            <span>
                                                {out
                                                    ? 'Out of stock'
                                                    : reward.stock !== null
                                                      ? `${reward.stock} left`
                                                      : ''}
                                            </span>
                                            <Button
                                                size="sm"
                                                variant={
                                                    reward.affordable && !out
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                disabled={
                                                    !reward.affordable ||
                                                    out ||
                                                    !me.has_employee
                                                }
                                                onClick={() =>
                                                    setChoosing(reward)
                                                }
                                            >
                                                {out ? (
                                                    <PackageX className="size-4" />
                                                ) : (
                                                    <Gift className="size-4" />
                                                )}
                                                {out
                                                    ? 'Out of stock'
                                                    : reward.affordable
                                                      ? 'Redeem'
                                                      : `${short.toLocaleString()} more needed`}
                                            </Button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <section
                        aria-labelledby="requests"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="requests" className="text-base font-semibold">
                            Your requests
                        </h2>
                        {redemptions.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing asked for yet.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                                {redemptions.map((r) => (
                                    <li
                                        key={r.id}
                                        className="flex items-start justify-between gap-3 px-4 py-3 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-medium">
                                                {r.reward?.name ?? 'A reward'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDay(r.created_at)} ·{' '}
                                                {formatPoints(r.cost)}
                                                {r.response_note
                                                    ? ` · “${r.response_note}”`
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge
                                                variant="outline"
                                                className={
                                                    REDEMPTION_STATUS[r.status]
                                                        .className
                                                }
                                            >
                                                {
                                                    REDEMPTION_STATUS[r.status]
                                                        .label
                                                }
                                            </Badge>
                                            {r.status === 'pending' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.patch(
                                                            recognitionRoutes.cancel(
                                                                r.id,
                                                            ),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-labelledby="history"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="history" className="text-base font-semibold">
                            Where your points came from
                        </h2>
                        {history.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No points yet — awards and kudos from colleagues
                                add them.
                            </p>
                        ) : (
                            <ul className="divide-y divide-border rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                                {history.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate">
                                                {line.note ??
                                                    LEDGER_KIND[line.kind]}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {LEDGER_KIND[line.kind]} ·{' '}
                                                {formatDay(line.at)}
                                            </p>
                                        </div>
                                        <span
                                            className={cn(
                                                'shrink-0 font-semibold tabular-nums',
                                                line.amount > 0
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {line.amount > 0 ? '+' : '−'}
                                            {Math.abs(
                                                line.amount,
                                            ).toLocaleString()}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </PageBody>

            <RedeemDialog
                reward={choosing}
                balance={me.balance}
                onClose={() => setChoosing(null)}
            />
        </>
    );
}

function RedeemDialog({
    reward,
    balance,
    onClose,
}: {
    reward: Reward | null;
    balance: number;
    onClose: () => void;
}) {
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);

    const redeem = () =>
        reward &&
        router.post(
            recognitionRoutes.redeem(reward.hashid),
            { note },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setNote('');
                    onClose();
                },
            },
        );

    return (
        <Dialog
            open={reward !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Redeem {reward?.name}?</DialogTitle>
                    <DialogDescription>
                        {reward
                            ? `${formatPoints(reward.cost)} come off now — you’ll have ${formatPoints(balance - reward.cost)} left. If HR declines it, or you cancel before it’s handed over, they come back.`
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                <Input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    maxLength={500}
                    placeholder="A note for HR — a size, a colour (optional)"
                    aria-label="Note for HR"
                />
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Not now
                    </Button>
                    <Button onClick={redeem} disabled={processing}>
                        {processing && <Spinner />}
                        Redeem
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

MyRewards.layout = {
    breadcrumbs: [
        { title: 'Awards & Recognition', href: '/awards' },
        { title: 'My points', href: '/awards/points' },
    ],
};
