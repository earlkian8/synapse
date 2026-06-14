import { Link } from '@inertiajs/react';
import type { CareersOrg } from '../types';

/**
 * Public page chrome for the careers surface: a branded org header and footer
 * wrapped around a readable, theme-independent light layout (candidates may
 * arrive with any system theme, so colours are explicit rather than tokenised).
 */
export function CareersShell({
    organization,
    children,
}: {
    organization: CareersOrg;
    children: React.ReactNode;
}) {
    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-4xl items-center gap-3 px-6 py-4">
                    <Link
                        href={`/careers/${organization.slug}`}
                        className="flex items-center gap-3"
                    >
                        {organization.logo_url ? (
                            <img
                                src={organization.logo_url}
                                alt={organization.name}
                                className="size-10 rounded-md object-contain"
                            />
                        ) : (
                            <div className="flex size-10 items-center justify-center rounded-md bg-[#0F2044] text-sm font-semibold text-white">
                                {organization.initials}
                            </div>
                        )}
                        <div>
                            <p className="text-sm font-semibold text-slate-900">
                                {organization.name}
                            </p>
                            <p className="text-xs text-slate-500">Careers</p>
                        </div>
                    </Link>
                </div>
            </header>

            <main className="mx-auto max-w-4xl px-6 py-8">{children}</main>

            <footer className="border-t border-slate-200 py-6">
                <p className="text-center text-xs text-slate-400">
                    {organization.name} careers · Powered by SYNAPSE
                </p>
            </footer>
        </div>
    );
}
