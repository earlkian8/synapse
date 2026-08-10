import {
    CalendarClock,
    ExternalLink,
    KanbanSquare,
    Link2,
    Pencil,
} from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { TYPE_LABELS } from '../constants';
import type { ManagedPosting, RecruitmentPermissions } from '../types';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
    ModalSection,
} from './modal';
import { PostingStatusBadge } from './posting-status-badge';

type Props = {
    posting: ManagedPosting | null;
    open: boolean;
    can: RecruitmentPermissions;
    onOpenChange: (open: boolean) => void;
    onOpenPipeline: (posting: ManagedPosting) => void;
    onEdit: (posting: ManagedPosting) => void;
};

export function PostingDetailDialog({
    posting,
    open,
    can,
    onOpenChange,
    onOpenPipeline,
    onEdit,
}: Props) {
    if (!posting) {
        return null;
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
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <KanbanSquare />
                        </ModalIcon>
                    }
                    title={posting.title}
                    description={
                        posting.position?.title ?? 'No linked position'
                    }
                    meta={
                        <>
                            <PostingStatusBadge status={posting.status} />
                            <span className="text-xs text-muted-foreground">
                                {TYPE_LABELS[posting.employment_type]}
                                {posting.created_human
                                    ? ` · posted ${posting.created_human}`
                                    : ''}
                            </span>
                        </>
                    }
                />

                <ModalBody className="space-y-6">
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
                    <ModalSection title="Overview">
                        <dl className="grid gap-x-6 gap-y-2.5 sm:grid-cols-2">
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
                                <Row
                                    label="Posted by"
                                    value={posting.posted_by}
                                />
                            )}
                            {posting.min_years_experience != null && (
                                <Row
                                    label="Minimum experience"
                                    value={`${posting.min_years_experience} ${
                                        posting.min_years_experience === 1
                                            ? 'year'
                                            : 'years'
                                    }`}
                                />
                            )}
                        </dl>
                        {posting.skills.length > 0 && (
                            <div className="flex flex-wrap gap-1.5 pt-1">
                                {posting.skills.map((skill) => (
                                    <span
                                        key={skill}
                                        className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                                    >
                                        {skill}
                                    </span>
                                ))}
                            </div>
                        )}
                    </ModalSection>

                    {/* Public link */}
                    {posting.is_open && posting.apply_url && (
                        <ModalSection
                            title="Public application page"
                            hint="Candidates can read this role and apply here — share the link or copy it for a job board."
                        >
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
                        </ModalSection>
                    )}

                    {/* Description */}
                    {posting.description && (
                        <ModalSection title="Description">
                            <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                                {posting.description}
                            </p>
                        </ModalSection>
                    )}

                    {/* Requirements */}
                    {posting.requirements && (
                        <ModalSection title="Requirements">
                            <p className="text-sm leading-relaxed whitespace-pre-line text-muted-foreground">
                                {posting.requirements}
                            </p>
                        </ModalSection>
                    )}

                    {!posting.description && !posting.requirements && (
                        <p className="flex items-center gap-2 rounded-md bg-muted/40 p-3 text-sm text-muted-foreground">
                            <CalendarClock className="size-4 shrink-0" />
                            No description or requirements were added to this
                            posting.
                        </p>
                    )}
                </ModalBody>

                <ModalFooter className="justify-start">
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
                </ModalFooter>
            </ModalContent>
        </Modal>
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

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">{value}</dd>
        </div>
    );
}
