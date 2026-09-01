import { CircleAlert } from 'lucide-react';
import type { ServiceInfo } from '../types';

/**
 * Says the one thing an HR user needs to know about the prediction service:
 * that it is briefly unavailable and why the Run button is disabled. Nothing is
 * rendered while it is working — a healthy service is not news, and the model
 * behind it (its algorithm, its version, its accuracy metrics) is an
 * implementation detail nobody using this screen is meant to reason about.
 */
export function ServiceBanner({ service }: { service: ServiceInfo }) {
    if (service.connected) {
        return null;
    }

    return (
        <div className="flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
            <CircleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <div className="flex flex-col gap-0.5">
                <p className="font-medium text-amber-700 dark:text-amber-300">
                    Assessments are temporarily unavailable
                </p>
                <p className="text-amber-700/80 dark:text-amber-300/80">
                    Existing assessments are still shown, but a new one can't be
                    run right now. Try again shortly, or contact your system
                    administrator if this continues.
                </p>
            </div>
        </div>
    );
}
