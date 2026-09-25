import type { ReactNode } from 'react';

/**
 * The scrolling middle of a step. Kept in one place so every step's content sits
 * on the same measure — wide enough for the Company Setup editors a step carries
 * (the map beside its sites, a week of roster) — and the footer below it never
 * moves between steps.
 */
export default function StepBody({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-6 md:px-8">
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-5">
                {children}
            </div>
        </div>
    );
}
