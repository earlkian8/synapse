import { Plus, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

type Props = {
    /** What this list is, in the company's terms — "Leave you grant yourself". */
    title: string;
    /** One line on when to reach for it. */
    hint: string;
    /** The wording of the add button, e.g. "Add a leave type". */
    addLabel: string;
    onAdd: () => void;
    /** What the region says while the company has written nothing yet. */
    empty: string;
    children?: ReactNode;
};

/**
 * The part of a step that holds what the company wrote for itself, under the
 * suggestions it could have taken instead.
 *
 * The suggestions above are what SYNAPSE knows; this is what the company knows.
 * That distinction is worth drawing, so everything in here sits on a dashed
 * hairline — the same signal the framework editor already uses for a criterion
 * written by hand — rather than the solid cards an offer comes on. No new
 * colour: the difference is authorship, not importance.
 */
export default function CustomSection({
    title,
    hint,
    addLabel,
    onAdd,
    empty,
    children,
}: Props) {
    return (
        <div>
            <h2 className="text-sm font-semibold text-foreground">{title}</h2>
            <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                {hint}
            </p>

            <div className="mt-3 flex flex-col gap-2.5">
                {children ?? (
                    <p className="rounded-xl border border-dashed border-sidebar-border bg-muted/20 px-4 py-5 text-center text-xs text-muted-foreground">
                        {empty}
                    </p>
                )}

                <Button
                    type="button"
                    variant="outline"
                    className="w-fit"
                    onClick={onAdd}
                >
                    <Plus className="size-4" />
                    {addLabel}
                </Button>
            </div>
        </div>
    );
}

/**
 * One thing the company wrote, on the dashed surface that says so — with the
 * name it is going by and the way to take it back out.
 */
export function CustomRow({
    label,
    onRemove,
    removeLabel,
    children,
}: {
    /** Names the row for a screen reader — "Leave type 2", "Department 1". */
    label: string;
    onRemove: () => void;
    removeLabel: string;
    children: ReactNode;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="rounded-xl border border-dashed border-sidebar-border bg-muted/20 p-3.5"
        >
            <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">{children}</div>

                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={onRemove}
                    aria-label={removeLabel}
                    className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                >
                    <X className="size-4" />
                </Button>
            </div>
        </div>
    );
}
