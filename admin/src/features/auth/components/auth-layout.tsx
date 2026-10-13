import { Check, KeyRound, ShieldCheck, Smartphone } from 'lucide-react';
import { motion, useReducedMotion } from 'motion/react';
import type { ReactNode } from 'react';
import { AppLogoIcon } from '@/components/app-logo';
import { ThemeToggle } from '@/components/theme-toggle';
import { TooltipProvider } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** Where somebody is in signing in: the three gates, in order. */
export type SignInStage = 'password' | 'code' | 'session';

const STAGES: {
    key: SignInStage;
    title: string;
    detail: string;
    icon: typeof KeyRound;
}[] = [
    {
        key: 'password',
        title: 'Password',
        detail: 'Your admin email and password. ERP accounts are not admin accounts.',
        icon: KeyRound,
    },
    {
        key: 'code',
        title: 'Authenticator',
        detail: 'A six-digit code from the app on your phone, every time.',
        icon: Smartphone,
    },
    {
        key: 'session',
        title: 'Session',
        detail: 'Eight hours at most, and it ends after 30 minutes with nothing happening.',
        icon: ShieldCheck,
    },
];

/**
 * The ERP's sign-in frame (layouts/auth/auth-simple-layout.tsx): a navy panel
 * beside a quiet form. The panel's subject is the sign-in itself. Its three
 * gates light up as they are passed, so the extra step never comes as a
 * surprise, and the session's limits are stated before it starts.
 */
export function AuthLayout({
    stage,
    children,
}: {
    stage: SignInStage;
    children: ReactNode;
}) {
    const current = STAGES.findIndex((item) => item.key === stage);
    const reduceMotion = useReducedMotion();

    return (
        <TooltipProvider delayDuration={200}>
            <div className="flex min-h-svh">
                <aside
                    className="relative hidden flex-col overflow-hidden text-white select-none lg:flex lg:w-[42%] xl:w-[38%]"
                    style={{
                        background:
                            'linear-gradient(160deg, #0F2044 0%, #0a1730 100%)',
                    }}
                >
                    <div className="relative z-10 flex h-full flex-col px-10 py-10 xl:px-12">
                        <div className="flex items-center gap-3">
                            <AppLogoIcon surface="dark" />
                            <span className="text-xs font-bold tracking-[0.22em] uppercase opacity-90">
                                SYNAPSE
                            </span>
                            <span className="rounded-full border border-white/15 px-2 py-0.5 text-[10px] font-medium text-white/60">
                                Admin
                            </span>
                        </div>

                        <div className="mt-12 flex flex-1 flex-col justify-center gap-10">
                            <div className="space-y-3">
                                <h2 className="text-[1.9rem] leading-[1.15] font-bold tracking-[-0.025em] xl:text-[2.2rem]">
                                    The platform, from above.
                                </h2>
                                <p className="max-w-[300px] text-[13px] leading-relaxed text-white/50">
                                    Every organisation and account on SYNAPSE,
                                    and the people who look after it.
                                </p>
                            </div>

                            <ol
                                className="relative space-y-7"
                                aria-label="Signing in"
                            >
                                {/* The rail, and how much of it has been travelled. */}
                                <span
                                    aria-hidden
                                    className="absolute top-4 bottom-4 left-[15px] w-px bg-white/12"
                                />
                                <motion.span
                                    aria-hidden
                                    className="absolute top-4 left-[15px] w-px origin-top bg-[#0ABFBF]"
                                    style={{ height: 'calc(100% - 2rem)' }}
                                    initial={false}
                                    animate={{
                                        scaleY: current / (STAGES.length - 1),
                                    }}
                                    transition={
                                        reduceMotion
                                            ? { duration: 0 }
                                            : {
                                                  duration: 0.5,
                                                  ease: [0.22, 1, 0.36, 1],
                                              }
                                    }
                                />

                                {STAGES.map((item, index) => {
                                    const done = index < current;
                                    const active = index === current;

                                    return (
                                        <li
                                            key={item.key}
                                            className="relative flex items-start gap-4"
                                            aria-current={
                                                active ? 'step' : undefined
                                            }
                                        >
                                            <motion.span
                                                className={cn(
                                                    'relative z-10 flex size-[31px] shrink-0 items-center justify-center rounded-full border text-xs font-semibold transition-colors duration-300',
                                                    done &&
                                                        'border-[#0ABFBF] bg-[#0ABFBF] text-[#0F2044]',
                                                    active &&
                                                        'border-[#0ABFBF] bg-[#0F2044] text-[#0ABFBF]',
                                                    !done &&
                                                        !active &&
                                                        'border-white/15 bg-[#0d1b3a] text-white/40',
                                                )}
                                                initial={false}
                                                animate={
                                                    active && !reduceMotion
                                                        ? {
                                                              scale: [
                                                                  1, 1.12, 1,
                                                              ],
                                                          }
                                                        : { scale: 1 }
                                                }
                                                transition={{ duration: 0.45 }}
                                            >
                                                {done ? (
                                                    <Check
                                                        className="size-4"
                                                        strokeWidth={3}
                                                    />
                                                ) : (
                                                    <item.icon className="size-3.5" />
                                                )}
                                            </motion.span>
                                            <div className="pt-1">
                                                <p
                                                    className={cn(
                                                        'text-[13px] leading-snug font-medium transition-colors duration-300',
                                                        active
                                                            ? 'text-white'
                                                            : done
                                                              ? 'text-white/70'
                                                              : 'text-white/45',
                                                    )}
                                                >
                                                    {item.title}
                                                    {done && (
                                                        <span className="sr-only">
                                                            {' '}
                                                            (done)
                                                        </span>
                                                    )}
                                                </p>
                                                <p
                                                    className={cn(
                                                        'mt-0.5 max-w-[290px] text-[11.5px] leading-snug transition-colors duration-300',
                                                        active
                                                            ? 'text-white/60'
                                                            : 'text-white/30',
                                                    )}
                                                >
                                                    {item.detail}
                                                </p>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ol>
                        </div>

                        <p className="mt-auto max-w-[320px] pt-8 text-[11px] leading-relaxed text-white/35">
                            Every sign-in, and every failed attempt, is written
                            to the admin activity log.
                        </p>
                    </div>
                </aside>

                <div className="flex flex-1 flex-col bg-[#F4F6FA] dark:bg-[#0d1120]">
                    <header className="flex h-[58px] shrink-0 items-center justify-between border-b border-black/[0.07] bg-white px-6 md:px-10 dark:border-white/[0.07] dark:bg-[#0d1120]">
                        <div className="flex items-center gap-2.5 lg:invisible">
                            <AppLogoIcon className="h-6" />
                            <span className="text-[11px] font-bold tracking-[0.18em] text-[#0F2044] uppercase dark:text-white">
                                SYNAPSE Admin
                            </span>
                        </div>
                        <ThemeToggle />
                    </header>

                    <main className="flex flex-1 items-center justify-center p-6 md:p-10">
                        <div className="w-full max-w-[400px]">
                            <p className="mb-5 text-xs font-medium text-muted-foreground lg:hidden">
                                Step {current + 1} of {STAGES.length}:{' '}
                                {STAGES[current].title}
                            </p>
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </TooltipProvider>
    );
}
