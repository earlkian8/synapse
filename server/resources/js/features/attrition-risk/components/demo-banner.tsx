import { Sparkles } from 'lucide-react';

/**
 * Attrition Risk is a frontend-only demo surface (see
 * docs/decisions/0030-attrition-risk-frontend-only.md): the roster and its risk
 * scores are fabricated and computed in the browser, not by a trained model or
 * real employee data. This banner keeps that honest rather than implying a live
 * prediction service.
 */
export function DemoBanner() {
    return (
        <div className="flex items-start gap-2.5 rounded-xl border border-sidebar-border/70 bg-card/60 px-4 py-3 text-xs text-muted-foreground dark:border-sidebar-border">
            <Sparkles className="mt-0.5 size-4 shrink-0 text-[#0ABFBF]" />
            <p>
                <span className="font-medium text-foreground">
                    Simulated data.
                </span>{' '}
                This roster and its risk scores are generated in your browser
                for demonstration — no real employee data or trained model is
                involved. Each assessment reshuffles the scores.
            </p>
        </div>
    );
}
