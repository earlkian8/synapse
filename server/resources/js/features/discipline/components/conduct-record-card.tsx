import { Link } from '@inertiajs/react';
import { calendarDate } from '../constants';
import type { ConductRecord } from '../types';
import { StandingBadge } from './case-badges';

/**
 * Where somebody stands (ADR 0105), and the decisions that still count against
 * them — each until its offense's period has run. After that a decision stays on
 * file but no longer makes the next offense a repeat.
 */
export function ConductRecordCard({
    record,
    caseHref,
}: {
    record: ConductRecord;
    caseHref: (hashid: string) => string;
}) {
    return (
        <section className="space-y-4 rounded-xl border bg-card p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <StandingBadge standing={record.standing} />
                <p className="text-xs text-muted-foreground tabular-nums">
                    {record.upheld} upheld, {record.cleared} not upheld,{' '}
                    {record.open} open
                </p>
            </div>
            {record.active.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No decision counts against them now.
                </p>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {record.active.map((item) => (
                        <li
                            key={item.hashid}
                            className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-3 py-2.5 text-sm"
                        >
                            <Link
                                href={caseHref(item.hashid)}
                                className="font-medium hover:underline"
                            >
                                {item.offense ?? item.reference}
                                <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                    {item.reference}
                                </span>
                            </Link>
                            <span className="text-muted-foreground">
                                {item.penalty_label}, counts until{' '}
                                {calendarDate(item.counts_until)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
