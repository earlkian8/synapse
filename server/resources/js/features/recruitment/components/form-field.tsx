import { Slot } from '@radix-ui/react-slot';
import { useId } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    required?: boolean;
    error?: string;
    hint?: string;
    className?: string;
    /**
     * Set when the field holds a cluster of controls rather than one input (the
     * rating stars, say) — the label then names the group instead of a control.
     */
    group?: boolean;
    children: React.ReactNode;
};

/**
 * One labelled control in a recruitment form. It wires the label, the hint and
 * the validation error to the control itself, so a screen reader announces the
 * field's name, its guidance, and what went wrong — and the input picks up the
 * `aria-invalid` error styling for free.
 */
export function FormField({
    label,
    required = false,
    error,
    hint,
    className,
    group = false,
    children,
}: Props) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    const describedBy =
        [error ? errorId : null, hint && !error ? hintId : null]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className={className}>
            <Label
                id={group ? `${id}-label` : undefined}
                htmlFor={group ? undefined : id}
                className={cn('mb-1.5 block', group && 'cursor-default')}
            >
                {label}
                {required && (
                    <>
                        <span
                            className="ml-0.5 text-destructive"
                            aria-hidden="true"
                        >
                            *
                        </span>
                        <span className="sr-only">(required)</span>
                    </>
                )}
            </Label>

            {group ? (
                <div
                    role="group"
                    aria-labelledby={`${id}-label`}
                    aria-describedby={describedBy}
                >
                    {children}
                </div>
            ) : (
                <Slot
                    id={id}
                    aria-describedby={describedBy}
                    aria-invalid={error ? true : undefined}
                >
                    {children}
                </Slot>
            )}

            {hint && !error && (
                <p id={hintId} className="mt-1.5 text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError
                id={errorId}
                role="alert"
                message={error}
                className="mt-1.5"
            />
        </div>
    );
}
