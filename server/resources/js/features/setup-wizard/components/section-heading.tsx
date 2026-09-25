import type { ReactNode } from 'react';

/**
 * The heading over a step's Company Setup editor — "Your departments" — set as
 * a section of the step rather than a page title, because the step's own header
 * already says what the page is.
 */
export default function SectionHeading({
    title,
    hint,
}: {
    title: string;
    hint?: ReactNode;
}) {
    return (
        <div className="min-w-0">
            <h2 className="text-sm font-semibold text-foreground">{title}</h2>
            {hint && (
                <p className="mt-0.5 max-w-xl text-xs leading-relaxed text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}
