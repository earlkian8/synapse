import { Link } from '@inertiajs/react';
import { ArrowUpRight, Cpu } from 'lucide-react';
import type { MlSignal } from '../types';

const TONE: Record<MlSignal['tone'], { dot: string; value: string }> = {
    rose: { dot: 'bg-rose-500', value: 'text-rose-600 dark:text-rose-400' },
    teal: { dot: 'bg-[#0ABFBF]', value: 'text-[#0a8f8f] dark:text-[#0ABFBF]' },
    amber: { dot: 'bg-amber-500', value: 'text-amber-600 dark:text-amber-400' },
};

/**
 * Model-derived decision signals that ride alongside the relevant reports — read from
 * the latest persisted ML runs, each linking through to its full analytics surface.
 */
export function ReportSignals({ signals }: { signals: MlSignal[] }) {
    if (signals.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                <Cpu className="size-3.5 text-[#0ABFBF]" />
                Model signals
            </p>
            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {signals.map((signal) => {
                    const tone = TONE[signal.tone];

                    return (
                        <Link
                            key={signal.key}
                            href={signal.href}
                            className="group flex items-center gap-3 rounded-lg border border-sidebar-border/70 bg-card px-3 py-2.5 shadow-sm transition-colors hover:border-[#0ABFBF]/50 dark:border-sidebar-border"
                        >
                            <span
                                className={`size-2 shrink-0 rounded-full ${tone.dot}`}
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-[11px] tracking-wide text-muted-foreground uppercase">
                                    {signal.label}
                                </p>
                                <p
                                    className={`text-sm font-semibold ${tone.value}`}
                                >
                                    {signal.value}
                                </p>
                                <p className="truncate text-[11px] text-muted-foreground">
                                    {signal.detail}
                                </p>
                            </div>
                            <ArrowUpRight className="size-3.5 shrink-0 text-muted-foreground/50 transition-colors group-hover:text-foreground" />
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
