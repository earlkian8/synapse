import { Building2, Check, Minus } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import SynapseField from '@/components/synapse-field';
import type { CompanyProfile } from '@/features/company-profile/types';
import { cn } from '@/lib/utils';
import { STEPS } from '../constants';
import type { SetupStep, StepStatus } from '../types';
import type { WizardView } from '../use-setup-wizard';

type Props = {
    company: CompanyProfile;
    logoPreview: string | null;
    statuses: Record<SetupStep, StepStatus>;
    can: Record<SetupStep, boolean>;
    view: WizardView;
    counts: { answered: number; total: number; percent: number };
    onSelect: (view: WizardView) => void;
    email: string;
    onLeave: () => void;
    leaving: boolean;
};

/**
 * The wizard's left rail: whose company this is, how far through it is, and the
 * six steps as a ladder you can move around freely.
 *
 * It carries SYNAPSE's deep-navy chrome — the same field the workspace picker and
 * the sign-in screens use — because setup is the last stretch of the road that
 * starts at registration, and it should read as the same place. The working pane
 * beside it stays on the app's own light surface, which is where the owner is
 * about to spend their time.
 */
export default function WizardRail({
    company,
    logoPreview,
    statuses,
    can,
    view,
    counts,
    onSelect,
    email,
    onLeave,
    leaving,
}: Props) {
    return (
        <aside className="relative hidden w-[19rem] shrink-0 flex-col overflow-hidden bg-[#0F2044] text-white lg:flex xl:w-[21rem]">
            <SynapseField />

            <div className="relative z-10 flex h-full flex-col px-7 py-7">
                <div className="flex items-center gap-2.5">
                    <AppLogoIcon surface="dark" className="h-6 w-auto" />
                    <span className="text-[11px] font-bold tracking-[0.22em] text-white/85 uppercase">
                        Synapse
                    </span>
                </div>

                {/* Whose company this is */}
                <div className="mt-9 flex items-center gap-3">
                    <div className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white/10 ring-1 ring-white/10">
                        {logoPreview ? (
                            <img
                                src={logoPreview}
                                alt=""
                                className="size-full object-contain"
                            />
                        ) : company.initials ? (
                            <span className="text-sm font-semibold tracking-wide text-white/80 uppercase">
                                {company.initials}
                            </span>
                        ) : (
                            <Building2 className="size-5 text-white/60" />
                        )}
                    </div>
                    <div className="min-w-0">
                        <p className="truncate text-[15px] font-semibold text-white">
                            {company.name}
                        </p>
                        <p className="text-xs text-white/45">Company setup</p>
                    </div>
                </div>

                {/* How far through */}
                <div className="mt-6">
                    <div className="flex items-baseline justify-between text-xs">
                        <span className="text-white/45">
                            {counts.answered} of {counts.total} settled
                        </span>
                        <span className="font-medium text-[#0ABFBF]">
                            {counts.percent}%
                        </span>
                    </div>
                    <div className="mt-2 h-1 overflow-hidden rounded-full bg-white/10">
                        <div
                            className="h-full rounded-full bg-[#0ABFBF] transition-[width] duration-500 ease-out"
                            style={{ width: `${counts.percent}%` }}
                        />
                    </div>
                </div>

                {/* The ladder */}
                <nav aria-label="Setup steps" className="mt-7 flex flex-col">
                    {STEPS.map((meta, index) => (
                        <RailStep
                            key={meta.step}
                            index={index + 1}
                            label={meta.label}
                            purpose={meta.purpose}
                            status={statuses[meta.step]}
                            active={view === meta.step}
                            allowed={can[meta.step]}
                            last={index === STEPS.length - 1}
                            onSelect={() => onSelect(meta.step)}
                        />
                    ))}
                </nav>

                <div className="mt-auto pt-8">
                    <button
                        type="button"
                        onClick={onLeave}
                        disabled={leaving}
                        className="text-xs font-medium text-white/45 underline-offset-4 transition-colors hover:text-white hover:underline disabled:opacity-50"
                    >
                        I'll set this up later
                    </button>
                    <p className="mt-3 truncate text-[11px] text-white/25">
                        {email}
                    </p>
                </div>
            </div>
        </aside>
    );
}

/**
 * One rung. The marker states what happened to the step — a tick for done, a
 * dash for deliberately skipped, its number otherwise — so the ladder is a record
 * rather than decoration.
 */
function RailStep({
    index,
    label,
    purpose,
    status,
    active,
    allowed,
    last,
    onSelect,
}: {
    index: number;
    label: string;
    purpose: string;
    status: StepStatus;
    active: boolean;
    allowed: boolean;
    last: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onSelect}
            aria-current={active ? 'step' : undefined}
            className="group relative flex items-start gap-3 rounded-lg py-2 pr-2 pl-0 text-left focus-visible:ring-2 focus-visible:ring-[#0ABFBF]/50 focus-visible:outline-none"
        >
            <span className="relative flex flex-col items-center self-stretch">
                <span
                    className={cn(
                        'flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold transition-colors',
                        status === 'done'
                            ? 'border-[#0ABFBF] bg-[#0ABFBF] text-[#0F2044]'
                            : status === 'skipped'
                              ? 'border-white/20 bg-white/5 text-white/40'
                              : active
                                ? 'border-[#0ABFBF] text-[#0ABFBF]'
                                : 'border-white/20 text-white/45',
                    )}
                >
                    {status === 'done' ? (
                        <Check className="size-3" strokeWidth={3} />
                    ) : status === 'skipped' ? (
                        <Minus className="size-3" strokeWidth={3} />
                    ) : (
                        index
                    )}
                </span>
                {!last && (
                    <span
                        className={cn(
                            'mt-1 w-px flex-1',
                            status === 'done'
                                ? 'bg-[#0ABFBF]/40'
                                : 'bg-white/10',
                        )}
                    />
                )}
            </span>

            <span className="min-w-0 flex-1 pb-3">
                <span
                    className={cn(
                        'flex items-center gap-2 text-[13px] font-medium transition-colors',
                        active
                            ? 'text-white'
                            : 'text-white/60 group-hover:text-white/85',
                    )}
                >
                    {label}
                    {!allowed && (
                        <span className="rounded bg-white/10 px-1.5 py-px text-[10px] font-normal text-white/40">
                            No access
                        </span>
                    )}
                    {status === 'skipped' && (
                        <span className="text-[10px] font-normal text-white/30">
                            skipped
                        </span>
                    )}
                </span>
                {active && (
                    <span className="mt-1 block text-[11px] leading-relaxed text-white/40">
                        {purpose}
                    </span>
                )}
            </span>
        </button>
    );
}
