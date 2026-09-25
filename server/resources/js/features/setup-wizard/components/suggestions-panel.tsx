import { ChevronDown, ChevronUp, Lightbulb, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /**
     * Whether it can fold away. Until the step's module holds something the
     * suggestions are the way in, so they stay open; after that they are one way
     * among several to add more, and fold to a single line.
     */
    collapsible: boolean;
    title?: string;
    description: ReactNode;
    /** What the folded line says is waiting — "4 departments you haven't added". */
    summary: string;
    onSubmit: () => void;
    submitLabel: string;
    submitDisabled?: boolean;
    processing: boolean;
    /** Beside the button — what adding will actually create. */
    note?: ReactNode;
    children: ReactNode;
};

/**
 * Where a step offers a place to start: the departments, leave, holidays or
 * checklists most companies begin with, each adopted in one click and then
 * editable below like anything else.
 *
 * It is a tray rather than a page — the offers sit on their own muted surface,
 * with the button that adds them at its foot — so it reads as one way in beside
 * the step's editor, not as the step itself. Adding stays on the step: what was
 * added appears in the editor below, ready to be nested, renamed or extended,
 * and the tray folds away.
 */
export default function SuggestionsPanel({
    open,
    onOpenChange,
    collapsible,
    title = 'Start from a suggestion',
    description,
    summary,
    onSubmit,
    submitLabel,
    submitDisabled = false,
    processing,
    note,
    children,
}: Props) {
    if (!open) {
        return (
            <button
                type="button"
                onClick={() => onOpenChange(true)}
                aria-expanded={false}
                className="group flex w-full items-center gap-3 rounded-xl border border-dashed border-sidebar-border bg-muted/20 px-4 py-3 text-left transition-colors hover:border-[#0ABFBF]/50 hover:bg-[#0ABFBF]/[0.03] focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/40 focus-visible:outline-none"
            >
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground transition-colors group-hover:text-[#0a8b91] dark:group-hover:text-[#0ABFBF]">
                    <Plus className="size-4" />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-sm font-medium text-foreground">
                        Add from suggestions
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                        {summary}
                    </span>
                </span>
                <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
            </button>
        );
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!submitDisabled && !processing) {
            onSubmit();
        }
    };

    return (
        <section className="rounded-2xl border border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border">
            <div className="flex items-start gap-3 px-4 pt-4 md:px-5 md:pt-5">
                <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0a8b91] dark:text-[#0ABFBF]">
                    <Lightbulb className="size-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-sm font-semibold text-foreground">
                        {title}
                    </h2>
                    <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {description}
                    </p>
                </div>
                {collapsible && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        aria-expanded
                        className="-mt-1 -mr-2 shrink-0 text-muted-foreground"
                    >
                        Hide
                        <ChevronUp className="size-4" />
                    </Button>
                )}
            </div>

            <form onSubmit={submit}>
                <div className="flex flex-col gap-4 px-3 pt-4 pb-4 sm:px-4 md:px-5">
                    {children}
                </div>

                <div className="flex flex-col-reverse gap-2 border-t border-sidebar-border/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-end md:px-5 dark:border-sidebar-border">
                    {note && (
                        <p className="text-xs text-muted-foreground sm:mr-auto">
                            {note}
                        </p>
                    )}
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={submitDisabled || processing}
                        className="bg-background"
                    >
                        {processing && <Spinner />}
                        {submitLabel}
                    </Button>
                </div>
            </form>
        </section>
    );
}

/**
 * Whether a step's suggestions start open: always, until the step's module holds
 * something — then they fold away behind a single line.
 */
export function useSuggestionsOpen(configured: boolean) {
    return useState(!configured);
}
