import { Link, usePage } from '@inertiajs/react';
import { Compass, LifeBuoy, Signpost, Sparkles } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { assistantLauncher } from '@/features/assistant/launcher';
import { useProductTour } from '@/features/product-tour/use-product-tour';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';

type Option = {
    key: string;
    icon: LucideIcon;
    title: string;
    description: string;
} & ({ onClick: () => void } | { href: string });

/**
 * Where to turn when the manual hasn't answered it: the assistant (for people
 * it is offered to — it opens with the question started, never sent), the
 * tour, the Setup Guide for whoever sets the company up, and the questions
 * people ask most.
 */
export function StillNeedHelp({
    topic,
    compact = false,
}: {
    /** What the reader was looking at, to start the assistant's question. */
    topic?: string;
    compact?: boolean;
}) {
    const { auth } = usePage().props;
    const { can } = usePermissions();
    const { start } = useProductTour();

    const options: Option[] = [
        ...(auth.assistant
            ? [
                  {
                      key: 'assistant',
                      icon: Sparkles,
                      title: 'Ask the assistant',
                      description:
                          'Ask in plain words — it answers from your workspace, within your access.',
                      onClick: () =>
                          assistantLauncher.open(
                              topic ? `About “${topic}”: ` : '',
                          ),
                  },
              ]
            : []),
        {
            key: 'tour',
            icon: Signpost,
            title: 'Take the tour',
            description:
                'A one-minute look around the parts of the app you can use.',
            onClick: () => start('manual'),
        },
        ...(can('setup.company.manage')
            ? [
                  {
                      key: 'setup',
                      icon: Compass,
                      title: 'Open the Setup Guide',
                      description: 'Pick up a company setup step you skipped.',
                      href: '/setup/wizard',
                  },
              ]
            : []),
        {
            key: 'faq',
            icon: LifeBuoy,
            title: 'Common questions',
            description:
                'A missing screen, a code that didn’t arrive, a refused punch — and what to do.',
            href: '/help/troubleshooting/faq',
        },
    ];

    return (
        <section
            aria-labelledby="still-need-help"
            className={cn(
                'rounded-2xl border border-sidebar-border/70 bg-muted/30 dark:border-sidebar-border',
                compact ? 'p-5' : 'p-6 sm:p-8',
            )}
        >
            <h2
                id="still-need-help"
                className={cn(
                    'font-semibold tracking-tight',
                    compact ? 'text-base' : 'text-lg',
                )}
            >
                Still need help?
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">
                {compact
                    ? 'Other ways to get an answer:'
                    : 'If you can’t find what you’re looking for, try one of these — or ask whoever manages SYNAPSE at your company.'}
            </p>

            <div
                className={cn(
                    'mt-4 grid gap-3',
                    compact
                        ? 'sm:grid-cols-2'
                        : 'sm:grid-cols-2 xl:grid-cols-4',
                )}
            >
                {options.map((option) => {
                    const content = (
                        <>
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <option.icon className="size-[18px]" />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">
                                    {option.title}
                                </span>
                                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                    {option.description}
                                </span>
                            </span>
                        </>
                    );
                    const className =
                        'flex items-start gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 text-left transition-colors hover:border-[#0ABFBF]/50 dark:border-sidebar-border';

                    return 'href' in option ? (
                        <Link
                            key={option.key}
                            href={option.href}
                            className={className}
                        >
                            {content}
                        </Link>
                    ) : (
                        <button
                            key={option.key}
                            type="button"
                            onClick={option.onClick}
                            className={className}
                        >
                            {content}
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
