import { Head, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import AttendanceStep from '@/features/setup-wizard/components/attendance-step';
import CompanyStep from '@/features/setup-wizard/components/company-step';
import DepartmentsStep from '@/features/setup-wizard/components/departments-step';
import DoneScreen from '@/features/setup-wizard/components/done-screen';
import IntroScreen from '@/features/setup-wizard/components/intro-screen';
import LeaveTypesStep from '@/features/setup-wizard/components/leave-types-step';
import PerformanceStep from '@/features/setup-wizard/components/performance-step';
import RecruitmentStep from '@/features/setup-wizard/components/recruitment-step';
import StepFooter from '@/features/setup-wizard/components/step-footer';
import ToastClearance from '@/features/setup-wizard/components/toast-clearance';
import WizardRail from '@/features/setup-wizard/components/wizard-rail';
import { STEP_META, STEPS } from '@/features/setup-wizard/constants';
import type {
    SetupStep,
    SetupWizardPageProps,
} from '@/features/setup-wizard/types';
import { useSetupWizard } from '@/features/setup-wizard/use-setup-wizard';
import type { Auth } from '@/types';

/**
 * Company setup — the guided walk-through a brand-new company is taken through
 * before its dashboard.
 *
 * Registration provisions an empty tenant, so the owner's first sign-in used to
 * land on a dashboard of zeroes with nine Company Setup screens behind it and
 * nothing saying which mattered. This walks the six that block day-one work,
 * every one of them skippable, and every one of them writing to the same tables
 * the Company Setup screens do — the wizard is a route through Company Setup, not
 * a parallel copy of it.
 *
 * It brings its own chrome rather than the app shell: the deep-navy rail is the
 * same field the sign-in screens and the workspace picker use, because setup is
 * the last stretch of the road that starts at registration. The working pane
 * beside it is already the app's own light surface — which is where the owner is
 * about to spend their time.
 */
export default function SetupWizardPage() {
    const { company, timezones, progress, blueprints, existing, can } =
        usePage<SetupWizardPageProps>().props;
    const { auth } = usePage<{ auth: Auth }>().props;

    const wizard = useSetupWizard(progress);

    // The rail's company mark follows what is being typed on step one, so the
    // company starts feeling like theirs before anything is saved.
    const [logoPreview, setLogoPreview] = useState<string | null>(
        company.logo_url,
    );
    const [draftName, setDraftName] = useState('');

    const displayName = draftName.trim() || company.name;
    const current = wizard.isStep ? (wizard.view as SetupStep) : null;
    const meta = current ? STEP_META[current] : null;

    const stepProps = current
        ? {
              onSaved: wizard.goNext,
              onBack: wizard.goBack,
              onSkip: () => wizard.skip(current),
              skipping: wizard.working,
          }
        : null;

    return (
        <>
            <Head title="Company setup" />

            <ToastClearance />

            <div className="flex h-dvh overflow-hidden bg-background">
                <WizardRail
                    company={company}
                    logoPreview={logoPreview}
                    statuses={progress.steps}
                    can={can}
                    view={wizard.view}
                    counts={wizard.counts}
                    onSelect={wizard.setView}
                    email={auth.user.email}
                    onLeave={wizard.finish}
                    leaving={wizard.working}
                />

                <main className="flex min-w-0 flex-1 flex-col">
                    <MobileHeader
                        companyName={displayName}
                        percent={wizard.counts.percent}
                        position={wizard.position}
                        total={wizard.counts.total}
                        onLeave={wizard.finish}
                        leaving={wizard.working}
                    />

                    {meta && (
                        <header className="shrink-0 border-b border-sidebar-border/70 px-5 pt-6 pb-5 md:px-8 dark:border-sidebar-border">
                            <div className="mx-auto w-full max-w-3xl">
                                <p className="text-xs font-medium text-[#0a8b91] dark:text-[#0ABFBF]">
                                    Step {wizard.position} of{' '}
                                    {wizard.counts.total}
                                </p>
                                <h1 className="mt-1 text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                                    {meta.title}
                                </h1>
                                <p className="mt-1.5 max-w-xl text-sm leading-relaxed text-muted-foreground">
                                    {meta.purpose}
                                </p>
                            </div>
                        </header>
                    )}

                    {wizard.view === 'intro' && (
                        <IntroScreen
                            companyName={displayName}
                            firstName={auth.user.first_name}
                            can={can}
                            onStart={() => wizard.setView(STEPS[0].step)}
                            onLeave={wizard.finish}
                            leaving={wizard.working}
                        />
                    )}

                    {wizard.view === 'done' && (
                        <DoneScreen
                            companyName={displayName}
                            statuses={progress.steps}
                            onRevisit={wizard.setView}
                            onBack={wizard.goBack}
                            onFinish={wizard.finish}
                            finishing={wizard.working}
                            alreadyCompleted={progress.completed}
                        />
                    )}

                    {current && stepProps && !can[current] && (
                        <Restricted
                            label={STEP_META[current].label}
                            onBack={wizard.goBack}
                            onSkip={stepProps.onSkip}
                            skipping={wizard.working}
                        />
                    )}

                    {current && stepProps && can[current] && (
                        <>
                            {current === 'company' && (
                                <CompanyStep
                                    {...stepProps}
                                    company={company}
                                    timezones={timezones}
                                    savedBefore={
                                        progress.steps.company === 'done'
                                    }
                                    logoPreview={logoPreview}
                                    onLogoPreview={setLogoPreview}
                                    onNameChange={setDraftName}
                                />
                            )}
                            {current === 'departments' && (
                                <DepartmentsStep
                                    {...stepProps}
                                    blueprints={blueprints.departments}
                                    existing={existing.departments}
                                />
                            )}
                            {current === 'leave-types' && (
                                <LeaveTypesStep
                                    {...stepProps}
                                    blueprints={blueprints.leaveTypes}
                                    existing={existing.leaveTypes}
                                />
                            )}
                            {current === 'attendance' && (
                                <AttendanceStep
                                    {...stepProps}
                                    presets={blueprints.attendancePolicies}
                                    existingPolicies={
                                        existing.attendancePolicies
                                    }
                                    existingSchedules={existing.schedules}
                                />
                            )}
                            {current === 'recruitment' && (
                                <RecruitmentStep
                                    {...stepProps}
                                    blueprints={blueprints.pipelines}
                                    existing={existing.pipelines}
                                />
                            )}
                            {current === 'performance' && (
                                <PerformanceStep
                                    {...stepProps}
                                    blueprints={blueprints.frameworks}
                                    criteria={blueprints.criteria}
                                    instruments={blueprints.instruments}
                                    bands={blueprints.bands}
                                    tones={blueprints.tones}
                                    existing={existing.frameworks}
                                />
                            )}
                        </>
                    )}
                </main>
            </div>
        </>
    );
}

/**
 * The rail, compressed for phones: who you are setting up, how far along, and
 * the way out. The step ladder itself is left to the header above each step —
 * on a narrow screen there is only ever one step worth showing.
 */
function MobileHeader({
    companyName,
    percent,
    position,
    total,
    onLeave,
    leaving,
}: {
    companyName: string;
    percent: number;
    position: number;
    total: number;
    onLeave: () => void;
    leaving: boolean;
}) {
    return (
        <div className="shrink-0 bg-[#0F2044] px-5 py-3.5 text-white lg:hidden">
            <div className="flex items-center gap-2.5">
                <AppLogoIcon surface="dark" className="h-5 w-auto" />
                <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                    {companyName}
                </span>
                <button
                    type="button"
                    onClick={onLeave}
                    disabled={leaving}
                    className="shrink-0 text-[11px] font-medium text-white/50 transition-colors hover:text-white disabled:opacity-50"
                >
                    Later
                </button>
            </div>

            <div className="mt-2.5 flex items-center gap-3">
                <div className="h-1 flex-1 overflow-hidden rounded-full bg-white/10">
                    <div
                        className="h-full rounded-full bg-[#0ABFBF] transition-[width] duration-500 ease-out"
                        style={{ width: `${percent}%` }}
                    />
                </div>
                <span className="shrink-0 text-[11px] text-white/45 tabular-nums">
                    {position > 0 ? `${position} / ${total}` : `${percent}%`}
                </span>
            </div>
        </div>
    );
}

/**
 * A step whose module the signed-in person may not configure. Shown rather than
 * hidden, so they can see what is outstanding and hand it to somebody who can —
 * and can still move past it themselves.
 */
function Restricted({
    label,
    onBack,
    onSkip,
    skipping,
}: {
    label: string;
    onBack: () => void;
    onSkip: () => void;
    skipping: boolean;
}) {
    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-8 md:px-8">
                <div className="mx-auto flex w-full max-w-3xl items-start gap-3.5 rounded-xl border border-sidebar-border/70 bg-muted/40 p-5 dark:border-sidebar-border">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-background text-muted-foreground">
                        <Lock className="size-4" />
                    </span>
                    <div>
                        <h2 className="text-sm font-semibold text-foreground">
                            {label} needs a permission your role doesn't have
                        </h2>
                        <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                            Ask an HR Manager to configure it, or move on — the
                            rest of setup works without it, and this step stays
                            here for whoever can do it.
                        </p>
                    </div>
                </div>
            </div>

            <StepFooter
                onBack={onBack}
                onSkip={onSkip}
                processing={false}
                skipping={skipping}
                skipOnly
            />
        </div>
    );
}
