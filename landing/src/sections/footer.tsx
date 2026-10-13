import { ArrowUpRight } from 'lucide-react';
import { ActionLink } from '../components/actions';
import { Mark } from '../components/mark';
import { links } from '../lib/links';

const columns = [
    {
        heading: 'On this page',
        items: [
            { label: 'How it works', href: '#lifecycle' },
            { label: 'Insights', href: '#insights' },
            { label: 'Mobile app', href: '#app' },
        ],
    },
    {
        heading: 'SYNAPSE',
        items: [
            { label: 'Open SYNAPSE', href: links.erp },
            { label: 'Sign in', href: links.signIn },
            { label: 'Create a workspace', href: links.register },
            { label: 'Download the APK', href: links.apk },
        ],
    },
    {
        heading: 'Legal',
        items: [
            { label: 'Privacy policy', href: links.privacy },
            { label: 'Terms of service', href: links.terms },
        ],
    },
];

export function Footer() {
    return (
        <footer className="relative overflow-hidden bg-white">
            <div className="mx-auto max-w-[84rem] px-4 pt-24 sm:px-6 lg:px-10 lg:pt-32">
                <div className="grid gap-16 lg:grid-cols-12 lg:gap-10">
                    <div className="lg:col-span-6">
                        <h2 className="text-[clamp(2rem,4.6vw,3.5rem)] leading-[1] font-[780] tracking-[-0.03em] text-balance font-wide">
                            Start with your company's workspace.
                        </h2>
                        <p className="mt-6 max-w-[30rem] text-[1.05rem] leading-[1.65] text-ink-soft">
                            Create a workspace on the web, bring in your roster,
                            and invite your people. They join from the app with
                            their invitation or your company's join code.
                        </p>
                        <div className="mt-9 flex flex-wrap gap-3">
                            <ActionLink
                                href={links.register}
                                icon={
                                    <ArrowUpRight
                                        className="size-4.5"
                                        strokeWidth={2.25}
                                    />
                                }
                            >
                                Create a workspace
                            </ActionLink>
                            <ActionLink href={links.signIn} tone="outline">
                                Sign in
                            </ActionLink>
                        </div>
                    </div>

                    <nav
                        aria-label="Footer"
                        className="grid grid-cols-2 gap-10 sm:grid-cols-3 lg:col-span-5 lg:col-start-8 lg:pt-3"
                    >
                        {columns.map((col) => (
                            <div key={col.heading}>
                                <p className="text-[0.82rem] font-semibold text-ink">
                                    {col.heading}
                                </p>
                                <ul className="mt-4 space-y-3">
                                    {col.items.map((item) => (
                                        <li key={item.label}>
                                            <a
                                                href={item.href}
                                                className="text-[0.9rem] text-ink-soft transition-colors hover:text-signal"
                                            >
                                                {item.label}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </nav>
                </div>

                <div className="mt-20 flex flex-wrap items-center justify-between gap-4 border-t border-ink/8 pt-6 text-[0.82rem] text-ink-soft">
                    <p>
                        &copy; {new Date().getFullYear()} SYNAPSE. Built for
                        Philippine organisations.
                    </p>
                    <Mark className="h-6 w-auto" title={null} />
                </div>
            </div>

            {/* The name, set wide enough to fill the page and cut by its foot. */}
            <p
                aria-hidden="true"
                className="pointer-events-none mt-6 -mb-[0.2em] text-center text-[clamp(3.6rem,15.2vw,17rem)] leading-[0.82] font-[820] tracking-[-0.045em] whitespace-nowrap text-frost font-wide select-none"
            >
                SYNAPSE
            </p>
        </footer>
    );
}
