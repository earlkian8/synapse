import { BriefcaseBusiness, CalendarClock, Users2 } from 'lucide-react';
import { TYPE_LABELS } from '../constants';
import type { ManagedPosting, RecruitmentPermissions } from '../types';
import { PostingRowActions } from './posting-row-actions';
import { PostingStatusBadge } from './posting-status-badge';

type Handlers = {
    onView: (posting: ManagedPosting) => void;
    onOpen: (posting: ManagedPosting) => void;
    onEdit: (posting: ManagedPosting) => void;
    onStatus: (posting: ManagedPosting, status: string) => void;
    onDelete: (posting: ManagedPosting) => void;
};

type Props = Handlers & {
    postings: ManagedPosting[];
    can: RecruitmentPermissions;
};

export function PostingsGrid({ postings, can, ...handlers }: Props) {
    if (postings.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card py-16 text-center dark:border-sidebar-border">
                <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                    <BriefcaseBusiness className="size-6 text-muted-foreground" />
                </span>
                <p className="text-sm font-medium">No job postings found</p>
                <p className="max-w-xs text-sm text-muted-foreground">
                    Create a posting to start collecting applications.
                </p>
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {postings.map((posting) => (
                <div
                    key={posting.id}
                    className="group flex flex-col rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border"
                >
                    <div className="flex items-start justify-between gap-2">
                        <button
                            type="button"
                            onClick={() => handlers.onView(posting)}
                            className="min-w-0 flex-1 text-left"
                        >
                            <span className="block truncate font-medium hover:underline">
                                {posting.title}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {posting.position?.title ??
                                    'No linked position'}
                            </span>
                        </button>
                        <PostingRowActions
                            posting={posting}
                            can={can}
                            {...handlers}
                        />
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <PostingStatusBadge status={posting.status} />
                        <span className="inline-flex items-center rounded-md bg-muted/60 px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            {TYPE_LABELS[posting.employment_type]}
                        </span>
                    </div>

                    <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Department
                            </dt>
                            <dd className="mt-0.5 truncate">
                                {posting.department?.name ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Openings filled
                            </dt>
                            <dd className="mt-0.5 inline-flex items-center gap-1 tabular-nums">
                                <Users2 className="size-3.5 text-muted-foreground" />
                                {posting.hired_count ?? 0}/{posting.openings}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Closing
                            </dt>
                            <dd className="mt-0.5 inline-flex items-center gap-1 text-muted-foreground">
                                <CalendarClock className="size-3.5" />
                                {posting.closing_date ?? 'Open-ended'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Pipeline
                            </dt>
                            <dd className="mt-0.5">
                                <button
                                    type="button"
                                    onClick={() => handlers.onOpen(posting)}
                                    className="inline-flex items-center gap-1 rounded-md bg-muted/60 px-2 py-0.5 text-xs font-medium tabular-nums hover:bg-muted"
                                >
                                    <span className="text-[#0ABFBF]">
                                        {posting.open_count ?? 0}
                                    </span>
                                    <span className="text-muted-foreground">
                                        active ·{' '}
                                        {posting.applications_count ?? 0} total
                                    </span>
                                </button>
                            </dd>
                        </div>
                    </dl>
                </div>
            ))}
        </div>
    );
}
