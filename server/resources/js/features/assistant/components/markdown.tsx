import { memo } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';

/**
 * An app-relative path — "/leave", never "//host" or a full URL. The same rule
 * as the server's ReplyGuard.
 */
function isInternal(href: string | undefined): href is string {
    return (
        !!href &&
        href.startsWith('/') &&
        !href.startsWith('//') &&
        !href.startsWith('/\\')
    );
}

/** The host of an external URL, for showing where it would have gone. */
function hostOf(href: string | undefined): string | null {
    try {
        return href ? new URL(href).host : null;
    } catch {
        return null;
    }
}

/**
 * Compact, theme-aware markdown for assistant replies: GitHub-flavoured (tables,
 * lists, strikethrough), with styling tuned for a chat bubble.
 *
 * A reply may have been steered by text in a record (ADR 0049), and the classic
 * end of that is a leak — an image that fetches `https://…?q=<data>` the moment
 * it renders, or a link that carries it on click. So the renderer draws no
 * images at all, and links only to the app's own pages; anything else is shown
 * as plain text with its host, never as something to click. The server's
 * ReplyGuard applies the same rule before a reply is stored, so this is the
 * second line, and the one that covers replies stored before it existed.
 */
const COMPONENTS: Components = {
    p: ({ children }) => (
        <p className="my-1.5 first:mt-0 last:mb-0">{children}</p>
    ),
    a: ({ children, href }) => {
        if (isInternal(href)) {
            return (
                <a
                    href={href}
                    className="font-medium text-[#0ABFBF] underline decoration-[#0ABFBF]/40 underline-offset-2 hover:decoration-[#0ABFBF]"
                >
                    {children}
                </a>
            );
        }

        const host = hostOf(href);

        return (
            <span title="External links are not opened from the assistant">
                {children}
                {host && !String(children).includes(host) && (
                    <span className="text-muted-foreground"> ({host})</span>
                )}
            </span>
        );
    },
    img: ({ alt }) =>
        alt ? <span className="text-muted-foreground">[{alt}]</span> : null,
    ul: ({ children }) => (
        <ul className="my-1.5 ml-4 list-disc space-y-1 marker:text-muted-foreground">
            {children}
        </ul>
    ),
    ol: ({ children }) => (
        <ol className="my-1.5 ml-4 list-decimal space-y-1 marker:text-muted-foreground">
            {children}
        </ol>
    ),
    li: ({ children }) => <li className="pl-0.5">{children}</li>,
    strong: ({ children }) => (
        <strong className="font-semibold text-foreground">{children}</strong>
    ),
    em: ({ children }) => <em className="italic">{children}</em>,
    h1: ({ children }) => (
        <h1 className="mt-2 mb-1 text-base font-semibold">{children}</h1>
    ),
    h2: ({ children }) => (
        <h2 className="mt-2 mb-1 text-sm font-semibold">{children}</h2>
    ),
    h3: ({ children }) => (
        <h3 className="mt-2 mb-1 text-sm font-semibold">{children}</h3>
    ),
    blockquote: ({ children }) => (
        <blockquote className="my-1.5 border-l-2 border-[#0ABFBF]/50 pl-3 text-muted-foreground italic">
            {children}
        </blockquote>
    ),
    code: ({ className, children }) => {
        const isBlock = (className ?? '').includes('language-');

        if (isBlock) {
            return (
                <code className="block font-mono text-[12.5px] leading-relaxed">
                    {children}
                </code>
            );
        }

        return (
            <code className="rounded bg-muted px-1 py-0.5 font-mono text-[12.5px] text-foreground">
                {children}
            </code>
        );
    },
    pre: ({ children }) => (
        <pre className="my-2 overflow-x-auto rounded-lg border border-border bg-muted/60 p-3">
            {children}
        </pre>
    ),
    table: ({ children }) => (
        <div className="my-2 overflow-x-auto rounded-lg border border-border">
            <table className="w-full border-collapse text-xs">{children}</table>
        </div>
    ),
    thead: ({ children }) => <thead className="bg-muted/60">{children}</thead>,
    th: ({ children }) => (
        <th className="border-b border-border px-2.5 py-1.5 text-left font-semibold">
            {children}
        </th>
    ),
    td: ({ children }) => (
        <td className="border-b border-border/60 px-2.5 py-1.5">{children}</td>
    ),
    hr: () => <hr className="my-2 border-border" />,
};

export const Markdown = memo(function Markdown({ text }: { text: string }) {
    return (
        <div className="text-sm leading-relaxed break-words">
            <ReactMarkdown remarkPlugins={[remarkGfm]} components={COMPONENTS}>
                {text}
            </ReactMarkdown>
        </div>
    );
});
