import { CircleAlert, Cpu } from 'lucide-react';
import { modelAlgorithm } from '../constants';
import type { ServiceInfo } from '../types';

/**
 * A slim status strip for the inference service: an amber warning with the start
 * command when it's offline, or a subtle model + accuracy line when it's live.
 */
export function ServiceBanner({ service }: { service: ServiceInfo }) {
    if (!service.connected) {
        return (
            <div className="flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                <CircleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                <div className="flex flex-col gap-0.5">
                    <p className="font-medium text-amber-700 dark:text-amber-300">
                        Prediction service offline
                    </p>
                    <p className="text-amber-700/80 dark:text-amber-300/80">
                        Existing assessments are still shown, but you can't run
                        a new one until the model service is running. Start it
                        with{' '}
                        <code className="rounded bg-amber-500/15 px-1 py-0.5 font-mono text-xs">
                            python -m api
                        </code>{' '}
                        from the{' '}
                        <span className="font-mono text-xs">model/</span>{' '}
                        directory.
                    </p>
                </div>
            </div>
        );
    }

    const algorithm = modelAlgorithm(service.model_version);
    const roc = service.metrics?.test_roc_auc;

    return (
        <div className="flex items-center gap-2 rounded-xl border border-sidebar-border/70 bg-card/60 px-4 py-2.5 text-xs text-muted-foreground dark:border-sidebar-border">
            <span className="flex size-6 items-center justify-center rounded-md bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Cpu className="size-3.5" />
            </span>
            <span className="font-medium text-foreground">
                Model service connected
            </span>
            {algorithm && (
                <>
                    <span aria-hidden>·</span>
                    <span>{algorithm}</span>
                </>
            )}
            {typeof roc === 'number' && (
                <>
                    <span aria-hidden>·</span>
                    <span>ROC-AUC {roc.toFixed(2)}</span>
                </>
            )}
        </div>
    );
}
