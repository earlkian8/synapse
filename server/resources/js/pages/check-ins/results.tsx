import { Head, router, usePage } from '@inertiajs/react';
import {
    DoorOpen,
    Gauge,
    MessageSquareHeart,
    ShieldCheck,
    Sparkles,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader, StatTiles } from '@/components/data-table';
import type { StatTile } from '@/components/data-table/stat-tiles';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { CheckInsNav } from '@/features/check-ins/components/check-ins-nav';
import { DepartmentResults } from '@/features/check-ins/components/department-results';
import {
    MeasureTrend,
    MoodTrend,
} from '@/features/check-ins/components/mood-charts';
import { MoodFace } from '@/features/check-ins/components/mood-face';
import {
    formatMean,
    formatShare,
    monthName,
} from '@/features/check-ins/constants';
import { checkInRoutes } from '@/features/check-ins/routes';
import type { ResultsPageProps } from '@/features/check-ins/types';
import { BreakdownSection } from '@/features/prediction-insights/components/breakdown-dialog';

/**
 * Team results (ADR 0102): how a month felt across the company and in each
 * department, and the company over the last year — mood, job satisfaction,
 * engagement and how many often think about leaving. Only groups of five or more
 * answers are shown; nothing here can be traced to one person.
 */
export default function CheckInResultsPage() {
    const { results, trend, periods, min_group, enabled, can } =
        usePage<ResultsPageProps>().props;
    const company = results.company;
    const [saving, setSaving] = useState(false);

    const tiles: StatTile[] = [
        {
            key: 'answered',
            label: 'Answered',
            value: company.answers,
            hint: `of ${company.people} · ${company.people > 0 ? Math.round((company.answers / company.people) * 100) : 0}%`,
            icon: Users,
            accent: 'slate',
        },
        {
            key: 'mood',
            label: 'Mood',
            value: company.shown ? (
                <span className="inline-flex items-center gap-2">
                    <MoodFace mood={company.mood ?? null} size={22} />
                    {formatMean(company.mood)}
                </span>
            ) : (
                '—'
            ),
            hint: company.shown ? 'of 5' : undefined,
            icon: MessageSquareHeart,
            accent: 'indigo',
        },
        {
            key: 'engagement',
            label: 'Engagement',
            value: company.shown ? formatMean(company.engagement) : '—',
            hint: company.shown ? 'of 6' : undefined,
            icon: Sparkles,
            accent: 'amber',
        },
        {
            key: 'leaving',
            label: 'Leaving',
            value: company.shown ? formatShare(company.leave_share) : '—',
            hint: company.shown ? 'often' : undefined,
            icon: DoorOpen,
            accent: 'rose',
        },
    ];

    const toggle = (on: boolean) => {
        setSaving(true);
        router.post(
            checkInRoutes.settings,
            { check_ins_enabled: on },
            { preserveScroll: true, onFinish: () => setSaving(false) },
        );
    };

    return (
        <>
            <Head title="Check-in results" />
            <PageBody>
                <PageHeader
                    title="Check-in results"
                    description={`How ${monthName(results.period)} felt, from the monthly check-ins. Only groups of ${min_group} or more are shown.`}
                    actions={<CheckInsNav current="results" />}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Select
                        value={results.period}
                        onValueChange={(period) =>
                            router.get(
                                checkInRoutes.results(period),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <SelectTrigger className="w-48" aria-label="Month">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {periods.map((p) => (
                                <SelectItem key={p.value} value={p.value}>
                                    {p.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {can.manage ? (
                        <div className="flex items-center gap-2.5">
                            <Switch
                                id="check-ins-enabled"
                                checked={enabled}
                                disabled={saving}
                                onCheckedChange={toggle}
                            />
                            <Label
                                htmlFor="check-ins-enabled"
                                className="text-sm"
                            >
                                Monthly check-ins {enabled ? 'on' : 'off'}
                            </Label>
                        </div>
                    ) : (
                        !enabled && (
                            <p className="text-sm text-muted-foreground">
                                Check-ins are turned off.
                            </p>
                        )
                    )}
                </div>

                <StatTiles tiles={tiles} />

                {!company.shown && (
                    <p className="flex items-start gap-2 rounded-xl border border-dashed border-border px-4 py-3 text-sm text-muted-foreground">
                        <ShieldCheck
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden
                        />
                        {company.answers === 0
                            ? `No one has answered ${monthName(results.period)}'s check-in yet.`
                            : `${company.answers} ${company.answers === 1 ? 'person has' : 'people have'} answered so far. Results appear at ${min_group}, so no one's answers can be picked out.`}
                    </p>
                )}

                <div className="flex flex-col gap-4">
                    <BreakdownSection
                        title="Mood over the year"
                        description="How everyone's answers split, month by month. An outlined month had fewer than five answers."
                    >
                        <MoodTrend points={trend} selected={results.period} />
                    </BreakdownSection>

                    <div className="grid gap-3 md:grid-cols-3">
                        <MeasureTrend
                            title="Job satisfaction"
                            points={trend}
                            value={(p) => p.satisfaction}
                            domain={[1, 5]}
                            ticks={[1, 3, 5]}
                            format={(v) => v.toFixed(1)}
                        />
                        <MeasureTrend
                            title="Engagement"
                            points={trend}
                            value={(p) => p.engagement}
                            domain={[0, 6]}
                            ticks={[0, 3, 6]}
                            format={(v) => v.toFixed(1)}
                        />
                        <MeasureTrend
                            title="Often think of leaving"
                            points={trend}
                            value={(p) => p.leave_share}
                            domain={[0, 1]}
                            ticks={[0, 0.5, 1]}
                            format={(v) => `${Math.round(v * 100)}%`}
                        />
                    </div>
                </div>

                <BreakdownSection
                    title={`By team, ${monthName(results.period, true)}`}
                    description="Sorted by name. A team shows once it has five answers; smaller ones are pooled, and when even the pool is small the next-smallest team joins it, so a hidden team can't be worked out from the others."
                >
                    <DepartmentResults results={results} minGroup={min_group} />
                </BreakdownSection>

                <section className="grid gap-3 rounded-2xl border border-sidebar-border/70 p-5 text-sm sm:grid-cols-3 dark:border-sidebar-border">
                    <div className="flex items-start gap-3 sm:col-span-3">
                        <Gauge
                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <p className="text-muted-foreground">
                            <span className="font-medium text-foreground">
                                What the numbers are.
                            </span>{' '}
                            Mood and satisfaction run 1 to 5. Engagement is the
                            three-item Utrecht Work Engagement Scale, 0 (never)
                            to 6 (every day). "Often think of leaving" is the
                            share answering often or always to "How often have
                            you considered leaving your job?" — one of the
                            strongest published signs that people will. A team's
                            latest shown result also feeds the attrition model
                            your company trains on its own records, never
                            anyone's own answers.
                        </p>
                    </div>
                </section>
            </PageBody>
        </>
    );
}

CheckInResultsPage.layout = {
    breadcrumbs: [
        { title: 'Check-ins', href: '/check-ins' },
        { title: 'Team results', href: '/check-ins/results' },
    ],
};
