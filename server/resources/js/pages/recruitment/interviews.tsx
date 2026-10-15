import { Head, useForm, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    ExternalLink,
    FileText,
    Mail,
    MapPin,
    Phone,
} from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalSection,
} from '@/components/modal';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { RecruitmentNav } from '@/features/recruitment/components/recruitment-nav';
import { MODE_LABELS } from '@/features/recruitment/constants';
import { recruitmentRoutes } from '@/features/recruitment/routes';
import type {
    MyInterview,
    MyInterviewsPageProps,
} from '@/features/recruitment/types';
import { cn } from '@/lib/utils';

/**
 * Recruitment → My interviews (ADR 0091): the interviews you are assigned —
 * the ones still to hold or record first, then the ones you have recorded.
 * Open one to see the candidate and give your verdict.
 */
export default function MyInterviews() {
    const { interviews } = usePage<MyInterviewsPageProps>().props;
    const [openId, setOpenId] = useState<number | null>(null);

    const all = [...interviews.pending, ...interviews.done];
    const selected = all.find((interview) => interview.id === openId) ?? null;

    return (
        <>
            <Head title="My interviews" />

            <PageBody>
                <PageHeader
                    title="My interviews"
                    description="Candidates you've been asked to interview. Record your verdict once you've met them."
                    actions={
                        <RecruitmentNav
                            current="interviews"
                            pending={interviews.pending.length}
                        />
                    }
                />

                <InterviewGroup
                    title="To record"
                    interviews={interviews.pending}
                    empty="Nothing waiting. When HR books you on an interview, it appears here."
                    onOpen={setOpenId}
                />

                {interviews.done.length > 0 && (
                    <InterviewGroup
                        title="Recorded"
                        interviews={interviews.done}
                        onOpen={setOpenId}
                    />
                )}
            </PageBody>

            <InterviewDialog
                interview={selected}
                open={selected !== null}
                onOpenChange={(open) => !open && setOpenId(null)}
            />
        </>
    );
}

