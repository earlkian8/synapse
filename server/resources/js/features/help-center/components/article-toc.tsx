import { cn } from '@/lib/utils';
import type { HelpHeading } from '../types';
import { useActiveHeading } from '../use-active-heading';

/**
 * "On this page": the article's sections, with the one being read lit up.
 * Clicking one jumps to it and puts its anchor in the address bar, so the
 * section can be shared.
 */
export function ArticleToc({ headings }: { headings: HelpHeading[] }) {
    const active = useActiveHeading(headings.map((heading) => heading.id));

    if (headings.length < 2) {
        return null;
    }

    return (
        <nav aria-labelledby="help-toc-title">
            <p
                id="help-toc-title"
                className="mb-3 text-[11px] font-semibold tracking-[0.12em] text-muted-foreground uppercase"
            >
                On this page
            </p>
            <ul className="space-y-0.5 border-l border-border">
                {headings.map((heading) => (
                    <li key={heading.id}>
                        <a
                            href={`#${heading.id}`}
                            aria-current={
                                heading.id === active ? 'location' : undefined
                            }
                            className={cn(
                                '-ml-px block border-l-2 py-1 text-[13px] leading-snug transition-colors',
                                heading.level === 3 ? 'pl-6' : 'pl-3.5',
                                heading.id === active
                                    ? 'border-[#0ABFBF] font-medium text-foreground'
                                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                            )}
                        >
                            {heading.title}
                        </a>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
