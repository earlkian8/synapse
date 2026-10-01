import { Check } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** `single` renders a radio, `multiple` a checkbox — the real input, hidden. */
    mode: 'single' | 'multiple';
    /** Shared across a radio group; ignored for checkboxes. */
    name: string;
    value: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    /** Shown at the top right — a count, a badge, a hint. */
    aside?: ReactNode;
    /**
     * An action the card carries rather than a choice it offers — "Customise",
     * typically. It sits inside the label so it travels with the card, and
     * swallows its own click so pressing it never also ticks the box.
     */
    action?: ReactNode;
    /** The detail a card opens up to show: stages, sections, criteria. */
    children?: ReactNode;
    disabled?: boolean;
    className?: string;
    /**
     * Drop the card chrome and keep only the control. Used where the row itself
     * is already the surface — the leave table, where a nested card inside a
     * table row reads as a second, smaller thing to click.
     */
    bare?: boolean;
};

/**
 * A card you pick rather than a field you fill in — the wizard's main control.
 *
 * Setup is a series of decisions a company makes once, and this codebase already
 * treats a decision as a choice from a named list rather than free text (see the
 * framework editor's criteria). The real input is kept in the DOM and hidden, so
 * keyboard, screen readers and form semantics all behave natively and the card
 * only has to style `peer-checked`.
 */
export default function ChoiceCard({
    mode,
    name,
    value,
    checked,
    onChange,
    title,
    description,
    aside,
    action,
    children,
    disabled = false,
    className,
    bare = false,
}: Props) {
    return (
        <label
            className={cn(
                'group relative block',
                disabled ? 'cursor-not-allowed' : 'cursor-pointer',
                className,
            )}
        >
            <input
                type={mode === 'single' ? 'radio' : 'checkbox'}
                name={name}
                value={value}
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="peer sr-only"
            />

            <span
                className={cn(
                    'flex h-full flex-col gap-2 text-left transition-colors',
                    'peer-focus-visible:ring-2 peer-focus-visible:ring-[#0ABFBF]/40 peer-focus-visible:outline-none',
                    bare
                        ? 'rounded-md'
                        : [
                              'rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border',
                              disabled
                                  ? 'opacity-55'
                                  : 'hover:border-[#0ABFBF]/50 hover:bg-[#0ABFBF]/[0.03]',
                              'peer-checked:border-[#0ABFBF] peer-checked:bg-[#0ABFBF]/[0.06]',
                          ],
                    disabled && bare && 'opacity-55',
                )}
            >
                <span className="flex items-start gap-3">
                    <Marker checked={checked} round={mode === 'single'} />

                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-foreground">
                            {title}
                        </span>
                        {description && (
                            <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                {description}
                            </span>
                        )}
                    </span>

                    {aside && (
                        <span className="shrink-0 text-xs text-muted-foreground">
                            {aside}
                        </span>
                    )}
                </span>

                {children && <span className="block">{children}</span>}

                {action && (
                    <span
                        className="block"
                        // The card is a label: without this, pressing the button
                        // inside it would also toggle the control it points at.
                        onClick={(event) => {
                            event.preventDefault();
                            event.stopPropagation();
                        }}
                    >
                        {action}
                    </span>
                )}
            </span>
        </label>
    );
}

/** The tick box / radio dot, drawn rather than native so it matches the card. */
function Marker({ checked, round }: { checked: boolean; round: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'mt-0.5 flex size-4 shrink-0 items-center justify-center border transition-colors',
                round ? 'rounded-full' : 'rounded-[5px]',
                checked
                    ? 'border-[#0ABFBF] bg-[#0ABFBF] text-white'
                    : 'border-input bg-transparent',
            )}
        >
            {checked &&
                (round ? (
                    <span className="size-1.5 rounded-full bg-white" />
                ) : (
                    <Check className="size-3" strokeWidth={3.5} />
                ))}
        </span>
    );
}
