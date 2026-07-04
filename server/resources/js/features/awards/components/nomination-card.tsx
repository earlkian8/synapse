import { Award, ChevronDown, History, Medal, Plus } from 'lucide-react';
import { useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import {
    awardColorStyle,
    BAND_LABELS,
    BAND_TONES,
    DEFAULT_AWARD_COLOR,
    SIGNAL_COLORS,
} from '../constants';
import type { AwardNomination, Nominee } from '../types';

type Props = {
    nomination: AwardNomination;
    canManage: boolean;
    onGive: (employeeId: number, typeId: number) => void;
};

/**
 * One award type's panel on the nomination board: its focus profile and the
 * ranked shortlist of who deserves it most. Every nominee expands into the
 * transparent scoring breakdown — a contribution bar whose segments are the
 * signals (performance, attendance, training, tenure, recognition gap, ML
 * forecast), each in its fixed hue.
 */
export function NominationCard({ nomination, canManage, onGive }: Props) {
    const { type, profile, nominees } = nomination;
    const accent = type.color ?? DEFAULT_AWARD_COLOR;

    return (
        <section className="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            {/* Type header, tinted by the award's own accent */}
            <header
                className="flex items-start gap-3 border-b border-sidebar-border/60 px-4 py-3.5 dark:border-sidebar-border"
                style={{ backgroundColor: `${accent}0d` }}
            >
                <span
                    className="flex size-9 shrink-0 items-center justify-center rounded-lg"
                    style={{ backgroundColor: `${accent}1a`, color: accent }}
                >
                    <Award className="size-4.5" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="text-sm font-semibold tracking-tight">
                            {type.name}
                        </h2>
                        <span
                            className="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-medium"
                            style={awardColorStyle(type.color)}
                            title={profile.hint}
                        >
                            {profile.label}
                        </span>
                    </div>
                    {type.description && (
                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                            {type.description}
                        </p>
                    )}
                </div>
            </header>

            {nominees.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                    No signals to rank yet — appraisals, attendance and
                    trainings feed this shortlist.
                </p>
            ) : (
                <ul className="divide-y divide-border">
                    {nominees.map((nominee) => (
                        <NomineeRow
                            key={nominee.employee.id}
                            nominee={nominee}
                            typeId={type.id}
                            canManage={canManage}
                            onGive={onGive}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

function NomineeRow({
    nominee,
    typeId,
    canManage,
    onGive,
}: {
    nominee: Nominee;
    typeId: number;
    canManage: boolean;
    onGive: (employeeId: number, typeId: number) => void;
}) {
    // The front-runner opens with their breakdown visible; the rest expand.
    const [open, setOpen] = useState(nominee.rank === 1);
    const detailsId = `nominee-${typeId}-${nominee.employee.id}`;

    return (
        <li className={cn(nominee.rank === 1 && 'bg-muted/30')}>
            <div className="flex items-center gap-3 px-4 py-3">
                <RankBadge rank={nominee.rank} />
                <PersonAvatar
                    name={nominee.employee.full_name}
                    initials={nominee.employee.initials}
                    photo={nominee.employee.photo}
                    className={cn(nominee.rank === 1 ? 'size-10' : 'size-8')}
                />
                <button
                    type="button"
                    onClick={() => setOpen((v) => !v)}
                    aria-expanded={open}
                    aria-controls={detailsId}
                    className="flex min-w-0 flex-1 items-center gap-2 text-left"
                >
                    <span className="min-w-0 flex-1">
                        <span className="flex flex-wrap items-center gap-1.5">
                            <span
                                className={cn(
                                    'truncate text-sm font-medium',
                                    nominee.rank === 1 && 'font-semibold',
                                )}
                            >
                                {nominee.employee.full_name}
                            </span>
                            {nominee.recent_winner && (
                                <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                    <History className="size-3" />
                                    Won{' '}
                                    {nominee.won_months_ago === 0
                                        ? 'this month'
                                        : `${nominee.won_months_ago}mo ago`}
                                </span>
                            )}
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {[
                                nominee.employee.position,
                                nominee.employee.department,
                            ]
                                .filter(Boolean)
                                .join(' · ') || nominee.employee.employee_no}
                        </span>
                    </span>
                    <ChevronDown
                        className={cn(
                            'size-4 shrink-0 text-muted-foreground transition-transform',
                            open && 'rotate-180',
                        )}
                    />
                </button>
                <div className="flex w-14 flex-col items-end">
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            BAND_TONES[nominee.band],
                        )}
                    >
                        {nominee.score}
                    </span>
                    <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                        {BAND_LABELS[nominee.band]}
                    </span>
                </div>
                {canManage && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        onClick={() => onGive(nominee.employee.id, typeId)}
                    >
                        <Plus className="size-3.5" />
                        Give
                    </Button>
                )}
            </div>

            {open && (
                <div id={detailsId} className="px-4 pb-3 pl-[3.75rem]">
                    <Breakdown nominee={nominee} />
                </div>
            )}
        </li>
    );
}

/** #1 gold, #2 silver, #3 bronze; the rest plain. */
function RankBadge({ rank }: { rank: number }) {
    const styles: Record<number, string> = {
        1: 'border-amber-400/40 bg-amber-400/15 text-amber-600 dark:text-amber-400',
        2: 'border-slate-400/40 bg-slate-400/15 text-slate-600 dark:text-slate-300',
        3: 'border-orange-600/30 bg-orange-600/10 text-orange-700 dark:text-orange-400',
    };

    return (
        <span
            className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-bold tabular-nums',
                styles[rank] ?? 'border-border bg-muted text-muted-foreground',
            )}
            aria-label={`Rank ${rank}`}
        >
            {rank === 1 ? <Medal className="size-3.5" /> : rank}
        </span>
    );
}

/**
 * The transparent "why": a contribution bar segmented by signal (out of the
 * profile's full 100), then each signal's grounded detail.
 */
function Breakdown({ nominee }: { nominee: Nominee }) {
    const available = nominee.components.reduce((sum, c) => sum + c.max, 0);

    return (
        <div className="flex flex-col gap-2 rounded-lg border border-sidebar-border/60 bg-card/60 p-3 dark:border-sidebar-border">
            <div
                className="flex h-1.5 w-full gap-px overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`Fit score ${nominee.score} out of 100`}
            >
                {nominee.components.map((component) =>
                    component.points > 0 ? (
                        <div
                            key={component.key}
                            className="h-full"
                            style={{
                                width: `${(component.points / available) * 100}%`,
                                backgroundColor: SIGNAL_COLORS[component.key],
                            }}
                        />
                    ) : null,
                )}
            </div>

            <ul className="flex flex-col gap-1">
                {nominee.components.map((component) => (
                    <li
                        key={component.key}
                        className="flex items-center gap-2 text-xs"
                    >
                        <span
                            className="size-2 shrink-0 rounded-full"
                            style={{
                                backgroundColor: SIGNAL_COLORS[component.key],
                            }}
                        />
                        <span className="w-24 shrink-0 font-medium">
                            {component.label}
                        </span>
                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                            {component.detail}
                        </span>
                        <span className="shrink-0 text-muted-foreground tabular-nums">
                            {component.points}/{component.max}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
