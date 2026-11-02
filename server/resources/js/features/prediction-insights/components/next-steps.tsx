import { Link } from '@inertiajs/react';
import { ArrowUpRight, MessageCircleQuestion } from 'lucide-react';
import type { NextStep } from '../types';

/**
 * Suggested next steps, each tied to the reason on record that prompts it, so a
 * suggestion can be weighed rather than followed. A conversation step lists the
 * questions worth asking; a step another screen can take links to it.
 */
export function NextSteps({ steps }: { steps: NextStep[] }) {
    if (steps.length === 0) {
        return null;
    }

    return (
        <ol className="flex flex-col divide-y divide-border rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            {steps.map((step, index) => (
                <li key={step.key} className="flex gap-3 px-4 py-3">
                    <span
                        aria-hidden
                        className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF]/12 text-[11px] font-semibold text-[#078a8a] tabular-nums dark:text-[#0ABFBF]"
                    >
                        {index + 1}
                    </span>
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <p className="text-sm font-medium">{step.title}</p>
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            {step.because}
                        </p>
                        {step.prompts && step.prompts.length > 0 && (
                            <ul className="mt-1 flex flex-col gap-1">
                                {step.prompts.map((prompt) => (
                                    <li
                                        key={prompt}
                                        className="flex items-start gap-1.5 text-xs text-foreground/85"
                                    >
                                        <MessageCircleQuestion className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                        {prompt}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {step.link && (
                            <Link
                                href={step.link.href}
                                className="mt-0.5 inline-flex w-fit items-center gap-0.5 rounded-sm text-xs font-medium text-[#078a8a] hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:text-[#0ABFBF]"
                            >
                                {step.link.label}
                                <ArrowUpRight className="size-3" />
                            </Link>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}