function InterviewGroup({
    title,
    interviews,
    empty,
    onOpen,
}: {
    title: string;
    interviews: MyInterview[];
    empty?: string;
    onOpen: (id: number) => void;
}) {
    return (
        <section className="flex flex-col gap-2.5">
            <h2 className="text-sm font-semibold">
                {title}{' '}
                <span className="font-normal text-muted-foreground">
                    ({interviews.length})
                </span>
            </h2>

            {interviews.length === 0 ? (
                <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                    {interviews.map((interview) => (
                        <li key={interview.id}>
                            <button
                                type="button"
                                onClick={() => onOpen(interview.id)}
                                className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none"
                            >
                                <PersonAvatar
                                    name={interview.candidate.name}
                                    initials={interview.candidate.initials}
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {interview.candidate.name}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {interview.posting.title}
                                        {interview.posting.department &&
                                            ` · ${interview.posting.department}`}
                                    </p>
                                </div>
                                <div className="hidden shrink-0 text-right sm:block">
                                    <p className="text-sm">
                                        {interview.scheduled_label}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {MODE_LABELS[interview.mode]} ·{' '}
                                        {interview.scheduled_human}
                                    </p>
                                </div>
                                <ResultBadge interview={interview} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function ResultBadge({ interview }: { interview: MyInterview }) {
    const { label, tone } =
        interview.result === 'passed'
            ? {
                  label: 'Passed',
                  tone: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
              }
            : interview.result === 'failed'
              ? {
                    label: 'Not a fit',
                    tone: 'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300',
                }
              : interview.is_past
                ? {
                      label: 'Record verdict',
                      tone: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                  }
                : {
                      label: 'Upcoming',
                      tone: 'border-border bg-muted/50 text-muted-foreground',
                  };

    return (
        <span
            className={cn(
                'shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                tone,
            )}
        >
            {label}
        </span>
    );
}

function InterviewDialog({
    interview,
    open,
    onOpenChange,
}: {
    interview: MyInterview | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="lg">
                {interview && (
                    <>
                        <ModalHeader
                            icon={
                                <PersonAvatar
                                    name={interview.candidate.name}
                                    initials={interview.candidate.initials}
                                    className="size-10"
                                />
                            }
                            title={interview.candidate.name}
                            description={
                                interview.candidate.headline ??
                                `Applying for ${interview.posting.title}`
                            }
                            meta={
                                <span className="text-xs text-muted-foreground">
                                    {[
                                        // The title is already above when
                                        // there is no headline.
                                        interview.candidate.headline
                                            ? interview.posting.title
                                            : null,
                                        interview.posting.department,
                                        interview.stage
                                            ? `Stage: ${interview.stage}`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            }
                        />
                        <VerdictForm
                            key={interview.id}
                            interview={interview}
                            onDone={() => onOpenChange(false)}
                        />
                    </>
                )}
            </ModalContent>
        </Modal>
    );
}

function VerdictForm({
    interview,
    onDone,
}: {
    interview: MyInterview;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        result: interview.result,
        feedback: interview.feedback ?? '',
    });
    const candidate = interview.candidate;
    const location = interview.location;
    const isLink = location !== null && /^https?:\/\//i.test(location);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(recruitmentRoutes.interview(interview.id), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <ModalBody className="space-y-6">
                <ModalSection title="The interview">
                    <ul className="space-y-1.5 text-sm">
                        <li className="flex items-center gap-2">
                            <CalendarClock className="size-4 text-muted-foreground" />
                            {interview.scheduled_label}
                            <span className="text-muted-foreground">
                                · {interview.scheduled_human}
                            </span>
                        </li>
                        <li className="flex items-center gap-2">
                            <MapPin className="size-4 text-muted-foreground" />
                            {MODE_LABELS[interview.mode]}
                            {location &&
                                (isLink ? (
                                    <a
                                        href={location}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 font-medium text-[#078a8a] underline-offset-2 hover:underline dark:text-[#0ABFBF]"
                                    >
                                        Join link
                                        <ExternalLink className="size-3.5" />
                                    </a>
                                ) : (
                                    <span className="text-muted-foreground">
                                        · {location}
                                    </span>
                                ))}
                        </li>
                    </ul>
                    {interview.notes && (
                        <p className="rounded-md bg-muted/40 p-3 text-sm text-muted-foreground">
                            {interview.notes}
                        </p>
                    )}
                </ModalSection>

                <ModalSection title="The candidate">
                    {(candidate.years_experience !== null ||
                        candidate.current_location) && (
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                            {candidate.years_experience !== null && (
                                <Fact
                                    label="Experience"
                                    value={`${candidate.years_experience} ${candidate.years_experience === 1 ? 'year' : 'years'}`}
                                />
                            )}
                            {candidate.current_location && (
                                <Fact
                                    label="Based in"
                                    value={candidate.current_location}
                                />
                            )}
                        </dl>
                    )}
                    <div className="flex flex-wrap gap-2">
                        {candidate.resume_url && (
                            <LinkButton
                                href={candidate.resume_url}
                                icon={<FileText />}
                            >
                                Résumé
                            </LinkButton>
                        )}
                        {candidate.linkedin_url && (
                            <LinkButton
                                href={candidate.linkedin_url}
                                icon={<ExternalLink />}
                            >
                                LinkedIn
                            </LinkButton>
                        )}
                        {candidate.portfolio_url && (
                            <LinkButton
                                href={candidate.portfolio_url}
                                icon={<ExternalLink />}
                            >
                                Portfolio
                            </LinkButton>
                        )}
                        {candidate.email && (
                            <LinkButton
                                href={`mailto:${candidate.email}`}
                                icon={<Mail />}
                            >
                                Email
                            </LinkButton>
                        )}
                        {candidate.phone && (
                            <LinkButton
                                href={`tel:${candidate.phone}`}
                                icon={<Phone />}
                            >
                                {candidate.phone}
                            </LinkButton>
                        )}
                    </div>
                    {candidate.cover_note && (
                        <p className="rounded-md bg-muted/40 p-3 text-sm leading-relaxed text-muted-foreground">
                            {candidate.cover_note}
                        </p>
                    )}
                    {interview.screening.length > 0 && (
                        <ul className="space-y-1 text-sm">
                            {interview.screening.map((answer) => (
                                <li
                                    key={answer.label}
                                    className="flex items-start justify-between gap-4"
                                >
                                    <span className="text-muted-foreground">
                                        {answer.label}
                                    </span>
                                    <span className="shrink-0 font-medium">
                                        {answer.answer ? 'Yes' : 'No'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </ModalSection>

                <ModalSection title="Your verdict">
                    <div
                        role="radiogroup"
                        aria-label="Your verdict"
                        className="grid grid-cols-3 gap-1 rounded-md border border-input p-0.5"
                    >
                        {(
                            [
                                ['pending', 'Not yet'],
                                ['passed', 'Passed'],
                                ['failed', 'Not a fit'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={data.result === value}
                                onClick={() => setData('result', value)}
                                className={cn(
                                    'h-8 rounded-[5px] text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                    data.result === value
                                        ? 'bg-[#0F2044] font-medium text-white dark:bg-[#0ABFBF] dark:text-[#0F2044]'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <FormField
                        label="Feedback"
                        error={errors.feedback ?? errors.result}
                        hint="What stood out, and anything HR should follow up on."
                    >
                        <textarea
                            value={data.feedback}
                            onChange={(e) =>
                                setData('feedback', e.target.value)
                            }
                            rows={4}
                            maxLength={2000}
                            className="flex w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
                        />
                    </FormField>
                </ModalSection>
            </ModalBody>

            <ModalFooter>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Close
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Spinner />}
                    Save verdict
                </Button>
            </ModalFooter>
        </form>
    );
}

function Fact({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

function LinkButton({
    href,
    icon,
    children,
}: {
    href: string;
    icon: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <Button variant="outline" size="sm" asChild>
            <a
                href={href}
                target={href.startsWith('http') ? '_blank' : undefined}
                rel="noopener noreferrer"
            >
                {icon}
                {children}
            </a>
        </Button>
    );
}

MyInterviews.layout = {
    breadcrumbs: [
        { title: 'Recruitment', href: recruitmentRoutes.index },
        { title: 'My interviews', href: recruitmentRoutes.myInterviews },
    ],
};
