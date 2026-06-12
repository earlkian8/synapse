import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login } from '@/routes';
import { register } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col items-center bg-[#0F2044] p-6 text-white lg:justify-center lg:p-8">
                <header className="mb-6 w-full max-w-[335px] text-sm not-has-[nav]:hidden lg:max-w-4xl">
                    <nav className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="flex h-8 w-8 items-center justify-center">
                                <AppLogoIcon className="size-8 object-contain" />
                            </div>
                            <span className="text-sm font-bold tracking-widest text-white">
                                NEXO
                            </span>
                        </div>
                        <div className="flex items-center gap-4">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="inline-block rounded-md border border-[#0ABFBF] px-5 py-1.5 text-sm leading-normal text-[#0ABFBF] transition-colors hover:bg-[#0ABFBF] hover:text-[#0F2044]"
                                >
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="inline-block rounded-md px-5 py-1.5 text-sm leading-normal text-white/80 transition-colors hover:text-white"
                                    >
                                        Log in
                                    </Link>
                                    <Link
                                        href={register()}
                                        className="inline-block rounded-md border border-[#0ABFBF] bg-[#0ABFBF] px-5 py-1.5 text-sm leading-normal font-semibold text-[#0F2044] transition-colors hover:bg-[#09aeae]"
                                    >
                                        Get started
                                    </Link>
                                </>
                            )}
                        </div>
                    </nav>
                </header>

                <div className="flex w-full items-center justify-center opacity-100 transition-opacity duration-750 lg:grow starting:opacity-0">
                    <main className="flex w-full max-w-[335px] flex-col lg:max-w-4xl lg:flex-row lg:items-center lg:gap-16">
                        {/* Hero text */}
                        <div className="flex-1 py-12 lg:py-0">
                            <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-4 py-1.5 text-xs font-medium tracking-wider text-[#0ABFBF] uppercase">
                                AI-Powered HR ERP
                            </div>
                            <h1 className="mb-3 text-5xl font-extrabold tracking-widest text-white lg:text-7xl">
                                NEXO
                            </h1>
                            <p className="mb-4 text-xl font-medium text-[#0ABFBF] lg:text-2xl">
                                Intelligent HR Management
                            </p>
                            <p className="mb-8 max-w-md text-base leading-relaxed text-white/60">
                                Predictive workforce analytics, intelligent
                                recruitment, and AI-driven HR decision support —
                                purpose-built for Philippine institutions.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                {auth.user ? (
                                    <Link
                                        href={dashboard()}
                                        className="inline-block rounded-md bg-[#0ABFBF] px-6 py-2.5 text-sm font-semibold text-[#0F2044] transition-colors hover:bg-[#09aeae]"
                                    >
                                        Go to Dashboard
                                    </Link>
                                ) : (
                                    <>
                                        <Link
                                            href={register()}
                                            className="inline-block rounded-md bg-[#0ABFBF] px-6 py-2.5 text-sm font-semibold text-[#0F2044] transition-colors hover:bg-[#09aeae]"
                                        >
                                            Get started free
                                        </Link>
                                        <Link
                                            href={login()}
                                            className="inline-block rounded-md border border-white/20 px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:border-white/40"
                                        >
                                            Log in
                                        </Link>
                                    </>
                                )}
                            </div>
                        </div>

                        {/* Feature highlights */}
                        <div className="flex flex-col gap-4 lg:w-80">
                            {[
                                {
                                    title: 'Predictive Analytics',
                                    desc: 'AI-powered workforce forecasting and attrition risk detection.',
                                },
                                {
                                    title: 'Smart Recruitment',
                                    desc: 'Intelligent applicant screening and ranking tailored to your criteria.',
                                },
                                {
                                    title: 'HR Decision Support',
                                    desc: 'Data-driven insights to guide compensation, promotions, and planning.',
                                },
                            ].map((f) => (
                                <div
                                    key={f.title}
                                    className="rounded-xl border border-white/10 bg-white/5 p-5 backdrop-blur-sm"
                                >
                                    <div className="mb-1 h-1 w-8 rounded-full bg-[#0ABFBF]" />
                                    <h3 className="mb-1 text-sm font-semibold text-white">
                                        {f.title}
                                    </h3>
                                    <p className="text-xs leading-relaxed text-white/50">
                                        {f.desc}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </main>
                </div>

                <footer className="mt-8 text-center text-xs text-white/30">
                    &copy; {new Date().getFullYear()} NEXO. Built for Philippine
                    institutions.
                </footer>
            </div>
        </>
    );
}
