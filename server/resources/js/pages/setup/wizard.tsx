import { Head, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { DevicesManager } from '@/features/devices/components/devices-manager';
import { LocationsManager } from '@/features/locations/components/locations-manager';
import { OffboardingProgramsManager } from '@/features/offboarding/components/programs-manager';
import { OnboardingProgramsManager } from '@/features/onboarding/components/programs-manager';
import { CATEGORY_META } from '@/features/onboarding/constants';
import type { TaskCategory } from '@/features/onboarding/types';
import { RosterManager } from '@/features/roster/components/roster-manager';
import AttendanceStep from '@/features/setup-wizard/components/attendance-step';
import AwardsStep from '@/features/setup-wizard/components/awards-step';
import ChecklistStep from '@/features/setup-wizard/components/checklist-step';
import CompanyStep from '@/features/setup-wizard/components/company-step';
import DepartmentsStep from '@/features/setup-wizard/components/departments-step';
import DoneScreen from '@/features/setup-wizard/components/done-screen';
import EditorStep from '@/features/setup-wizard/components/editor-step';
import IntroScreen from '@/features/setup-wizard/components/intro-screen';
import LeaveTypesStep from '@/features/setup-wizard/components/leave-types-step';
import PerformanceStep from '@/features/setup-wizard/components/performance-step';
import RecruitmentStep from '@/features/setup-wizard/components/recruitment-step';
import ScheduleStep from '@/features/setup-wizard/components/schedule-step';
import SectionHeading from '@/features/setup-wizard/components/section-heading';
import StepFooter from '@/features/setup-wizard/components/step-footer';
import ToastClearance from '@/features/setup-wizard/components/toast-clearance';
import WizardRail from '@/features/setup-wizard/components/wizard-rail';
import {
    GROUP_LABEL,
    STEP_META,
    STEPS,
} from '@/features/setup-wizard/constants';
import { setupWizardRoutes } from '@/features/setup-wizard/routes';
import type {
    SetupStep,
    SetupWizardPageProps,
    StepControls,
    StepScreens,
} from '@/features/setup-wizard/types';
import { useSetupWizard } from '@/features/setup-wizard/use-setup-wizard';
import type { Auth } from '@/types';

/**
 * Company setup — the guided walk-through a brand-new company is taken through
 * before its dashboard.
 *
 * Registration provisions an empty tenant, so the owner's first sign-in used to
 * land on a dashboard of zeroes with every Company Setup screen behind it and
 * nothing saying which mattered, or in what order. This walks all of them, one
 * step per screen, every one skippable. Each step carries its Company Setup
 * screen whole — the same props, the same editors, posting to the same routes —
 * and adds a place to start where there is a sensible one: the wizard is a route
 * through Company Setup, not a parallel copy of it.
 *
 * It brings its own chrome rather than the app shell: the deep-navy rail is the
 * same field the sign-in screens and the workspace picker use, because setup is
 * the last stretch of the road that starts at registration. The working pane
 * beside it is already the app's own light surface — which is where the owner is
 * about to spend their time.
 */
export default function SetupWizardPage() {
    const {
        view,
        company,
        progress,
        configured,
        screen,
        blueprints,
        existing,
        can,
        canInvite,
    } = usePage<SetupWizardPageProps>().props;
    const { auth } = usePage<{ auth: Auth }>().props;

    const wizard = useSetupWizard(view, progress);

    // The rail's company mark follows what is being typed on step one, so the
    // company starts feeling like theirs before anything is saved.
    const [logoPreview, setLogoPreview] = useState<string | null>(
        company.logo_url,
    );
    const [draftName, setDraftName] = useState('');

    const displayName = draftName.trim() || company.name;
    const current = wizard.isStep ? (view as SetupStep) : null;
    const meta = current ? STEP_META[current] : null;

    const controls: StepControls | null = current
        ? {
              configured: configured[current],
              onContinue: () => wizard.advance(current),
              onNext: wizard.goNext,
              onBack: wizard.goBack,
              onSkip: () => wizard.skip(current),
              busy: wizard.working,
              skipping: wizard.busy === 'skip',
              advancing: wizard.busy === 'advance',
          }
        : null;

    /** The step's screen, typed as the step it belongs to. */
    const screenFor = <S extends SetupStep>() => screen as StepScreens[S];

    return (
        <>
            <Head
                title={meta ? `${meta.label} · Company setup` : 'Company setup'}
            />

            <ToastClearance />

            <div className="flex h-dvh overflow-hidden bg-background">
                <WizardRail
                    company={company}
                    logoPreview={logoPreview}
                    statuses={progress.steps}
                    can={can}
                    view={view}
                    counts={wizard.counts}
                    onSelect={wizard.visit}
                    email={auth.user.email}
                    onLeave={() => wizard.finish()}
                    leaving={wizard.busy === 'finish'}
                />

                <main className="flex min-w-0 flex-1 flex-col">
                    <MobileHeader
                        companyName={displayName}
                        percent={wizard.counts.percent}
                        position={wizard.position}
                        total={wizard.counts.total}
                        onLeave={() => wizard.finish()}
                        leaving={wizard.busy === 'finish'}
                    />

                    {meta && (
                        <header className="shrink-0 border-b border-sidebar-border/70 px-5 pt-6 pb-5 md:px-8 dark:border-sidebar-border">
                            <div className="mx-auto w-full max-w-4xl">
                                <p className="text-xs font-medium text-[#0a8b91] dark:text-[#0ABFBF]">
                                    Step {wizard.position} of{' '}
                                    {wizard.counts.total}
                                    <span className="text-muted-foreground">
                                        {' · '}
                                        {GROUP_LABEL[meta.group]}
                                    </span>
                                </p>
                                <h1 className="mt-1 text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
                                    {meta.title}
                                </h1>
                                <p className="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                                    {meta.purpose}
                                </p>
                            </div>
                        </header>
                    )}

                    {view === 'intro' && (
                        <IntroScreen
                            companyName={displayName}
                            firstName={auth.user.first_name}
                            can={can}
                            onStart={() => wizard.visit(STEPS[0].step)}
                            onLeave={() => wizard.finish()}
                            leaving={wizard.busy === 'finish'}
                        />
                    )}

                    {view === 'done' && (
                        <DoneScreen
                            companyName={displayName}
                            statuses={progress.steps}
                            onRevisit={wizard.visit}
                            onBack={wizard.goBack}
                            onFinish={wizard.finish}
                            finishing={wizard.busy === 'finish'}
                            alreadyCompleted={progress.completed}
                            canInvite={canInvite}
                        />
                    )}

                    {current && controls && (!can[current] || !screen) && (
                        <Restricted
                            label={STEP_META[current].label}
                            onBack={controls.onBack}
                            onSkip={controls.onSkip}
                            busy={controls.busy}
                            skipping={controls.skipping}
                        />
                    )}

                    {/*
                     * Keyed by step: two steps can share a component (the
                     * editor-only ones do), and moving between them must start
                     * the next one fresh rather than carry the last one's state.
                     */}
                    {current && controls && can[current] && screen && (
                        <StepView
                            key={current}
                            step={current}
                            controls={controls}
                            screenFor={screenFor}
                            company={company}
                            blueprints={blueprints}
                            existing={existing}
                            savedBefore={progress.steps.company === 'done'}
                            logoPreview={logoPreview}
                            onLogoPreview={setLogoPreview}
                            onNameChange={setDraftName}
                        />
                    )}
                </main>
            </div>
        </>
    );
}

/** One step, with the part of the page it needs. */
function StepView({
    step,
    controls,
    screenFor,
    company,
    blueprints,
    existing,
    savedBefore,
    logoPreview,
    onLogoPreview,
    onNameChange,
}: {
    step: SetupStep;
    controls: StepControls;
    screenFor: <S extends SetupStep>() => StepScreens[S];
    company: SetupWizardPageProps['company'];
    blueprints: SetupWizardPageProps['blueprints'];
    existing: SetupWizardPageProps['existing'];
    savedBefore: boolean;
    logoPreview: string | null;
    onLogoPreview: (url: string | null) => void;
    onNameChange: (name: string) => void;
}) {
    switch (step) {
        case 'company':
            return (
                <CompanyStep
                    {...controls}
                    company={company}
                    screen={screenFor<'company'>()}
                    savedBefore={savedBefore}
                    logoPreview={logoPreview}
                    onLogoPreview={onLogoPreview}
                    onNameChange={onNameChange}
                />
            );

        case 'departments':
            return (
                <DepartmentsStep
                    {...controls}
                    screen={screenFor<'departments'>()}
                    blueprints={blueprints.departments}
                    existing={existing.departments}
                />
            );

        case 'attendance':
            return (
                <AttendanceStep
                    {...controls}
                    screen={screenFor<'attendance'>()}
                    presets={blueprints.attendancePolicies}
                    existingPolicies={existing.attendancePolicies}
                    existingSchedules={existing.schedules}
                />
            );

        case 'schedule':
            return (
                <ScheduleStep
                    {...controls}
                    screen={screenFor<'schedule'>()}
                    blueprints={blueprints.holidays}
                    existing={existing.holidays}
                />
            );

        case 'leave-types':
            return (
                <LeaveTypesStep
                    {...controls}
                    screen={screenFor<'leave-types'>()}
                    blueprints={blueprints.leaveTypes}
                    existing={existing.leaveTypes}
                />
            );

        case 'locations':
            return (
                <EditorStep
                    {...controls}
                    emptyNote="Draw your first site, or skip — punches aren’t checked against a place until you do."
                >
                    <LocationsManager
                        {...screenFor<'locations'>()}
                        heading={
                            <SectionHeading
                                title="Your sites"
                                hint="Draw each office, plant or store as a fence on the map. Whether a punch from outside one is flagged or refused is each attendance policy’s call; people and a default schedule and policy can be set per site."
                            />
                        }
                    />
                </EditorStep>
            );

        case 'devices':
            return (
                <EditorStep
                    {...controls}
                    emptyNote="Most companies need none — skip unless you have a scanner or want a kiosk."
                >
                    <DevicesManager
                        {...screenFor<'devices'>()}
                        heading={
                            <SectionHeading
                                title="Your devices"
                                hint="Register a biometric scanner to take its punches, or turn a tablet at the door into a kiosk. Each device gets a key, shown once; a scanner that cannot send can be fed from its CSV export."
                            />
                        }
                    />
                </EditorStep>
            );

        case 'roster':
            return (
                <EditorStep
                    {...controls}
                    emptyNote="Set a default schedule first, or skip — the roster fills in as people join."
                >
                    <RosterManager
                        {...screenFor<'roster'>()}
                        reload={{
                            url: setupWizardRoutes.view('roster'),
                            only: ['screen'],
                        }}
                        heading={
                            <SectionHeading
                                title="The week ahead"
                                hint="Everyone works the company default schedule unless their department, site or own assignment says otherwise. Put people on a schedule from a date, or change one day’s shift by clicking it."
                            />
                        }
                    />
                </EditorStep>
            );

        case 'recruitment':
            return (
                <RecruitmentStep
                    {...controls}
                    screen={screenFor<'recruitment'>()}
                    blueprints={blueprints.pipelines}
                />
            );

        case 'onboarding':
            return (
                <ChecklistStep
                    {...controls}
                    offers={blueprints.onboardingPrograms.map((program) => ({
                        key: program.key,
                        name: program.name,
                        description: program.description,
                        lines: program.tasks.map((task) => ({
                            label: task.title,
                            meta: `${CATEGORY_META[task.category as TaskCategory]?.label ?? task.category} · due day ${task.due_offset_days}`,
                        })),
                    }))}
                    existing={existing.onboardingPrograms}
                    action={setupWizardRoutes.onboarding}
                    noun="checklist"
                    description="The checklist each new hire is given the moment they are hired — paperwork, equipment, access, orientation and the rest, each due a number of days after they start."
                    footnote="The first checklist is every new hire’s default. Change any task, add a checklist for one department or employment type, or pick which is the default in the editor below."
                >
                    <OnboardingProgramsManager
                        {...screenFor<'onboarding'>()}
                        heading={
                            <SectionHeading
                                title="Your onboarding checklists"
                                hint="Edit a checklist’s tasks and due days, aim one at a department or employment type, or change which one is the default."
                            />
                        }
                    />
                </ChecklistStep>
            );

        case 'performance':
            return (
                <PerformanceStep
                    {...controls}
                    screen={screenFor<'performance'>()}
                    blueprints={blueprints.frameworks}
                    criteria={blueprints.criteria}
                    instruments={blueprints.instruments}
                    bands={blueprints.bands}
                    tones={blueprints.tones}
                />
            );

        case 'awards':
            return (
                <AwardsStep
                    {...controls}
                    screen={screenFor<'awards'>()}
                    blueprints={blueprints.awardTypes}
                    existing={existing.awardTypes}
                />
            );

        case 'offboarding':
            return (
                <ChecklistStep
                    {...controls}
                    offers={blueprints.offboardingPrograms.map((program) => ({
                        key: program.key,
                        name: program.name,
                        description: program.description,
                        lines: program.items.map((item) => ({
                            label: item.item,
                            meta:
                                CLEARED_BY[item.department] ?? item.department,
                        })),
                    }))}
                    existing={existing.offboardingPrograms}
                    action={setupWizardRoutes.offboarding}
                    noun="clearance"
                    description="The clearance every exit runs through, each item signed off by the department that owns it — the leaver’s own, IT, Finance or HR."
                    footnote="Items are routed to your departments coded IT, FIN and HR; one with no such department stays unrouted until you pick one in the editor below."
                >
                    <OffboardingProgramsManager
                        {...screenFor<'offboarding'>()}
                        heading={
                            <SectionHeading
                                title="Your exit clearances"
                                hint="Edit a clearance’s items and who signs each off, aim one at an exit type or department, or change which one is the default."
                            />
                        }
                    />
                </ChecklistStep>
            );
    }
}

/** Who signs off a clearance item, by the department code it is routed to. */
const CLEARED_BY: Record<string, string> = {
    __own__: 'Their own department',
    IT: 'Cleared by IT',
    FIN: 'Cleared by Finance',
    HR: 'Cleared by HR',
};

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
    busy,
    skipping,
}: {
    label: string;
    onBack: () => void;
    onSkip: () => void;
    busy: boolean;
    skipping: boolean;
}) {
    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="min-h-0 flex-1 overflow-y-auto px-5 py-8 md:px-8">
                <div className="mx-auto flex w-full max-w-4xl items-start gap-3.5 rounded-xl border border-sidebar-border/70 bg-muted/40 p-5 dark:border-sidebar-border">
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
                busy={busy}
                skipping={skipping}
            />
        </div>
    );
}
