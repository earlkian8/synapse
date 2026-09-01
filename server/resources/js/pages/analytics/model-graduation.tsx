import { Head } from '@inertiajs/react';
import { History, Milestone, RefreshCw, Trash2 } from 'lucide-react';
import { useMemo, useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { deleteCheck, runCheck } from '@/features/model-graduation/api';
import { DemoBanner } from '@/features/model-graduation/components/demo-banner';
import { GateVerdict } from '@/features/model-graduation/components/gate-verdict';
import { ModelSummary } from '@/features/model-graduation/components/model-summary';
import { RequirementDialog } from '@/features/model-graduation/components/requirement-dialog';
import { RequirementLedger } from '@/features/model-graduation/components/requirement-ledger';
import { StageRail } from '@/features/model-graduation/components/stage-rail';
import { formatRelative } from '@/features/model-graduation/constants';
import {
    getChecksSnapshot,
    getServerChecksSnapshot,
    subscribeChecks,
    toSummary,
} from '@/features/model-graduation/mock-engine';
import type { Requirement } from '@/features/model-graduation/types';

export default function ModelGraduation() {
    // Model Graduation is a frontend-only demo (no server data behind it) —
    // checks live in localStorage, seeded with one on first visit. Read via
    // useSyncExternalStore (the same pattern as useAppearance/useIsMobile and
    // the Attrition Risk demo) rather than a useEffect + setState: its
    // getServerSnapshot keeps the SSR pass and the first client render both
    // rendering "no checks yet", so no hydration mismatch is possible.
    const checks = useSyncExternalStore(
        subscribeChecks,
        getChecksSnapshot,
        getServerChecksSnapshot,
    );

    const [activeHashid, setActiveHashid] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [detail, setDetail] = useState<Requirement | null>(null);

    const check =
        checks.find((c) => c.hashid === activeHashid) ?? checks[0] ?? null;
    const summaries = useMemo(() => checks.map(toSummary), [checks]);

    const handleCheck = () => {
        runCheck({
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            // The store's own notify() already re-renders `checks` with the new
            // check in front; just point the view at it.
            onSuccess: (newCheck) => setActiveHashid(newCheck.hashid),
        });
    };

    const handleDelete = () => {
        if (!check || !window.confirm('Delete this readiness check?')) {
            return;
        }

        // If the deleted check was active, `check` falls back to `checks[0]` on
        // its own once the store notifies — no need to manage that here.
        deleteCheck(check.hashid, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title="Model Graduation" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            <Milestone className="size-5 text-[#0ABFBF]" />
                            Model Graduation
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            Predictions currently come from a model trained on a
                            general public dataset. This page tracks how much of
                            this organisation’s own history exists, and holds
                            retraining shut until there is enough of it to learn
                            from honestly.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        onClick={handleCheck}
                        disabled={processing}
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <RefreshCw className="size-4" />
                        )}
                        {processing ? 'Checking…' : 'Re-check readiness'}
                    </Button>
                </div>

                <DemoBanner />

                {!check ? (
                    <EmptyState processing={processing} onCheck={handleCheck} />
                ) : (
                    <>
                        <StageRail stage={check.stage} />

                        <GateVerdict check={check} />

                        {/* Check meta + history */}
                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>
                                Last checked {formatRelative(check.checked_at)}
                            </span>
                            <div className="flex items-center gap-2">
                                {summaries.length > 1 && (
                                    <div className="flex items-center gap-1.5">
                                        <History className="size-3.5" />
                                        <Select
                                            value={check.hashid}
                                            onValueChange={setActiveHashid}
                                        >
                                            <SelectTrigger className="h-8 w-60 text-xs">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {summaries.map((c) => (
                                                    <SelectItem
                                                        key={c.hashid}
                                                        value={c.hashid}
                                                    >
                                                        {formatRelative(
                                                            c.checked_at,
                                                        )}{' '}
                                                        · {c.met_count} of{' '}
                                                        {c.total_count} met
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="h-8 text-muted-foreground hover:text-destructive"
                                    onClick={handleDelete}
                                    disabled={deleting}
                                >
                                    <Trash2 className="size-3.5" />
                                    Delete
                                </Button>
                            </div>
                        </div>

                        <ModelSummary check={check} />

                        <RequirementLedger
                            requirements={check.requirements}
                            bindingKey={check.binding_key}
                            onOpen={setDetail}
                        />
                    </>
                )}
            </div>

            <RequirementDialog
                requirement={detail}
                onOpenChange={(open) => !open && setDetail(null)}
            />
        </>
    );
}

function EmptyState({
    processing,
    onCheck,
}: {
    processing: boolean;
    onCheck: () => void;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-12 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Milestone className="size-6" />
            </span>
            <p className="text-sm font-medium">No readiness check yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Run a check to see how much of this organisation’s own history
                exists, and what still stands between it and a locally trained
                model.
            </p>
            <Button className="mt-1" onClick={onCheck} disabled={processing}>
                {processing ? <Spinner /> : <RefreshCw className="size-4" />}
                {processing ? 'Checking…' : 'Run first check'}
            </Button>
        </div>
    );
}

ModelGraduation.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: '/analytics/model-graduation' },
        { title: 'Model Graduation', href: '/analytics/model-graduation' },
    ],
};
