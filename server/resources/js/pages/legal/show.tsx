import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarDays, ListTree, Printer } from 'lucide-react';
import { useMemo } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { ArticleBody } from '@/features/help-center/components/article-body';
import { ArticleToc } from '@/features/help-center/components/article-toc';
import { headingsOf } from '@/features/help-center/lib/markdown';
import type { LegalPageProps } from '@/features/legal/types';
import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';

const DOCUMENTS = [
    { key: 'privacy', title: 'Privacy Policy', href: '/privacy' },
    { key: 'terms', title: 'Terms of Service', href: '/terms' },
] as const;

/**
 * The Privacy Policy or the Terms of Service (ADR 0063). Public, with its own
 * chrome — people read it before they have an account — and read like a
 * document: the key points first, then the full text with its contents beside
 * it. It prints without the page around it.
 */
export default function LegalShow() {
    const { document } = usePage<LegalPageProps>().props;
    const { auth } = usePage().props;
    // The numbered sections; their subsections are left out of the contents.
    const sections = useMemo(
        () =>
            headingsOf(document.body).filter((heading) => heading.level === 2),
        [document.body],
    );

    return (
        <>
            <Head title={document.title}>
                <meta name="description" content={document.summary} />
            </Head>

            <div className="min-h-screen bg-background text-foreground">
                {/* ── Top bar ── */}
                <header className="sticky top-0 z-30 border-b border-border/60 bg-background/85 backdrop-blur-md print:hidden">
                    <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6">
                        <Link
                            href="/"
                            className="flex items-center gap-2"
                            aria-label="SYNAPSE home"
                        >
                            <AppLogoIcon className="h-6 w-auto" />
                            <span className="hidden text-xs font-bold tracking-[0.2em] uppercase sm:inline">
                                SYNAPSE
                            </span>
                        </Link>

                        <nav
                            aria-label="Legal documents"
                            className="flex items-center gap-1 rounded-lg bg-muted/60 p-1"
                        >
                            {DOCUMENTS.map((item) => (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    aria-current={
                                        item.key === document.key
                                            ? 'page'
                                            : undefined
                                    }
                                    className={cn(
                                        'rounded-md px-3 py-1 text-xs font-medium transition-colors',
                                        item.key === document.key
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <span className="sm:hidden">
                                        {item.key === 'privacy'
                                            ? 'Privacy'
                                            : 'Terms'}
                                    </span>
                                    <span className="hidden sm:inline">
                                        {item.title}
                                    </span>
                                </Link>
                            ))}
                        </nav>

                        <Button variant="outline" size="sm" asChild>
                            <Link href={auth.user ? dashboard() : login()}>
                                {auth.user ? 'Back to SYNAPSE' : 'Sign in'}
                            </Link>
                        </Button>
                    </div>
                </header>

                <div className="mx-auto grid max-w-6xl gap-10 px-4 py-10 sm:px-6 lg:grid-cols-[minmax(0,1fr)_14rem] lg:py-14">
                    <main className="max-w-3xl min-w-0">
                        {/* ── Title ── */}
                        <header>
                            <p className="text-xs font-semibold tracking-[0.18em] text-[#0A9E9E] uppercase dark:text-[#0ABFBF]">
                                Legal
                            </p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">
                                {document.title}
                            </h1>
                            <p className="mt-3 text-base leading-relaxed text-pretty text-muted-foreground">
                                {document.summary}
                            </p>
                            <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-muted-foreground">
                                <span className="inline-flex items-center gap-1.5">
                                    <CalendarDays className="size-4" />
                                    Effective{' '}
                                    <time dateTime={document.effective}>
                                        {document.effective_label}
                                    </time>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => window.print()}
                                    className="inline-flex items-center gap-1.5 hover:text-foreground print:hidden"
                                >
                                    <Printer className="size-4" />
                                    Print or save as PDF
                                </button>
                            </div>
                        </header>

                        {/* ── At a glance ── */}
                        <section
                            aria-labelledby="legal-glance"
                            className="mt-8 rounded-2xl border border-[#0ABFBF]/25 bg-[#0ABFBF]/[0.05] p-5 sm:p-6"
                        >
                            <h2
                                id="legal-glance"
                                className="text-sm font-semibold tracking-tight"
                            >
                                At a glance
                            </h2>
                            <ul className="mt-3 space-y-2">
                                {document.highlights.map((point) => (
                                    <li
                                        key={point}
                                        className="flex gap-2.5 text-sm leading-relaxed text-foreground/85"
                                    >
                                        <span
                                            aria-hidden
                                            className="mt-2 size-1.5 shrink-0 rounded-full bg-[#0ABFBF]"
                                        />
                                        {point}
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-4 text-xs text-muted-foreground">
                                This summary is for convenience. The full text
                                below is what applies.
                            </p>
                        </section>

                        {/* ── Contents, where there is no room beside the text ── */}
                        {sections.length >= 2 && (
                            <details className="group mt-6 rounded-xl border border-border bg-muted/30 px-4 py-3 lg:hidden print:hidden">
                                <summary className="flex cursor-pointer list-none items-center gap-2 text-sm font-medium [&::-webkit-details-marker]:hidden">
                                    <ListTree className="size-4 text-muted-foreground" />
                                    Contents
                                    <span className="ml-auto text-xs text-muted-foreground group-open:hidden">
                                        {sections.length} sections
                                    </span>
                                </summary>
                                <ul className="mt-3 space-y-1.5 border-t border-border/60 pt-3">
                                    {sections.map((heading) => (
                                        <li key={heading.id}>
                                            <a
                                                href={`#${heading.id}`}
                                                className="text-sm text-muted-foreground hover:text-foreground"
                                            >
                                                {heading.title}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}

                        {/* ── The document ── */}
                        <div className="mt-10">
                            <ArticleBody markdown={document.body} />
                        </div>

                        {document.others.map((other) => (
                            <Link
                                key={other.key}
                                href={other.href}
                                className="group mt-12 flex items-center justify-between gap-4 rounded-xl border border-border px-5 py-4 transition-colors hover:border-[#0ABFBF]/50 hover:bg-muted/30 print:hidden"
                            >
                                <span>
                                    <span className="block text-xs text-muted-foreground">
                                        Read next
                                    </span>
                                    <span className="font-medium">
                                        {other.title}
                                    </span>
                                </span>
                                <ArrowRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-foreground" />
                            </Link>
                        ))}
                    </main>

                    <aside className="hidden lg:block print:hidden">
                        <div className="sticky top-24">
                            <ArticleToc headings={sections} />
                        </div>
                    </aside>
                </div>

                <footer className="border-t border-border/60 py-6 print:hidden">
                    <div className="mx-auto flex max-w-6xl flex-col items-center justify-between gap-2 px-4 text-xs text-muted-foreground sm:flex-row sm:px-6">
                        <p>
                            &copy; {new Date().getFullYear()}{' '}
                            {document.operator}. All rights reserved.
                        </p>
                        <nav
                            aria-label="Legal"
                            className="flex items-center gap-4"
                        >
                            {DOCUMENTS.map((item) => (
                                <Link
                                    key={item.key}
                                    href={item.href}
                                    className="hover:text-foreground"
                                >
                                    {item.title}
                                </Link>
                            ))}
                        </nav>
                    </div>
                </footer>
            </div>
        </>
    );
}
