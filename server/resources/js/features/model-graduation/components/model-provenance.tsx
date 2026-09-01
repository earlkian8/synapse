import { ChevronDown, Lock, RefreshCw, Sparkles } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import {
    completion,
    formatProgress,
    formatRelative,
    MODEL_COPY,
    STAGE_LABELS,
} from '../constants';
import {
    getServerSnapshot,
    getSnapshot,
    runCheck,
    subscribe,
} from '../mock-engine';
import type { ModelKey, Requirement } from '../types';
import { RequirementDialog } from './requirement-dialog';
import { RequirementLedger } from './requirement-ledger';
import { StageRail } from './stage-rail';

/** A short artificial delay so re-checking still feels like it queries records. */
const SIMULATED_DELAY_MS = 700;

/**
 * The graduation panel each predictive surface embeds beneath its own header.
 *
 * It answers one question an HR reader is entitled to ask of any score on the
 * page — *whose workforce does this actually describe?* — and, when the honest
 * answer is "not yours yet", shows exactly what would have to accumulate before
 * that changes. Collapsed, it states the provenance; expanded, it shows the
 * lifecycle, the binding shortfall and every requirement behind the gate.
 *
 * Deliberately free of model internals: which algorithm runs and how it tested
 * are implementation details this audience is not meant to reason about.
 */
export function ModelProvenance({ model }: { model: ModelKey }) {
    const check = useSyncExternalStore(
        subscribe(model),
        getSnapshot(model),
        getServerSnapshot,
    );

    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [detail, setDetail] = useState<Requirement | null>(null);

    // Nothing to show until the client store has seeded (the SSR pass and the
    // first client render both read null, so no hydration mismatch is possible).
    if (!check) {
        return null;
    }

    const copy = MODEL_COPY[model];
    const graduated = check.stage === 'graduated';
    const binding =
        check.requirements.find((r) => r.key === check.binding_key) ?? null;

    const handleRecheck = () => {
        setProcessing(true);
        window.setTimeout(() => {
            runCheck(model);
            setProcessing(false);
        }, SIMULATED_DELAY_MS);
    };

    return (
        <>
            <Collapsible
                open={open}
                onOpenChange={setOpen}
                className="rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
            >
                <CollapsibleTrigger className="flex w-full items-start gap-3 rounded-xl px-4 py-3.5 text-left transition-colors hover:bg-muted/50">
                    <span
                        className={cn(
                            'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg',
                            graduated
                                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                : 'bg-foreground/5 text-muted-foreground',
                        )}
                    >
                        <Lock className="size-4" />
                    </span>

                    <div className="min-w-0 flex-1">
                        <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {copy.title}
                            <StageBadge
                                label={STAGE_LABELS[check.stage]}
                                graduated={graduated}
                            />
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {copy.provenance}
                        </p>
                    </div>

                    <div className="flex shrink-0 items-center gap-3">
                        <span className="hidden text-xs text-muted-foreground tabular-nums sm:inline">
                            {check.met_count} of {check.total_count} met
                        </span>
                        <ChevronDown
                            className={cn(
                                'size-4 text-muted-foreground transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    </div>
                </CollapsibleTrigger>

                <CollapsibleContent>
                    <div className="flex flex-col gap-4 border-t border-sidebar-border/70 px-4 py-4 dark:border-sidebar-border">
                        <StageRail
                            stage={check.stage}
                            summaries={copy.stages}
                        />

                        {binding && <BindingConstraint binding={binding} />}

                        <RequirementLedger
                            requirements={check.requirements}
                            bindingKey={check.binding_key}
                            onOpen={setDetail}
                        />

                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="flex items-start gap-2 text-xs text-muted-foreground">
                                <Sparkles className="mt-0.5 size-3.5 shrink-0 text-[#0ABFBF]" />
                                <span>
                                    Record counts are simulated for
                                    demonstration. The requirements and the
                                    reasoning behind each are the real ones.
                                </span>
                            </p>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-8 shrink-0 text-xs text-muted-foreground"
                                onClick={handleRecheck}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <RefreshCw className="size-3.5" />
                                )}
                                {processing
                                    ? 'Checking…'
                                    : `Re-check · ${formatRelative(check.checked_at)}`}
                            </Button>
                        </div>
                    </div>
                </CollapsibleContent>
            </Collapsible>

            <RequirementDialog
                requirement={detail}
                onOpenChange={(isOpen) => !isOpen && setDetail(null)}
            />
        </>
    );
}

function StageBadge({
    label,
    graduated,
}: {
    label: string;
    graduated: boolean;
}) {
    return (
        <span
            className={cn(
                'rounded-full border px-2 py-0.5 text-[10px] font-medium tracking-wide uppercase',
                graduated
                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                    : 'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0ABFBF]',
            )}
        >
            {label}
        </span>
    );
}

/**
 * The single requirement furthest from satisfied, with a plain-language
 * projection of when it closes. Leading with the shortfall rather than with
 * progress is deliberate: the refusal is the designed behaviour.
 */
function BindingConstraint({ binding }: { binding: Requirement }) {
    const percent = completion(binding.current, binding.required);

    return (
        <div className="rounded-xl bg-muted/40 p-4">
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Furthest from ready
            </span>

            <div className="mt-2.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p className="text-sm font-medium">{binding.label}</p>
                <p className="text-sm font-semibold tabular-nums">
                    {formatProgress(binding.current, binding.required)}
                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                        {percent}%
                    </span>
                </p>
            </div>

            <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-[#0ABFBF]"
                    style={{ width: `${Math.max(percent, 1.5)}%` }}
                />
            </div>

            {binding.outlook && (
                <p className="mt-3 text-sm text-muted-foreground">
                    {binding.outlook}
                </p>
            )}
        </div>
    );
}
