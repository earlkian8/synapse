import { Link } from '@inertiajs/react';
import { BrainCircuit, ShieldCheck, TrendingUp } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import SynapseField from '@/components/synapse-field';
import { ThemeToggle } from '@/components/theme-toggle';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const features = [
    {
        icon: TrendingUp,
        label: 'Predictive Workforce Analytics',
        desc: 'Forecast attrition and headcount needs before they become problems.',
    },
    {
        icon: BrainCircuit,
        label: 'Intelligent Recruitment',
        desc: 'AI-ranked applicant screening tailored to your job criteria.',
    },
    {
        icon: ShieldCheck,
        label: 'Compliance Automation',
        desc: 'Stay aligned with DOLE, CSC, and institutional policies automatically.',
    },
];

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const year = new Date().getFullYear();

    return (
        <>
            <div className="flex min-h-svh">
                {/* ── Left Intelligence Panel ── */}
                <aside
                    className="relative hidden flex-col overflow-hidden select-none lg:flex lg:w-[42%] xl:w-[38%]"
                    style={{
                        background:
                            'linear-gradient(160deg, #0F2044 0%, #0a1730 100%)',
                    }}
                >
                    <SynapseField />

                    <div className="relative z-10 flex h-full flex-col px-10 py-10 xl:px-12">
                        {/* Brand mark */}
                        <Link
                            href={home()}
                            className="flex w-fit items-center gap-3"
                        >
                            <div className="h-8 w-8 flex-shrink-0 overflow-hidden rounded-lg ring-1 ring-white/10">
                                <AppLogoIcon className="h-full w-full object-contain" />
                            </div>
                            <span className="text-xs font-bold tracking-[0.22em] text-white uppercase opacity-90">
                                SYNAPSE
                            </span>
                        </Link>

                        {/* Hero copy */}
                        <div className="mt-12 flex flex-1 flex-col justify-center gap-8">
                            <div className="space-y-4">
                                <Badge
                                    variant="outline"
                                    className="rounded-full border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-3 py-1 text-[10px] font-medium tracking-[0.15em] text-[#0ABFBF] uppercase"
                                >
                                    AI-Powered HR ERP
                                </Badge>

                                <h2
                                    className="text-[1.9rem] leading-[1.2] font-bold text-white xl:text-[2.2rem]"
                                    style={{ letterSpacing: '-0.025em' }}
                                >
                                    Workforce intelligence
                                    <br />
                                    <span style={{ color: '#0ABFBF' }}>
                                        built for the Philippines.
                                    </span>
                                </h2>

                                <p className="max-w-[280px] text-[13px] leading-relaxed text-white/45">
                                    From government agencies to private
                                    enterprises — SYNAPSE brings data-driven HR
                                    to every Philippine institution.
                                </p>
                            </div>

                            <Separator className="my-1 bg-white/10" />

                            {/* Feature list */}
                            <ul className="space-y-4">
                                {features.map(({ icon: Icon, label, desc }) => (
                                    <li
                                        key={label}
                                        className="flex items-start gap-3.5"
                                    >
                                        <div className="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/[0.12] text-[#0ABFBF]">
                                            <Icon className="h-3.5 w-3.5" />
                                        </div>
                                        <div>
                                            <p className="text-[13px] leading-snug font-medium text-white/80">
                                                {label}
                                            </p>
                                            <p className="mt-0.5 text-[11.5px] leading-snug text-white/35">
                                                {desc}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {/* Left panel footer */}
                        <div className="mt-auto pt-8">
                            <Separator className="mb-5 bg-white/10" />
                            <p className="text-[11px] text-white/25">
                                &copy; {year} SYNAPSE. All rights reserved.
                            </p>
                        </div>
                    </div>
                </aside>

                {/* ── Right Form Panel ── */}
                <div className="flex flex-1 flex-col bg-[#F4F6FA] dark:bg-[#0d1120]">
                    {/* Top bar */}
                    <header className="flex h-[58px] flex-shrink-0 items-center justify-between border-b border-black/[0.07] bg-white px-6 md:px-10 dark:border-white/[0.07] dark:bg-[#0d1120]">
                        {/* Mobile brand */}
                        <Link
                            href={home()}
                            className="flex items-center gap-2.5 lg:invisible"
                        >
                            <div className="h-7 w-7 overflow-hidden rounded-md ring-1 ring-black/10">
                                <AppLogoIcon className="h-full w-full object-contain" />
                            </div>
                            <span className="text-[11px] font-bold tracking-[0.18em] text-[#0F2044] uppercase dark:text-white">
                                SYNAPSE
                            </span>
                        </Link>

                        {/* Right actions */}
                        <div className="ml-auto flex items-center gap-3">
                            <ThemeToggle />
                        </div>
                    </header>

                    {/* Form area */}
                    <main className="flex flex-1 items-center justify-center p-6 md:p-10">
                        <div className="w-full max-w-[360px]">
                            <div className="flex flex-col gap-7">
                                {/* Page header */}
                                <div className="flex flex-col items-center gap-3 text-center">
                                    {/* Show logo on mobile only */}
                                    <div className="mb-1 h-12 w-12 overflow-hidden rounded-xl shadow-md ring-1 ring-black/10 lg:hidden">
                                        <AppLogoIcon className="h-full w-full object-contain" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <h1
                                            className="text-[1.2rem] font-semibold text-[#0F2044] dark:text-white"
                                            style={{ letterSpacing: '-0.01em' }}
                                        >
                                            {title}
                                        </h1>
                                        <p className="text-sm leading-relaxed text-slate-500 dark:text-slate-400">
                                            {description}
                                        </p>
                                    </div>
                                </div>

                                {/* Form card */}
                                <Card className="border-black/[0.07] py-0 shadow-sm shadow-black/[0.06] dark:border-white/[0.07] dark:bg-[#131929]">
                                    <CardContent className="px-7 py-7">
                                        {children}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </main>

                    {/* Footer */}
                    <footer className="flex h-11 flex-shrink-0 items-center justify-between border-t border-black/[0.07] bg-white px-6 md:px-10 dark:border-white/[0.07] dark:bg-[#0d1120]">
                        <p className="text-[11px] text-slate-400">
                            &copy; {year} SYNAPSE. All rights reserved.
                        </p>
                        <div className="flex items-center gap-4">
                            <a
                                href="#"
                                className="text-[11px] text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-300"
                            >
                                Privacy Policy
                            </a>
                            <a
                                href="#"
                                className="text-[11px] text-slate-400 transition-colors hover:text-slate-600 dark:hover:text-slate-300"
                            >
                                Support
                            </a>
                        </div>
                    </footer>
                </div>
            </div>
        </>
    );
}
