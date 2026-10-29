import { Check, CircleDashed, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FIT_BAND_BARS, FIT_BAND_LABELS, FIT_BAND_STYLES } from '../constants';
import type { Fit, FitBreakdownItem, FitRank, SkillEvidence } from '../types';

/**
 * A compact fit pill (score + band colour) for table rows and cards. The
 * automatic ranking score the ApplicantScorer assigns — higher means a stronger
 * on-paper match for the role.
 */
export function FitBadge({
    fit,
    rank,
    className,
}: {
    fit: Fit | null;
    rank?: FitRank | null;
    className?: string;
}) {
    if (!fit) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    return (
        <span className={cn('inline-flex items-center gap-1.5', className)}>
            <span
                className={cn(
                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold tabular-nums',
                    FIT_BAND_STYLES[fit.band],
                )}
                title={FIT_BAND_LABELS[fit.band]}
            >
                {fit.value}
                <span className="text-[10px] font-normal opacity-70">fit</span>
            </span>
            {rank && rank.position <= 3 && (
                <span className="text-[10px] font-medium text-muted-foreground">
                    #{rank.position}
                </span>
            )}
        </span>
    );
}

/**
 * The fit score as a labelled meter with its component breakdown — the expanded
 * form shown in the candidate detail drawer.
 */
export function FitMeter({ fit }: { fit: Fit }) {
    return (
        <div className="space-y-3">
            <div className="flex items-end justify-between">
                <div>
                    <span className="text-3xl font-semibold tabular-nums">
                        {fit.value}
                    </span>
                    <span className="ml-1 text-sm text-muted-foreground">
                        / 100
                    </span>
                </div>
                <span
                    className={cn(
                        'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                        FIT_BAND_STYLES[fit.band],
                    )}
                >
                    {FIT_BAND_LABELS[fit.band]}
                </span>
            </div>

            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        FIT_BAND_BARS[fit.band],
                    )}
                    style={{ width: `${fit.value}%` }}
                />
            </div>

            <ul className="space-y-1.5">
                {fit.breakdown.map((item) => (
                    <li key={item.key} className="text-sm">
                        <div className="flex items-center justify-between gap-3">
                            <span className="flex min-w-0 flex-wrap items-baseline gap-x-2">
                                <span className="text-muted-foreground">
                                    {item.label}
                                </span>
                                <span className="text-xs text-muted-foreground/70">
                                    {item.detail}
                                </span>
                            </span>
                            <span className="shrink-0 font-medium tabular-nums">
                                {item.points}
                                <span className="text-muted-foreground">
                                    /{item.max}
                                </span>
                            </span>
                        </div>
                        {item.skills && item.skills.length > 0 && (
                            <SkillEvidenceList item={item} />
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Where the skills were looked for, when it isn't simply the résumé. */
const RESUME_NOTES: Record<string, string> = {
    pending:
        'Still reading the résumé — reopen in a moment for the full picture.',
    unreadable:
        'The résumé’s text couldn’t be read (a scan or a photo?), so skills come from the profile and cover note.',
    none: 'No résumé on file, so skills come from the profile and cover note.',
};

/**
 * How each of the posting's skills was shown (ADR 0097): by name, through a
 * skill that proves it ("JavaScript — shown by TypeScript"), partly through
 * a related one, or not at all.
 */
function SkillEvidenceList({ item }: { item: FitBreakdownItem }) {
    const skills = item.skills ?? [];
    const must = skills.filter((skill) => skill.tier === 'must');
    const nice = skills.filter((skill) => skill.tier === 'nice');
    const note = item.resume ? RESUME_NOTES[item.resume] : undefined;

    return (
        <div className="mt-2 space-y-2 rounded-lg border border-border/70 bg-muted/30 p-2.5">
            {[
                { title: nice.length > 0 ? 'Must-have' : null, list: must },
                { title: 'Nice to have', list: nice },
            ]
                .filter((group) => group.list.length > 0)
                .map((group) => (
                    <div key={group.title ?? 'skills'}>
                        {group.title && (
                            <p className="mb-1 text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                {group.title}
                            </p>
                        )}
                        <ul className="space-y-1">
                            {group.list.map((skill) => (
                                <SkillEvidenceRow
                                    key={`${skill.tier}-${skill.skill}`}
                                    skill={skill}
                                />
                            ))}
                        </ul>
                    </div>
                ))}
            {note && (
                <p className="text-[11px] leading-snug text-muted-foreground">
                    {note}
                </p>
            )}
        </div>
    );
}

function SkillEvidenceRow({ skill }: { skill: SkillEvidence }) {
    const shown = skill.credit >= 1;
    const partly = skill.credit > 0 && !shown;

    return (
        <li className="flex items-start gap-2 text-xs">
            {shown ? (
                <Check className="mt-0.5 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
            ) : partly ? (
                <CircleDashed className="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
            ) : (
                <X className="mt-0.5 size-3.5 shrink-0 text-muted-foreground/60" />
            )}
            <span className="min-w-0">
                <span
                    className={cn(
                        'font-medium',
                        !shown && !partly && 'text-muted-foreground',
                    )}
                >
                    {skill.skill}
                </span>
                <span className="text-muted-foreground">
                    {' — '}
                    {explainSkill(skill)}
                </span>
            </span>
        </li>
    );
}

/** One skill's reading in words: "shown by TypeScript · résumé". */
function explainSkill(skill: SkillEvidence): string {
    const reading = (() => {
        switch (skill.status) {
            case 'found':
                return skill.via ? `as “${skill.via}”` : 'by name';
            case 'implied':
                return `shown by ${skill.via}`;
            case 'parts':
                return `shown by ${skill.via}`;
            case 'spelling':
                return `spelled “${skill.via}”`;
            case 'related':
                return `related: ${skill.via} · ${Math.round(skill.credit * 100)}% credit`;
            default:
                return 'not found';
        }
    })();

    return skill.source ? `${reading} · ${skill.source}` : reading;
}
