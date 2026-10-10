import { Head, router, usePage } from '@inertiajs/react';
import { Medal } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AwardsNav } from '@/features/awards/components/awards-nav';
import { formatDay } from '@/features/events/constants';
import { NominateDialog } from '@/features/recognition/components/nominate-dialog';
import { NOMINATION_STATUS } from '@/features/recognition/constants';
import { recognitionRoutes } from '@/features/recognition/routes';
import type { MyNominationsPageProps } from '@/features/recognition/types';

/**
 * My nominations (ADR 0071): who the person nominated, for what, and what HR
 * decided — withdrawable while it waits.
 */
export default function MyNominations() {
    const { nominations, colleagues, types } =
        usePage<MyNominationsPageProps>().props;
    const [open, setOpen] = useState(false);

    return (
        <>
            <Head title="My nominations" />

            <PageBody>
                <PageHeader
                    title="My nominations"
                    description="Put a colleague forward for an award. HR reviews each one and you hear the outcome."
                    actions={<AwardsNav current="my-nominations" />}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        {types.length === 0
                            ? 'No award is open to nominations right now.'
                            : `Open to nominations: ${types.map((t) => t.name).join(', ')}.`}
                    </p>
                    <Button
                        size="sm"
                        onClick={() => setOpen(true)}
                        disabled={types.length === 0}
                    >
                        <Medal className="size-4" />
                        Nominate a colleague
                    </Button>
                </div>

                {nominations.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
                        <Medal
                            className="size-8 text-muted-foreground"
                            aria-hidden
                        />
                        <p className="font-medium">
                            You haven’t nominated anyone yet
                        </p>
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Know someone who went beyond? Nominate them — your
                            reason becomes their citation.
                        </p>
                    </div>
                ) : (
                    <ul className="flex flex-col gap-3">
                        {nominations.map((n) => (
                            <li
                                key={n.id}
                                className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 sm:flex-row sm:items-start dark:border-sidebar-border"
                            >
                                <PersonAvatar
                                    name={n.nominee?.name ?? '?'}
                                    initials={n.nominee?.initials ?? '?'}
                                    photo={n.nominee?.photo}
                                    className="size-10"
                                />
                                <div className="flex min-w-0 flex-1 flex-col gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium">
                                            {n.nominee?.name ??
                                                'A former colleague'}
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                for{' '}
                                            </span>
                                            {n.award_type?.name}
                                        </p>
                                        <Badge
                                            variant="outline"
                                            className={
                                                NOMINATION_STATUS[n.status]
                                                    .className
                                            }
                                        >
                                            {NOMINATION_STATUS[n.status].label}
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {n.reason}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Sent {formatDay(n.created_at)}
                                        {n.reviewed_at &&
                                            ` · decided ${formatDay(n.reviewed_at)}`}
                                        {n.review_note &&
                                            ` · “${n.review_note}”`}
                                    </p>
                                </div>
                                {n.status === 'pending' && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="self-start text-muted-foreground"
                                        onClick={() =>
                                            router.delete(
                                                recognitionRoutes.withdraw(
                                                    n.id,
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Withdraw
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </PageBody>

            <NominateDialog
                open={open}
                onOpenChange={setOpen}
                colleagues={colleagues}
                types={types}
            />
        </>
    );
}

MyNominations.layout = {
    breadcrumbs: [
        { title: 'Awards & Recognition', href: '/awards' },
        { title: 'My nominations', href: '/awards/my-nominations' },
    ],
};
