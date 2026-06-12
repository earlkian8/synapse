import { memo } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';

/**
 * Compact, theme-aware markdown for assistant replies: GitHub-flavoured (tables,
 * lists, strikethrough, autolinks), with styling tuned for a chat bubble.
 */
const COMPONENTS: Components = {
    p: ({ children }) => (
        <p className="my-1.5 first:mt-0 last:mb-0">{children}</p>
    ),
    a: ({ children, href }) => (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="font-medium text-[#0ABFBF] underline decoration-[#0ABFBF]/40 underline-offset-2 hover:decoration-[#0ABFBF]"
        >
            {children}
        </a>
    ),
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
