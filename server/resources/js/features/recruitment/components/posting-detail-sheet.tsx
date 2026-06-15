import {
    CalendarClock,
    ExternalLink,
    KanbanSquare,
    Link2,
    Pencil,
} from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { TYPE_LABELS } from '../constants';
import type { ManagedPosting, RecruitmentPermissions } from '../types';
import { PostingStatusBadge } from './posting-status-badge';

type Props = {
    posting: ManagedPosting | null;
    open: boolean;
    can: RecruitmentPermissions;
    onOpenChange: (open: boolean) => void;
    onOpenPipeline: (posting: ManagedPosting) => void;
    onEdit: (posting: ManagedPosting) => void;
};

export function PostingDetailSheet({
    posting,
    open,
    can,
    onOpenChange,
    onOpenPipeline,
    onEdit,
}: Props) {
    if (!posting) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="right"
                    className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
                />
            </Sheet>
        );
    }

    const copyPublicLink = () => {
        if (!posting.apply_url) {
            return;
        }

        navigator.clipboard.writeText(posting.apply_url).then(
            () => toast.success('Public application link copied'),
            () => toast.error('Could not copy the link'),
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                <SheetHeader className="border-b border-border px-6 py-5">
                    <div className="flex items-start gap-3">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-[#0F2044] text-white">
                            <KanbanSquare className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <SheetTitle className="truncate text-lg">
                                {posting.title}
                            </SheetTitle>
                            <p className="truncate text-xs text-muted-foreground">
                                {posting.position?.title ??
                                    'No linked position'}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <PostingStatusBadge status={posting.status} />
                                <span className="text-xs text-muted-foreground">
                                    {TYPE_LABELS[posting.employment_type]}
                                    {posting.created_human
                                        ? ` · posted ${posting.created_human}`
                                        : ''}
                                </span>
                            </div>
                        </div>
                    </div>
                </SheetHeader>

                <div className="space-y-6 px-6 py-5">
                    {/* Pipeline summary */}
                    <div className="grid grid-cols-3 gap-3">
                        <Stat
                            label="Openings"
                            value={`${posting.hired_count ?? 0}/${posting.openings}`}
                            hint="filled"
                        />
                        <Stat
                            label="Active"
                            value={String(posting.open_count ?? 0)}
                            hint="in pipeline"
                            accent
                        />
                        <Stat
                            label="Total"
                            value={String(posting.applications_count ?? 0)}
                            hint="applications"
                        />
                    </div>

                    {/* Facts */}
                    <Group title="Overview">
                        <Row
                            label="Department"
                            value={posting.department?.name ?? '—'}
                        />
                        <Row
                            label="Position"
                            value={posting.position?.title ?? '—'}
                        />
                        <Row
                            label="Employment type"
                            value={TYPE_LABELS[posting.employment_type]}
                        />
                        <Row
                            label="Closing date"
                            value={posting.closing_date ?? 'Open-ended'}
                        />
                        {posting.posted_by && (
                            <Row label="Posted by" value={posting.posted_by} />
                        )}
                    </Group>

                    {/* Public link */}
                    {posting.is_open && posting.apply_url && (
                        <Group title="Public application page">
                            <p className="text-sm text-muted-foreground">
                                Candidates can read this role and apply here —
                                share the link or copy it for a job board.
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={copyPublicLink}
                                >
                                    <Link2 className="size-4" />
                                    Copy link
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={posting.apply_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink className="size-4" />
                                        View public page
                                    </a>
                                </Button>
                            </div>
                        </Group>
                    )}

                    {/* Description */}
                    {posting.description && (
                        <Group title="Description">
                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                {posting.description}
                            </p>
                        </Group>
                    )}

                    {/* Requirements */}
                    {posting.requirements && (
                        <Group title="Requirements">
                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                {posting.requirements}
                            </p>
                        </Group>
                    )}

                    {!posting.description && !posting.requirements && (
                        <p className="flex items-center gap-2 rounded-md bg-muted/40 p-3 text-sm text-muted-foreground">
                            <CalendarClock className="size-4" />
                            No description or requirements were added to this
                            posting.
                        </p>
                    )}
                </div>

                <div className="sticky bottom-0 flex flex-wrap items-center gap-2 border-t border-border bg-background px-6 py-4">
                    <Button
                        size="sm"
                        onClick={() => {
                            onOpenChange(false);
                            onOpenPipeline(posting);
                        }}
                    >
                        <KanbanSquare className="size-4" />
                        Open pipeline
                    </Button>
                    {can.update && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                onOpenChange(false);
                                onEdit(posting);
                            }}
                        >
                            <Pencil className="size-4" />
                            Edit posting
                        </Button>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function Stat({
    label,
    value,
    hint,
    accent = false,
}: {
    label: string;
    value: string;
    hint: string;
    accent?: boolean;
}) {
    return (
        <div className="rounded-lg border border-border bg-muted/30 p-3 text-center">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={
                    accent
                        ? 'mt-1 text-xl font-semibold text-[#0ABFBF] tabular-nums'
                        : 'mt-1 text-xl font-semibold tabular-nums'
                }
            >
                {value}
            </p>
            <p className="text-[11px] text-muted-foreground">{hint}</p>
        </div>
    );
}

function Group({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            {children}
        </section>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-4 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}
