import { Head, router, usePage } from '@inertiajs/react';
import { HandHeart } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageBody, PageHeader } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { AwardsNav } from '@/features/awards/components/awards-nav';
import { FeedCard } from '@/features/recognition/components/feed-card';
import { KudosComposer } from '@/features/recognition/components/kudos-composer';
import { NominateDialog } from '@/features/recognition/components/nominate-dialog';
import { PointsCard } from '@/features/recognition/components/points-card';
import { recognitionRoutes } from '@/features/recognition/routes';
import type { FeedItem, WallPageProps } from '@/features/recognition/types';
import { cn } from '@/lib/utils';

/** How many moments the wall shows at first, and adds per "Show more". */
const PAGE = 15;

const FILTERS = [
    { key: 'all', label: 'Everything' },
    { key: 'kudos', label: 'Kudos' },
    { key: 'award', label: 'Awards' },
] as const;

type Filter = (typeof FILTERS)[number]['key'];

/**
 * The recognition wall (ADR 0071): kudos colleagues sent each other and the
 * awards given, newest first — with a composer to thank someone, and the
 * person's own points beside it.
 */
export default function RecognitionWall() {
    const { feed, me, colleagues, types } = usePage<WallPageProps>().props;
    const [nominating, setNominating] = useState(false);
    const [removing, setRemoving] = useState<FeedItem | null>(null);
    const [processing, setProcessing] = useState(false);
    const [filter, setFilter] = useState<Filter>('all');
    const [shown, setShown] = useState(PAGE);

    const items = useMemo(
        () =>
            filter === 'all'
                ? feed
                : feed.filter((item) => item.kind === filter),
        [feed, filter],
    );

    const remove = () =>
        removing &&
        router.delete(recognitionRoutes.kudosDestroy(removing.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });

    return (
        <>
            <Head title="Recognition wall" />

            <PageBody>
                <PageHeader
                    title="Recognition wall"
                    description="Thank a colleague for something specific, and see who’s been recognised in the last 90 days."
                    actions={<AwardsNav current="wall" />}
                />

                {!me.has_employee ? (
                    <p className="rounded-xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
                        Your account isn’t linked to an employee record, so you
                        can read the wall but not send kudos. Ask HR to link it.
                    </p>
                ) : null}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div className="flex min-w-0 flex-col gap-4">
                        {me.has_employee && (
                            <KudosComposer colleagues={colleagues} me={me} />
                        )}

                        {feed.length > 0 && (
                            <div
                                className="flex items-center gap-1 overflow-x-auto border-b border-border"
                                role="tablist"
                                aria-label="Show on the wall"
                            >
                                {FILTERS.map((option) => (
                                    <button
                                        key={option.key}
                                        type="button"
                                        role="tab"
                                        aria-selected={filter === option.key}
                                        onClick={() => {
                                            setFilter(option.key);
                                            setShown(PAGE);
                                        }}
                                        className={cn(
                                            'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                            filter === option.key
                                                ? 'border-[#0ABFBF] text-foreground'
                                                : 'border-transparent text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {feed.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
                                <HandHeart
                                    className="size-8 text-muted-foreground"
                                    aria-hidden
                                />
                                <p className="font-medium">
                                    Nothing on the wall yet
                                </p>
                                <p className="max-w-sm text-sm text-muted-foreground">
                                    Be the first: thank someone for something
                                    they did this week.
                                </p>
                            </div>
                        ) : items.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-border px-6 py-10 text-center text-sm text-muted-foreground">
                                {filter === 'kudos'
                                    ? 'No kudos in the last 90 days.'
                                    : 'No awards in the last 90 days.'}
                            </p>
                        ) : (
                            <div className="flex flex-col gap-3">
                                <ol
                                    className="divide-y divide-border rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border"
                                    aria-label="Recent recognition"
                                >
                                    {items.slice(0, shown).map((item) => (
                                        <li key={`${item.kind}-${item.id}`}>
                                            <FeedCard
                                                item={item}
                                                onRemove={setRemoving}
                                            />
                                        </li>
                                    ))}
                                </ol>
                                {items.length > shown && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="self-center"
                                        onClick={() => setShown(shown + PAGE)}
                                    >
                                        Show more
                                        <span className="text-muted-foreground tabular-nums">
                                            {items.length - shown} left
                                        </span>
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>

                    {me.has_employee && (
                        <aside className="lg:sticky lg:top-4 lg:self-start">
                            <PointsCard
                                me={me}
                                onNominate={() => setNominating(true)}
                            />
                        </aside>
                    )}
                </div>
            </PageBody>

            <NominateDialog
                open={nominating}
                onOpenChange={setNominating}
                colleagues={colleagues}
                types={types}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title="Take these kudos down?"
                description="They leave the wall, and any points they gave are taken back. The sender isn’t told."
                confirmLabel="Take down"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </>
    );
}

RecognitionWall.layout = {
    breadcrumbs: [
        { title: 'Awards & Recognition', href: '/awards' },
        { title: 'Wall', href: '/awards/wall' },
    ],
};
