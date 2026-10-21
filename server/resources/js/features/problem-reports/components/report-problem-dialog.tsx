import { Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronRight,
    Copy,
    Info,
    MessageSquareWarning,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ClipboardEvent, FormEvent } from 'react';
import { FormField } from '@/components/form-field';
import InputError from '@/components/input-error';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { displayContext, sendReport } from '../api';
import { CATEGORIES, IMPACTS } from '../constants';
import { problemReportLauncher, useProblemReportOpen } from '../launcher';
import { problemReportRoutes } from '../routes';
import type { ProblemReport, ReportCategory, ReportImpact } from '../types';
import { ScreenshotField, takeScreenshots } from './screenshot-field';
import type { Screenshot } from './screenshot-field';

/**
 * Report a problem (ADR 0096) — mounted once in the app layout and opened from
 * the Help menu, the Help Center and Your reports. It writes to the SYNAPSE
 * team, not to the company: the page it was opened on and a few facts about
 * the window go with it, shown before sending, and a screenshot can be pasted
 * straight in. Once sent, it says the reference and where replies will appear.
 */
export function ReportProblemDialog() {
    const open = useProblemReportOpen();
    const [dirty, setDirty] = useState(false);
    const [sending, setSending] = useState(false);
    const [sent, setSent] = useState<ProblemReport | null>(null);

    const setOpen = (next: boolean) => {
        if (next) {
            problemReportLauncher.open();

            return;
        }

        if (sending) {
            return;
        }

        problemReportLauncher.close();
        setDirty(false);
    };

    // A new opening starts a new report; the last one's confirmation is gone.
    const [wasOpen, setWasOpen] = useState(open);

    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setSent(null);
        }
    }

    return (
        <Modal open={open} onOpenChange={setOpen}>
            <ModalContent
                size="lg"
                // A click beside the dialog must not throw away a half-written report.
                onInteractOutside={(event) => {
                    if (dirty || sending) {
                        event.preventDefault();
                    }
                }}
            >
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <MessageSquareWarning />
                        </ModalIcon>
                    }
                    title="Report a problem"
                    description="Tell the SYNAPSE team what went wrong. Every report is read, and replies reach you in your notifications."
                />
                {open &&
                    (sent ? (
                        <Sent report={sent} onDone={() => setOpen(false)} />
                    ) : (
                        <ReportForm
                            onDirty={setDirty}
                            onSending={setSending}
                            onSent={(report) => {
                                setDirty(false);
                                setSent(report);
                            }}
                            onCancel={() => setOpen(false)}
                        />
                    ))}
            </ModalContent>
        </Modal>
    );
}

/** The errors less one field's, once it has been changed. */
function without(
    errors: Record<string, string>,
    field: string,
): Record<string, string> {
    return Object.fromEntries(
        Object.entries(errors).filter(([key]) => key !== field),
    );
}

