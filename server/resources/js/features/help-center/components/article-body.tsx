import { Link } from '@inertiajs/react';
import {
    CircleAlert,
    Info,
    Lightbulb,
    Link2,
    TriangleAlert,
} from 'lucide-react';
import { memo } from 'react';
import type { ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { cn } from '@/lib/utils';
import { remarkHelp } from '../lib/markdown';
import type { CalloutKind } from '../lib/markdown';

/** An address inside SYNAPSE — "/leave", never "//host" or a full URL. */
function isInternal(href: string | undefined): href is string {
    return (
        !!href &&
        href.startsWith('/') &&
        !href.startsWith('//') &&
        !href.startsWith('/\\')
    );
}

const CALLOUTS: Record<
    CalloutKind,
    { label: string; icon: typeof Info; tone: string; iconTone: string }
> = {
    note: {
        label: 'Note',
        icon: Info,
        tone: 'border-sky-500/25 bg-sky-500/[0.06]',
        iconTone: 'text-sky-600 dark:text-sky-400',
    },
    tip: {
        label: 'Tip',
        icon: Lightbulb,
        tone: 'border-[#0ABFBF]/30 bg-[#0ABFBF]/[0.07]',
        iconTone: 'text-[#0ABFBF]',
    },
    important: {
        label: 'Important',
        icon: CircleAlert,
        tone: 'border-violet-500/25 bg-violet-500/[0.06]',
        iconTone: 'text-violet-600 dark:text-violet-400',
    },
    warning: {
        label: 'Warning',
        icon: TriangleAlert,
        tone: 'border-amber-500/30 bg-amber-500/[0.07]',
        iconTone: 'text-amber-600 dark:text-amber-400',
    },
};

/** A section heading with a link to itself, shown on hover or focus. */
function Heading({
    as: Tag,
    id,
    className,
    children,
}: {
    as: 'h2' | 'h3';
    id?: string;
    className: string;
    children: ReactNode;
}) {
    return (
        <Tag id={id} className={cn('group scroll-mt-24', className)}>
            {children}
            {id && (
                <a
                    href={`#${id}`}
                    aria-label="Link to this section"
                    className="ml-2 inline-flex align-middle text-muted-foreground/0 transition-colors group-hover:text-muted-foreground/60 hover:!text-[#0ABFBF] focus-visible:text-[#0ABFBF]"
                >
                    <Link2 className="size-4" />
                </a>
            )}
        </Tag>
    );
}

/**
 * How an article reads: the app's own type scale and colours, a heading anchor
 * for every section, callouts for notes, tips and warnings, and tables that
 * scroll on a narrow screen. Links to a section stay on the page, links to
 * SYNAPSE's own pages are Inertia visits, and anything else opens in a new tab. Articles are written by us, not by users,
 * but images are still not drawn — the manual describes screens in words, and
 * a picture would go stale the first time a screen changes.
 */
const COMPONENTS: Components = {
    h2: ({ id, children }) => (
        <Heading
            as="h2"
            id={id}
            className="mt-10 mb-3 border-t border-border/60 pt-8 text-xl font-semibold tracking-tight first:mt-0 first:border-t-0 first:pt-0"
        >
            {children}
        </Heading>
    ),
    h3: ({ id, children }) => (
        <Heading
            as="h3"
            id={id}
            className="mt-7 mb-2 text-base font-semibold tracking-tight"
        >
            {children}
        </Heading>
    ),
    p: ({ children }) => (
        <p className="my-3.5 leading-7 text-foreground/85">{children}</p>
    ),
    a: ({ href, children }) =>
        href?.startsWith('#') ? (
            <a
                href={href}
                className="font-medium text-[#0A9E9E] underline decoration-[#0ABFBF]/40 underline-offset-[3px] transition-colors hover:decoration-[#0ABFBF] dark:text-[#0ABFBF]"
            >
                {children}
            </a>
        ) : isInternal(href) ? (
            <Link
                href={href}
                className="font-medium text-[#0A9E9E] underline decoration-[#0ABFBF]/40 underline-offset-[3px] transition-colors hover:decoration-[#0ABFBF] dark:text-[#0ABFBF]"
            >
                {children}
            </Link>
        ) : (
            <a
                href={href}
                target="_blank"
                rel="noopener noreferrer"
                className="font-medium text-[#0A9E9E] underline decoration-[#0ABFBF]/40 underline-offset-[3px] hover:decoration-[#0ABFBF] dark:text-[#0ABFBF]"
            >
                {children}
            </a>
        ),
    img: () => null,
    ul: ({ children }) => (
        <ul className="my-3.5 ml-5 list-disc space-y-1.5 leading-7 text-foreground/85 marker:text-[#0ABFBF]/70">
            {children}
        </ul>
    ),
    ol: ({ children }) => (
        <ol className="my-3.5 ml-5 list-decimal space-y-1.5 leading-7 text-foreground/85 marker:font-semibold marker:text-muted-foreground">
            {children}
        </ol>
    ),
    li: ({ children }) => (
        <li className="pl-1.5 [&>ol]:my-1.5 [&>p]:my-1 [&>ul]:my-1.5">
            {children}
        </li>
    ),
    strong: ({ children }) => (
        <strong className="font-semibold text-foreground">{children}</strong>
    ),
    em: ({ children }) => <em className="italic">{children}</em>,
    code: ({ className, children }) =>
        (className ?? '').includes('language-') ? (
            <code className="block font-mono text-[13px] leading-relaxed">
                {children}
            </code>
        ) : (
            <code className="rounded-md border border-border/70 bg-muted/60 px-1.5 py-0.5 font-mono text-[0.85em] text-foreground">
                {children}
            </code>
        ),
    pre: ({ children }) => (
        <pre className="my-4 overflow-x-auto rounded-xl border border-border bg-muted/50 p-4">
            {children}
        </pre>
    ),
    blockquote: ({ children }) => (
        <blockquote className="my-4 border-l-2 border-[#0ABFBF]/50 pl-4 text-muted-foreground italic">
            {children}
        </blockquote>
    ),
    table: ({ children }) => (
        <div className="my-5 overflow-x-auto rounded-xl border border-border">
            <table className="w-full border-collapse text-left text-sm">
                {children}
            </table>
        </div>
    ),
    thead: ({ children }) => <thead className="bg-muted/50">{children}</thead>,
    th: ({ children }) => (
        <th className="border-b border-border px-3.5 py-2.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </th>
    ),
    td: ({ children }) => (
        <td className="border-b border-border/60 px-3.5 py-2.5 align-top leading-6 text-foreground/85 [tr:last-child>&]:border-b-0">
            {children}
        </td>
    ),
    hr: () => <hr className="my-8 border-border" />,
    // A callout: `remarkHelp` turns `> [!TIP]` into `<aside data-callout="tip">`.
    aside: ({ children, ...props }) => {
        const kind = (props as Record<string, unknown>)['data-callout'];
        const callout = CALLOUTS[kind as CalloutKind] ?? CALLOUTS.note;
        const Icon = callout.icon;

        return (
            <aside
                role="note"
                aria-label={callout.label}
                className={cn(
                    'my-5 flex gap-3 rounded-xl border px-4 py-3.5',
                    callout.tone,
                )}
            >
                <Icon
                    aria-hidden
                    className={cn(
                        'mt-1 size-[18px] shrink-0',
                        callout.iconTone,
                    )}
                />
                <div className="min-w-0 flex-1 [&_p]:my-2 [&_p]:leading-relaxed [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
                    {children}
                </div>
            </aside>
        );
    },
};

/** An article's body, rendered from its Markdown. */
export const ArticleBody = memo(function ArticleBody({
    markdown,
}: {
    markdown: string;
}) {
    return (
        <div className="text-[15px] break-words">
            <ReactMarkdown
                remarkPlugins={[remarkGfm, remarkHelp]}
                components={COMPONENTS}
            >
                {markdown}
            </ReactMarkdown>
        </div>
    );
});
