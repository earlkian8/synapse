import { ArrowRight, GraduationCap } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { MODEL_COPY } from '../constants';
import type { Graduation } from '../types';
import { useGraduation } from '../use-graduation';
import { GraduationDialog, landingTab } from './graduation-dialog';
import type { GraduationTab, GraduationView } from './graduation-dialog';
import {
    awaitsDecision,
    RequirementDots,
    StagePill,
    stripLine,
} from './graduation-summary';

/**
 * Model graduation on a predictive page (ADR 0046): one line that says whose
 * model is scoring the page and what stands between it and a model of its own,
 * with the whole story — requirements, the model, the data — a click away in a
 * modal. The strip is emphasised only when a decision is waiting.
 *
 * Every figure is counted from the organisation's own records on the server.
 */
export function GraduationPanel({
    graduation,
    canManage,
}: {
    graduation: Graduation;
    canManage: boolean;
}) {
    const [view, setView] = useState<GraduationView | null>(null);
    const actions = useGraduation(graduation.model);
    const copy = MODEL_COPY[graduation.model];
    const waiting = awaitsDecision(graduation);

    const open = (tab: GraduationTab) => setView({ tab, requirement: null });

    return (
        <>
            <section
                aria-label="Model graduation"
                className={cn(
                    'flex flex-col gap-3 rounded-xl border px-4 py-3 shadow-sm sm:flex-row sm:items-center',
                    waiting
                        ? 'border-[#0ABFBF]/40 bg-[#0ABFBF]/6'
                        : 'border-sidebar-border/70 bg-card dark:border-sidebar-border',
                )}
            >
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <span
                        className={cn(
                            'mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg',
                            graduation.stage === 'graduated'
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                : 'bg-[#0ABFBF]/10 text-teal-700 dark:text-[#0ABFBF]',
                        )}
                    >
                        <GraduationCap className="size-4.5" />
                    </span>

                    <div className="min-w-0 flex-1">
                        <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm font-medium">
                            Model graduation
                            <StagePill graduation={graduation} />
                            <button
                                type="button"
                                onClick={() => open('requirements')}
                                className="rounded-sm transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                aria-label={`${graduation.met_count} of ${graduation.total_count} requirements met — see the requirements`}
                            >
                                <RequirementDots graduation={graduation} />
                            </button>
                        </p>
                        <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                            {stripLine(graduation)}
                        </p>
                    </div>
                </div>

                <Button
                    size="sm"
                    variant={waiting ? 'default' : 'outline'}
                    className="shrink-0 self-start sm:self-center"
                    onClick={() => open(landingTab(graduation))}
                >
                    {stripAction(graduation, canManage)}
                    <ArrowRight className="size-3.5" />
                </Button>
            </section>

            <GraduationDialog
                graduation={graduation}
                canManage={canManage}
                view={view}
                onView={setView}
                onClose={() => setView(null)}
                training={actions.training}
                onTrain={actions.train}
                onActivate={actions.askToActivate}
                onRevert={actions.askToRevert}
            />

            <ConfirmDialog
                open={actions.confirming}
                onOpenChange={(isOpen) => !isOpen && actions.cancel()}
                title={
                    actions.pending?.kind === 'revert'
                        ? 'Switch back to the general model?'
                        : 'Switch to your own model?'
                }
                description={
                    actions.pending?.kind === 'revert'
                        ? `From the next run, the ${copy.scores} on this page come from the general model again. Your model stays on record, but using your own history again means training a new one.`
                        : `From the next run, the ${copy.scores} on this page come from the model trained on your records. Earlier runs keep saying which model scored them, and you can switch back at any time.`
                }
                confirmLabel={
                    actions.pending?.kind === 'revert'
                        ? 'Switch back'
                        : 'Switch to our model'
                }
                processing={actions.switching}
                onConfirm={actions.confirm}
            />
        </>
    );
}

/** What the strip's button offers, in the reader's terms. */
function stripAction(graduation: Graduation, canManage: boolean): string {
    if (graduation.latest?.status === 'ready') {
        return 'Review the new model';
    }

    if (graduation.stage === 'graduated') {
        return 'See your model';
    }

    if (graduation.gate_open) {
        return canManage ? 'Train your own model' : 'See the details';
    }

    return 'See what’s needed';
}
