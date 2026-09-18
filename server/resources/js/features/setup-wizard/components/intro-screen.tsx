import { ArrowRight, Clock3 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { STEPS } from '../constants';
import type { SetupStep } from '../types';

type Props = {
    companyName: string;
    firstName: string;
    can: Record<SetupStep, boolean>;
    onStart: () => void;
    onLeave: () => void;
    leaving: boolean;
};

/**
 * The welcome. It exists to answer the two questions somebody has the moment
 * their company is created — what am I being asked for, and can I get out of it —
 * before any field appears.
 */
export default function IntroScreen({
    companyName,
    firstName,
    can,
    onStart,
    onLeave,
    leaving,
}: Props) {
    return (
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-10 md:px-8 md:py-14">
            <div className="mx-auto flex w-full max-w-2xl flex-col">
                <p className="text-sm text-muted-foreground">
                    Welcome, {firstName}.
                </p>
                <h1 className="mt-1.5 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                    {companyName} is ready to be set up.
                </h1>
                <p className="mt-3 max-w-lg text-sm leading-relaxed text-muted-foreground">
                    SYNAPSE ships with nothing filled in, so every list in it is
                    yours rather than a template's. Six short steps cover what
                    the rest of the system reads from — and any one of them can
                    wait.
                </p>

                <ol className="mt-8 flex flex-col">
                    {STEPS.map((meta) => (
                        <li
                            key={meta.step}
                            className="flex items-start gap-4 border-b border-sidebar-border/70 py-3.5 last:border-b-0 dark:border-sidebar-border"
                        >
                            <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <meta.icon className="size-4" />
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="flex items-center gap-2 text-sm font-medium text-foreground">
                                    {meta.label}
                                    {!can[meta.step] && (
                                        <span className="rounded bg-muted px-1.5 py-px text-[10px] font-normal text-muted-foreground">
                                            Needs a permission you don't have
                                        </span>
                                    )}
                                </span>
                                <span className="mt-0.5 block text-xs leading-relaxed text-muted-foreground">
                                    {meta.purpose}
                                </span>
                            </span>
                        </li>
                    ))}
                </ol>

                <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    <Button type="button" size="lg" onClick={onStart}>
                        Start setup
                        <ArrowRight className="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="lg"
                        onClick={onLeave}
                        disabled={leaving}
                        className="text-muted-foreground"
                    >
                        {leaving && <Spinner />}
                        Take me to the dashboard
                    </Button>
                </div>

                <p className="mt-5 flex items-center gap-2 text-xs text-muted-foreground">
                    <Clock3 className="size-3.5" />
                    About ten minutes. Everything here is also in Company Setup,
                    and nothing is locked in.
                </p>
            </div>
        </div>
    );
}