function ReportForm({
    onDirty,
    onSending,
    onSent,
    onCancel,
}: {
    onDirty: (dirty: boolean) => void;
    onSending: (sending: boolean) => void;
    onSent: (report: ProblemReport) => void;
    onCancel: () => void;
}) {
    const { currentUrl } = useCurrentUrl();
    const [category, setCategory] = useState<ReportCategory | null>(null);
    const [impact, setImpact] = useState<ReportImpact | null>(null);
    const [subject, setSubject] = useState('');
    const [description, setDescription] = useState('');
    const [screenshots, setScreenshots] = useState<Screenshot[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [context] = useState(() => displayContext());

    // Previews are object URLs made as files arrive; give them all back when
    // the form goes away. (Removing one gives its own back at once.)
    const previews = useRef(new Set<string>());
    useEffect(() => {
        const urls = previews.current;

        return () => urls.forEach((url) => URL.revokeObjectURL(url));
    }, []);

    const touched = () => onDirty(true);

    const addScreenshots = (files: File[]) => {
        if (files.length === 0) {
            return;
        }

        const { added, problem } = takeScreenshots(files, screenshots);

        added.forEach((shot) => previews.current.add(shot.preview));
        setScreenshots((current) => [...current, ...added]);
        setErrors((current) => ({
            ...without(current, 'screenshots'),
            ...(problem ? { screenshots: problem } : {}),
        }));
        touched();
    };

    const removeScreenshot = (id: string) => {
        const shot = screenshots.find((item) => item.id === id);

        if (shot) {
            URL.revokeObjectURL(shot.preview);
            previews.current.delete(shot.preview);
        }

        setScreenshots((current) => current.filter((item) => item.id !== id));
        setErrors((current) => without(current, 'screenshots'));
    };

    // A screenshot pasted anywhere in the form — the quickest way to attach
    // the one just taken. Text pastes into its field as usual.
    const paste = (event: ClipboardEvent<HTMLFormElement>) => {
        const files = Array.from(event.clipboardData.files);

        if (files.length > 0) {
            event.preventDefault();
            addScreenshots(files);
        }
    };

    const ready =
        category !== null &&
        impact !== null &&
        subject.trim().length >= 4 &&
        description.trim().length >= 10;

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (!category || !impact) {
            setErrors({
                ...(category
                    ? {}
                    : { category: 'Pick what kind of problem it is.' }),
                ...(impact
                    ? {}
                    : { impact: 'Say how much it’s getting in your way.' }),
            });

            return;
        }

        setBusy(true);
        onSending(true);
        setFailure(null);

        const outcome = await sendReport({
            category,
            impact,
            subject,
            description,
            page: currentUrl,
            screenshots: screenshots.map((shot) => shot.file),
        });

        setBusy(false);
        onSending(false);

        if (outcome.ok) {
            onSent(outcome.report);

            // Your reports, if it is the page underneath, lists the new one.
            if (currentUrl === problemReportRoutes.index) {
                router.reload({ only: ['reports'] });
            }

            return;
        }

        setErrors(outcome.errors);
        setFailure(outcome.message);
    };

    return (
        <form
            onSubmit={submit}
            onPaste={paste}
            className="flex min-h-0 flex-1 flex-col"
            noValidate
        >
            <ModalBody className="space-y-5">
                <FormField
                    label="What kind of problem?"
                    required
                    group
                    error={errors.category}
                >
                    <ChoiceCards
                        name="category"
                        value={category}
                        onChange={(value) => {
                            setCategory(value);
                            setErrors((current) =>
                                without(current, 'category'),
                            );
                            touched();
                        }}
                        disabled={busy}
                    />
                </FormField>

                <FormField label="Summary" required error={errors.subject}>
                    <Input
                        value={subject}
                        onChange={(event) => {
                            setSubject(event.target.value);
                            touched();
                        }}
                        placeholder="e.g. Saving a leave request does nothing"
                        maxLength={120}
                        disabled={busy}
                    />
                </FormField>

                <FormField
                    label="What happened?"
                    required
                    error={errors.description}
                    hint="What you were doing, what you expected, and what happened instead. Copy in any error message you saw."
                >
                    <textarea
                        value={description}
                        onChange={(event) => {
                            setDescription(event.target.value);
                            touched();
                        }}
                        rows={5}
                        maxLength={5000}
                        disabled={busy}
                        placeholder={'1. I opened …\n2. I pressed …\n3. Then …'}
                        className="flex min-h-28 w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground/70 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20"
                    />
                </FormField>

                <FormField
                    label="How much is it getting in your way?"
                    required
                    group
                    error={errors.impact}
                >
                    <ImpactChoice
                        value={impact}
                        onChange={(value) => {
                            setImpact(value);
                            setErrors((current) => without(current, 'impact'));
                            touched();
                        }}
                        disabled={busy}
                    />
                </FormField>

                <FormField
                    label="Screenshots"
                    group
                    error={errors.screenshots}
                    hint={`Optional, up to 3. A picture of the screen often says it faster.`}
                >
                    <ScreenshotField
                        value={screenshots}
                        onAdd={addScreenshots}
                        onRemove={removeScreenshot}
                        error={errors.screenshots}
                        disabled={busy}
                    />
                </FormField>

                <SentWithIt page={currentUrl} context={context} />

                <p className="flex gap-2 text-xs leading-relaxed text-muted-foreground">
                    <Info className="mt-px size-3.5 shrink-0" />
                    <span>
                        A mistake in your own records — a leave balance, a time
                        entry, your details? Your HR team corrects those, not
                        the SYNAPSE team.
                    </span>
                </p>

                {failure && <InputError message={failure} role="alert" />}
            </ModalBody>

            <ModalFooter>
                <Button
                    variant="ghost"
                    size="sm"
                    asChild
                    className="mr-auto px-2 text-muted-foreground"
                >
                    <Link href={problemReportRoutes.index} onClick={onCancel}>
                        Your reports
                    </Link>
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={busy}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={busy || !ready}>
                    {busy && <Spinner />}
                    Send report
                </Button>
            </ModalFooter>
        </form>
    );
}

/** The four kinds of problem, as cards: an icon, the name, a line of example. */
function ChoiceCards({
    name,
    value,
    onChange,
    disabled,
}: {
    name: string;
    value: ReportCategory | null;
    onChange: (value: ReportCategory) => void;
    disabled?: boolean;
}) {
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {CATEGORIES.map((option) => {
                const checked = value === option.value;

                return (
                    <label
                        key={option.value}
                        className={cn(
                            'flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                            checked
                                ? 'border-[#0ABFBF] bg-[#0ABFBF]/6'
                                : 'border-input hover:bg-muted/40',
                            disabled && 'cursor-not-allowed opacity-60',
                        )}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={checked}
                            onChange={() => onChange(option.value)}
                            disabled={disabled}
                            className="sr-only"
                        />
                        <span
                            className={cn(
                                'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg transition-colors',
                                checked
                                    ? 'bg-[#0ABFBF] text-white'
                                    : 'bg-muted text-muted-foreground',
                            )}
                        >
                            <option.icon className="size-4" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-medium">
                                {option.label}
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                {option.hint}
                            </span>
                        </span>
                    </label>
                );
            })}
        </div>
    );
}

