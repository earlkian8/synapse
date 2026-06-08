import { Link } from '@inertiajs/react';
import { Bell, BrainCircuit, ShieldCheck, TrendingUp } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
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

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    const year = new Date().getFullYear();

    return (
        <TooltipProvider>
            <div className="min-h-svh flex">

                {/* ── Left Intelligence Panel ── */}
                <aside
                    className="hidden lg:flex lg:w-[42%] xl:w-[38%] flex-col relative overflow-hidden select-none"
                    style={{ background: 'linear-gradient(160deg, #0F2044 0%, #0a1730 100%)' }}
                >
                    {/* Dot-grid texture */}
                    <div
                        className="absolute inset-0 pointer-events-none"
                        style={{
                            backgroundImage: 'radial-gradient(circle, rgba(10,191,191,0.18) 1px, transparent 1px)',
                            backgroundSize: '30px 30px',
                        }}
                    />

                    {/* Teal glow blobs */}
                    <div
                        className="absolute -bottom-40 -right-40 w-[500px] h-[500px] rounded-full pointer-events-none"
                        style={{ background: 'radial-gradient(circle, rgba(10,191,191,0.12) 0%, transparent 65%)' }}
                    />
                    <div
                        className="absolute top-1/4 -left-20 w-[300px] h-[300px] rounded-full pointer-events-none"
                        style={{ background: 'radial-gradient(circle, rgba(10,191,191,0.07) 0%, transparent 65%)' }}
                    />

                    {/* Decorative corner line-art */}
                    <svg
                        className="absolute top-0 right-0 opacity-[0.04] pointer-events-none"
                        width="320" height="320" viewBox="0 0 320 320" fill="none"
                    >
                        <circle cx="320" cy="0" r="200" stroke="#0ABFBF" strokeWidth="1" />
                        <circle cx="320" cy="0" r="140" stroke="#0ABFBF" strokeWidth="1" />
                        <circle cx="320" cy="0" r="80" stroke="#0ABFBF" strokeWidth="1" />
                    </svg>

                    <div className="relative z-10 flex flex-col h-full px-10 xl:px-12 py-10">

                        {/* Brand mark */}
                        <Link href={home()} className="flex items-center gap-3 w-fit">
                            <div className="w-8 h-8 rounded-lg overflow-hidden ring-1 ring-white/10 flex-shrink-0">
                                <AppLogoIcon className="w-full h-full object-contain" />
                            </div>
                            <span className="text-white font-bold tracking-[0.22em] text-xs uppercase opacity-90">
                                STAFFA
                            </span>
                        </Link>

                        {/* Hero copy */}
                        <div className="flex-1 flex flex-col justify-center gap-8 mt-12">
                            <div className="space-y-4">
                                <Badge
                                    variant="outline"
                                    className="border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0ABFBF] text-[10px] tracking-[0.15em] uppercase px-3 py-1 rounded-full font-medium"
                                >
                                    AI-Powered HR ERP
                                </Badge>

                                <h2
                                    className="text-white text-[1.9rem] xl:text-[2.2rem] font-bold leading-[1.2]"
                                    style={{ letterSpacing: '-0.025em' }}
                                >
                                    Workforce intelligence
                                    <br />
                                    <span style={{ color: '#0ABFBF' }}>built for the Philippines.</span>
                                </h2>

                                <p className="text-white/45 text-[13px] leading-relaxed max-w-[280px]">
                                    From government agencies to private enterprises — STAFFA brings data-driven HR to every Philippine institution.
                                </p>
                            </div>

                            <Separator className="bg-white/10 my-1" />

                            {/* Feature list */}
                            <ul className="space-y-4">
                                {features.map(({ icon: Icon, label, desc }) => (
                                    <li key={label} className="flex items-start gap-3.5">
                                        <div
                                            className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                                            style={{ background: 'rgba(10,191,191,0.12)' }}
                                        >
                                            <Icon className="w-3.5 h-3.5" style={{ color: '#0ABFBF' }} />
                                        </div>
                                        <div>
                                            <p className="text-white/80 text-[13px] font-medium leading-snug">{label}</p>
                                            <p className="text-white/35 text-[11.5px] leading-snug mt-0.5">{desc}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {/* Left panel footer */}
                        <div className="mt-auto pt-8">
                            <Separator className="bg-white/10 mb-5" />
                            <p className="text-white/25 text-[11px]">
                                &copy; {year} STAFFA. All rights reserved.
                            </p>
                        </div>
                    </div>
                </aside>

                {/* ── Right Form Panel ── */}
                <div className="flex-1 flex flex-col bg-[#F4F6FA] dark:bg-[#0d1120]">

                    {/* Top bar */}
                    <header className="flex items-center justify-between px-6 md:px-10 h-[58px] bg-white dark:bg-[#0d1120] border-b border-black/[0.07] dark:border-white/[0.07] flex-shrink-0">
                        {/* Mobile brand */}
                        <Link href={home()} className="flex items-center gap-2.5 lg:invisible">
                            <div className="w-7 h-7 rounded-md overflow-hidden ring-1 ring-black/10">
                                <AppLogoIcon className="w-full h-full object-contain" />
                            </div>
                            <span className="text-[#0F2044] dark:text-white font-bold tracking-[0.18em] text-[11px] uppercase">
                                STAFFA
                            </span>
                        </Link>

                        {/* Right actions */}
                        <div className="flex items-center gap-3 ml-auto">
                            {/* System status badge */}
                            <Badge
                                variant="outline"
                                className="hidden sm:inline-flex items-center gap-1.5 border-emerald-200 text-emerald-600 bg-emerald-50 dark:border-emerald-800 dark:text-emerald-400 dark:bg-emerald-950/40 text-[10px] tracking-wider uppercase px-2.5 py-1 rounded-full font-medium"
                            >
                                <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                All Systems Normal
                            </Badge>

                            {/* Notification bell */}
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        aria-label="Notifications"
                                        className="relative w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:text-[#0F2044] hover:bg-black/[0.06] dark:hover:text-white dark:hover:bg-white/[0.08] transition-colors duration-150"
                                    >
                                        <Bell className="w-[15px] h-[15px]" />
                                        {/* Unread dot */}
                                        <span
                                            className="absolute top-[7px] right-[7px] w-[7px] h-[7px] rounded-full border-2 border-white dark:border-[#0d1120]"
                                            style={{ background: '#0ABFBF' }}
                                        />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent side="bottom" className="text-xs">
                                    Notifications
                                </TooltipContent>
                            </Tooltip>
                        </div>
                    </header>

                    {/* Form area */}
                    <main className="flex-1 flex items-center justify-center p-6 md:p-10">
                        <div className="w-full max-w-[360px]">
                            <div className="flex flex-col gap-7">

                                {/* Page header */}
                                <div className="flex flex-col items-center gap-3 text-center">
                                    {/* Show logo on mobile only */}
                                    <div className="w-12 h-12 rounded-xl overflow-hidden shadow-md ring-1 ring-black/10 lg:hidden mb-1">
                                        <AppLogoIcon className="w-full h-full object-contain" />
                                    </div>
                                    <div className="space-y-1.5">
                                        <h1
                                            className="text-[1.2rem] font-semibold text-[#0F2044] dark:text-white"
                                            style={{ letterSpacing: '-0.01em' }}
                                        >
                                            {title}
                                        </h1>
                                        <p className="text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                                            {description}
                                        </p>
                                    </div>
                                </div>

                                {/* Form card */}
                                <Card className="shadow-sm shadow-black/[0.06] border-black/[0.07] dark:border-white/[0.07] dark:bg-[#131929] py-0">
                                    <CardContent className="px-7 py-7">
                                        {children}
                                    </CardContent>
                                </Card>

                            </div>
                        </div>
                    </main>

                    {/* Footer */}
                    <footer className="flex items-center justify-between px-6 md:px-10 h-11 border-t border-black/[0.07] dark:border-white/[0.07] bg-white dark:bg-[#0d1120] flex-shrink-0">
                        <p className="text-[11px] text-slate-400">
                            &copy; {year} STAFFA. All rights reserved.
                        </p>
                        <div className="flex items-center gap-4">
                            <a href="#" className="text-[11px] text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                Privacy Policy
                            </a>
                            <a href="#" className="text-[11px] text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                                Support
                            </a>
                        </div>
                    </footer>

                </div>
            </div>
        </TooltipProvider>
    );
}
