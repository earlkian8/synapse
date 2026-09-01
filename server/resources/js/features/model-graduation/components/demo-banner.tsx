import { Sparkles } from 'lucide-react';

/**
 * Model Graduation is a frontend-only demo surface: the record counts and the
 * readiness verdict are generated in the browser, and no retraining pipeline
 * exists behind the page. The thresholds themselves are real — they are what an
 * implementation would have to enforce — so the banner separates the two rather
 * than implying a live training service.
 */
export function DemoBanner() {
    return (
        <div className="flex items-start gap-2.5 rounded-xl border border-sidebar-border/70 bg-card/60 px-4 py-3 text-xs text-muted-foreground dark:border-sidebar-border">
            <Sparkles className="mt-0.5 size-4 shrink-0 text-[#0ABFBF]" />
            <p>
                <span className="font-medium text-foreground">
                    Simulated record counts.
                </span>{' '}
                The figures on this page are generated in your browser for
                demonstration, and no retraining runs behind it. The thresholds
                and the reasoning for each are the real ones.
            </p>
        </div>
    );
}
