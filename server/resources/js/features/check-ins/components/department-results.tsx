import { EyeOff } from 'lucide-react';
import { formatMean, formatShare } from '../constants';
import type { DepartmentResult, PeriodResults } from '../types';
import { MoodBar } from './mood-charts';

/**
 * Each department's month (ADR 0102): how its answers split across the moods, and
 * its satisfaction, engagement and share thinking of leaving. Departments under the
 * five-answer bar are pooled into "Other teams", and that pool too is shown only at
 * five or more. Sorted by name, never ranked: this is a read of the climate, not a
 * league table.
 */
export function DepartmentResults({
    results,
    minGroup,
}: {
    results: PeriodResults;
    minGroup: number;
}) {
    const { departments, others } = results;

    if (departments.length === 0 && !others) {
        return (
            <p className="rounded-xl border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                No one has answered this month yet.
            </p>
        );
    }

    const rows = [
        ...departments.map((d) => ({
            key: String(d.id ?? 'none'),
            name: d.name,
            group: d as DepartmentResult,
            answered: `${d.answers} of ${d.people}`,
        })),
        ...(others
            ? [
                  {
                      key: 'others',
                      name: `Other teams (${others.teams})`,
                      group: {
                          ...others,
                          id: null,
                          name: 'Other teams',
                          people: 0,
                      } as DepartmentResult,
                      answered: String(others.answers),
                  },
              ]
            : []),
    ];

    return (
        <>
            {/* On a phone: a card per team, the same figures stacked. */}
            <ul className="flex flex-col gap-2 sm:hidden">
                {rows.map((row) => (
                    <li
                        key={row.key}
                        className="flex flex-col gap-2.5 rounded-xl border border-sidebar-border/70 p-3.5 dark:border-sidebar-border"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">{row.name}</span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {row.answered} answered
                            </span>
                        </div>
                        {row.group.shown ? (
                            <>
                                <MoodBar group={row.group} label={row.name} />
                                <dl className="grid grid-cols-4 gap-2 text-center">
                                    {[
                                        ['Mood', formatMean(row.group.mood)],
                                        [
                                            'Satisfied',
                                            formatMean(row.group.satisfaction),
                                        ],
                                        [
                                            'Engaged',
                                            formatMean(row.group.engagement),
                                        ],
                                        [
                                            'Leaving',
                                            formatShare(row.group.leave_share),
                                        ],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label}
                                            className="flex flex-col"
                                        >
                                            <dd className="text-sm font-semibold tabular-nums">
                                                {value}
                                            </dd>
                                            <dt className="text-[11px] text-muted-foreground">
                                                {label}
                                            </dt>
                                        </div>
                                    ))}
                                </dl>
                            </>
                        ) : (
                            <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                <EyeOff className="size-3.5" aria-hidden />
                                Fewer than {minGroup} answers together, so not
                                shown
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            <div className="hidden overflow-x-auto rounded-xl border border-sidebar-border/70 sm:block dark:border-sidebar-border">
                <table className="w-full min-w-[44rem] text-sm">
                    <thead>
                        <tr className="border-b border-border text-left text-xs text-muted-foreground">
                            <th scope="col" className="px-4 py-2.5 font-medium">
                                Team
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2.5 text-right font-medium"
                            >
                                Answered
                            </th>
                            <th
                                scope="col"
                                className="w-[30%] px-3 py-2.5 font-medium"
                            >
                                Mood
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2.5 text-right font-medium"
                            >
                                Satisfaction
                            </th>
                            <th
                                scope="col"
                                className="px-3 py-2.5 text-right font-medium"
                            >
                                Engagement
                            </th>
                            <th
                                scope="col"
                                className="px-4 py-2.5 text-right font-medium"
                            >
                                Often think of leaving
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {departments.map((d) => (
                            <Row
                                key={d.id ?? 'none'}
                                name={d.name}
                                group={d}
                                answered={`${d.answers} of ${d.people}`}
                            />
                        ))}
                        {others && (
                            <Row
                                name={`Other teams (${others.teams})`}
                                hint={`Teams with fewer than ${minGroup} answers each, together`}
                                group={{
                                    ...others,
                                    id: null,
                                    name: 'Other teams',
                                    people: 0,
                                }}
                                answered={String(others.answers)}
                                hiddenNote={`Together still fewer than ${minGroup} answers`}
                            />
                        )}
                    </tbody>
                </table>
            </div>
        </>
    );
}

function Row({
    name,
    hint,
    group,
    answered,
    hiddenNote = `Fewer than five answers`,
}: {
    name: string;
    hint?: string;
    group: DepartmentResult;
    answered: string;
    hiddenNote?: string;
}) {
    return (
        <tr>
            <th scope="row" className="px-4 py-3 text-left font-medium">
                {name}
                {hint && (
                    <span className="block text-xs font-normal text-muted-foreground">
                        {hint}
                    </span>
                )}
            </th>
            <td className="px-3 py-3 text-right text-muted-foreground tabular-nums">
                {answered}
            </td>
            {group.shown ? (
                <>
                    <td className="px-3 py-3">
                        <div className="flex items-center gap-3">
                            <MoodBar
                                group={group}
                                label={name}
                                className="flex-1"
                            />
                            <span className="w-7 text-right font-medium tabular-nums">
                                {formatMean(group.mood)}
                            </span>
                        </div>
                    </td>
                    <td className="px-3 py-3 text-right tabular-nums">
                        {formatMean(group.satisfaction)}
                    </td>
                    <td className="px-3 py-3 text-right tabular-nums">
                        {formatMean(group.engagement)}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums">
                        {formatShare(group.leave_share)}
                    </td>
                </>
            ) : (
                <td colSpan={4} className="px-3 py-3 text-muted-foreground">
                    <span className="flex items-center gap-2 text-xs">
                        <EyeOff className="size-3.5" aria-hidden />
                        {hiddenNote}, so not shown
                    </span>
                </td>
            )}
        </tr>
    );
}