/** Three answers on one segmented track, from most in the way to least. */
function ImpactChoice({
    value,
    onChange,
    disabled,
}: {
    value: ReportImpact | null;
    onChange: (value: ReportImpact) => void;
    disabled?: boolean;
}) {
    return (
        <div className="grid gap-1 rounded-lg border border-input bg-muted/40 p-1 sm:grid-cols-3">
            {IMPACTS.map((option) => {
                const checked = value === option.value;

                return (
                    <label
                        key={option.value}
                        className={cn(
                            'flex cursor-pointer items-center justify-center rounded-md px-2.5 py-1.5 text-center text-sm transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                            checked
                                ? 'bg-background font-medium text-foreground shadow-sm ring-1 ring-[#0ABFBF]/60'
                                : 'text-muted-foreground hover:text-foreground',
                            disabled && 'cursor-not-allowed opacity-60',
                        )}
                    >
                        <input
                            type="radio"
                            name="impact"
                            value={option.value}
                            checked={checked}
                            onChange={() => onChange(option.value)}
                            disabled={disabled}
                            className="sr-only"
                        />
                        {option.label}
                    </label>
                );
            })}
        </div>
    );
}

/**
 * Exactly what goes with the report besides what was typed, shown before it
 * is sent: who is sending it, the page, and the window.
 */
function SentWithIt({
    page,
    context,
}: {
    page: string;
    context: Record<string, string>;
}) {
    const { auth } = usePage().props;
    const id = useId();
    const [shown, setShown] = useState(false);

    const facts: [string, string][] = [
        ['From', `${auth.user?.full_name ?? ''} · ${auth.user?.email ?? ''}`],
        ['Workspace', auth.organization?.name ?? '—'],
        ['Page', page],
        ['Browser', navigator.userAgent],
        ['Window', `${context.viewport} on a ${context.screen} screen`],
        ['Time zone', context.timezone || '—'],
        ['Language', context.language || '—'],
        ['Theme', context.theme === 'dark' ? 'Dark' : 'Light'],
    ];

    return (
        <div className="rounded-lg border border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border">
            <button
                type="button"
                onClick={() => setShown((value) => !value)}
                aria-expanded={shown}
                aria-controls={id}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-xs text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <ChevronRight
                    className={cn(
                        'size-3.5 shrink-0 transition-transform',
                        shown && 'rotate-90',
                    )}
                />
                <span className="min-w-0 flex-1 truncate">
                    Sent with your report:{' '}
                    <span className="font-medium text-foreground">
                        your name, this page ({page}) and your browser
                    </span>
                </span>
            </button>
            {shown && (
                <dl
                    id={id}
                    className="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 gap-y-1.5 border-t border-sidebar-border/70 px-3 py-2.5 text-xs dark:border-sidebar-border"
                >
                    {facts.map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="break-words">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

/** The report is in: its reference, and where the answer will come. */
function Sent({
    report,
    onDone,
}: {
    report: ProblemReport;
    onDone: () => void;
}) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void navigator.clipboard?.writeText(report.reference).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1600);
        });
    };

    return (
        <>
            <ModalBody className="flex flex-col items-center px-6 py-10 text-center">
                <span className="flex size-14 items-center justify-center rounded-full bg-[#0ABFBF]/12 text-[#0A9E9E] ring-8 ring-[#0ABFBF]/6 motion-safe:animate-in motion-safe:zoom-in-75 motion-safe:fade-in dark:text-[#0ABFBF]">
                    <Check className="size-7" strokeWidth={2.5} />
                </span>
                <h3 className="mt-5 text-lg font-semibold tracking-tight">
                    Report sent
                </h3>
                <p className="mt-1.5 max-w-sm text-sm text-muted-foreground">
                    The SYNAPSE team has it. You’ll get a notification when they
                    pick it up or reply, and an email if you get those.
                </p>

                <button
                    type="button"
                    onClick={copy}
                    className="mt-5 inline-flex items-center gap-2 rounded-lg border border-sidebar-border/70 bg-muted/40 px-3 py-1.5 text-sm transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:border-sidebar-border"
                    aria-label={`Copy reference ${report.reference}`}
                >
                    <span className="text-muted-foreground">Reference</span>
                    <span className="font-semibold tracking-wide tabular-nums">
                        {report.reference}
                    </span>
                    {copied ? (
                        <Check className="size-3.5 text-[#0A9E9E] dark:text-[#0ABFBF]" />
                    ) : (
                        <Copy className="size-3.5 text-muted-foreground" />
                    )}
                </button>
                <span className="sr-only" aria-live="polite">
                    {copied ? 'Reference copied' : ''}
                </span>
            </ModalBody>

            <ModalFooter>
                <Button variant="outline" asChild>
                    <Link
                        href={problemReportRoutes.show(report.reference)}
                        onClick={onDone}
                    >
                        View your reports
                    </Link>
                </Button>
                <Button type="button" onClick={onDone}>
                    Done
                </Button>
            </ModalFooter>
        </>
    );
}
