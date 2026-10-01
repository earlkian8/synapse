<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireCompanySetup;
use App\Http\Requests\Setup\UpdateCompanyProfileRequest;
use App\Http\Requests\Setup\Wizard\WizardAttendanceRequest;
use App\Http\Requests\Setup\Wizard\WizardAwardTypesRequest;
use App\Http\Requests\Setup\Wizard\WizardContinueRequest;
use App\Http\Requests\Setup\Wizard\WizardDepartmentsRequest;
use App\Http\Requests\Setup\Wizard\WizardHolidaysRequest;
use App\Http\Requests\Setup\Wizard\WizardLeaveTypesRequest;
use App\Http\Requests\Setup\Wizard\WizardPerformanceRequest;
use App\Http\Requests\Setup\Wizard\WizardProgramRequest;
use App\Http\Requests\Setup\Wizard\WizardRecruitmentRequest;
use App\Http\Requests\Setup\Wizard\WizardSkipRequest;
use App\Http\Resources\CompanyProfileResource;
use App\Models\AttendancePolicy;
use App\Models\AwardType;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\LeaveType;
use App\Models\OffboardingProgram;
use App\Models\OnboardingProgram;
use App\Models\Organization;
use App\Models\RecruitmentPipeline;
use App\Models\ReviewTemplate;
use App\Models\WorkSchedule;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\OrganizationClock;
use App\Support\Performance\RatingModel;
use App\Support\Setup\CompanyProfileWriter;
use App\Support\Setup\CompanySetup;
use App\Support\Setup\SetupBlueprints;
use App\Support\Setup\SetupDefinition;
use App\Support\Setup\SetupInstaller;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guided setup a brand-new company is taken through before its dashboard.
 *
 * Registration provisions an empty tenant (ADR 0005), and the modules that read
 * configuration ship no defaults on purpose — so the owner's first sign-in used
 * to land on a dashboard of zeroes with every Company Setup screen behind it and
 * nothing saying which mattered, or in what order. This walks all of them, one
 * step per screen ({@see CompanySetup::STEPS}): the company and its shape, how
 * its time is kept, and a person's life at the company from hire to exit.
 *
 * Each step carries its Company Setup screen whole — the same props
 * ({@see CompanySetup::SCREENS}) and the same editors, posting to the same
 * routes — so anything a company can do under Company Setup it can do here.
 * What a step adds is a place to start: the suggestions in
 * {@see SetupBlueprints}, adopted through this controller's own actions.
 *
 * Four rules hold across every step:
 *
 *  - **Every step can be skipped**, and a skip is recorded rather than forgotten,
 *    so the wizard resumes past it instead of asking twice.
 *  - **Nothing here is a hidden default.** A step writes only what the owner
 *    settled on — one of the offers in {@see SetupBlueprints}, or the company's
 *    own; leaving the wizard without touching it leaves the company exactly as
 *    empty as it was.
 *  - **A company is never held to the offers.** Every step also accepts
 *    definitions the company wrote — its own departments, kinds of leave, hiring
 *    stages, appraisal sections and criteria. Adopted and bespoke answers meet
 *    in {@see SetupDefinition} and are written by the same installer, so what
 *    the wizard builds is a real, editable module configuration either way.
 *  - **Steps configure real modules**, so each is gated by that module's own
 *    permission ({@see CompanySetup::ABILITIES}) — the wizard is a route through
 *    Company Setup, not a way around it. A step the signed-in user may not do is
 *    shown as unavailable rather than hidden, so they know what is outstanding.
 *
 * {@see RequireCompanySetup} is what brings an owner here; finishing (or skipping
 * outright) is what lets them past it.
 */
class SetupWizardController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * The wizard itself, opened on one view — the welcome, a step, or the
     * send-off. With none named it opens where the company left off.
     *
     * The view lives in the URL (`/setup/wizard/{view}`) rather than in the
     * client, so the Company Setup editors a step carries can save the way they
     * do on their own screens — post, then `back()` — and land on the same step
     * with its list refreshed. A step carries its Company Setup screen's own
     * props ({@see CompanySetup::SCREENS}); only the step on show is read.
     */
    public function show(Request $request, ?string $view = null): Response
    {
        $organization = $this->organization();
        $user = $request->user();
        $view ??= CompanySetup::initialView($organization);

        $can = collect(CompanySetup::ABILITIES)
            ->map(fn (string $ability): bool => $user->can($ability))
            ->all();

        $screen = isset(CompanySetup::SCREENS[$view]) && $can[$view]
            ? app(CompanySetup::SCREENS[$view])->toArray($request)
            : null;

        return Inertia::render('setup/wizard', [
            'view' => $view,
            'company' => (new CompanyProfileResource($organization))->resolve($request),

            'progress' => [
                'steps' => CompanySetup::statuses($organization),
                'resume' => CompanySetup::resumeStep($organization),
                'completed' => $organization->hasFinishedSetup(),
            ],

            // Whether each step's module already holds something, so a step
            // configured by hand can be moved on from rather than only skipped.
            'configured' => CompanySetup::configured($organization),

            // The step on show, exactly as its Company Setup screen reads.
            'screen' => $screen,

            // What each step can offer to start from. Closures, like `existing`,
            // so a step reloading only its screen (the roster's week) skips them.
            'blueprints' => fn (): array => [
                'departments' => SetupBlueprints::departments(),
                'leaveTypes' => SetupBlueprints::leaveTypes(),
                // How a day is judged (ADR 0038): each preset with its complete
                // settings, so customising one starts from every field filled.
                'attendancePolicies' => AttendancePolicyPresets::forClient(),
                'holidays' => SetupBlueprints::holidays(OrganizationClock::now()),
                'pipelines' => SetupBlueprints::pipelines(),
                'onboardingPrograms' => SetupBlueprints::onboardingPrograms(),
                'frameworks' => $this->frameworkBlueprints(),
                'awardTypes' => SetupBlueprints::awardTypes(),
                'offboardingPrograms' => SetupBlueprints::offboardingPrograms(),

                // What a company designing its own framework draws on: the
                // criteria catalogue as a list it can pick from, the instruments
                // it can measure on, and the ladder a result is reported in
                // unless it writes its own.
                'criteria' => $this->criteriaCatalogue(),
                'instruments' => SetupBlueprints::instruments(),
                'bands' => RatingModel::defaultBands(),
                'tones' => RatingModel::TONES,
            ],

            // What the company already has by name, so an offer it already took
            // reads "Already added" rather than being offered twice.
            'existing' => fn (): array => [
                'departments' => Department::query()->orderBy('name')->pluck('name')->all(),
                'leaveTypes' => LeaveType::query()->orderBy('name')->pluck('name')->all(),
                'attendancePolicies' => AttendancePolicy::query()->orderBy('name')->pluck('name')->all(),
                'schedules' => WorkSchedule::query()->orderBy('name')->pluck('name')->all(),
                'holidays' => Holiday::query()->orderBy('name')->pluck('name')->all(),
                'pipelines' => RecruitmentPipeline::query()->orderBy('name')->pluck('name')->all(),
                'onboardingPrograms' => OnboardingProgram::query()->orderBy('name')->pluck('name')->all(),
                'frameworks' => ReviewTemplate::query()->orderBy('name')->pluck('name')->all(),
                'awardTypes' => AwardType::query()->orderBy('name')->pluck('name')->all(),
                'offboardingPrograms' => OffboardingProgram::query()->orderBy('name')->pluck('name')->all(),
            ],

            // Per step, because every step is a different module's permission.
            'can' => $can,

            // The send-off points at bringing people in, where that is allowed.
            'canInvite' => $user->can('employees.invite'),
        ]);
    }

    /**
     * Step 1 — the company's own identity, contact details and statutory numbers.
     * Same payload, same validation and same writer as the Company Profile screen.
     */
    public function company(UpdateCompanyProfileRequest $request): RedirectResponse
    {
        $organization = $this->organization();

        CompanyProfileWriter::apply($organization, $request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: 'Set up the company profile',
            subject: $organization,
            logName: 'company-setup',
            subjectLabel: $organization->name,
        );

        return $this->completed(CompanySetup::COMPANY, 'Company profile saved.');
    }

    /**
     * Step 2 — the org structure: the suggested departments that were ticked,
     * plus the ones the company described itself.
     */
    public function departments(WizardDepartmentsRequest $request): RedirectResponse
    {
        $created = SetupInstaller::departments(SetupDefinition::departments(
            $request->validated('codes'),
            $request->validated('custom'),
        ));

        ActivityLogger::log(
            event: 'created',
            description: "Set up {$created} ".str('department')->plural($created).' during company setup',
            logName: 'company-setup',
        );

        return $this->completed(
            CompanySetup::DEPARTMENTS,
            $created.' '.str('department')->plural($created).' added.',
        );
    }

    /**
     * Step 3 — the kinds of leave the company grants, with the entitlement and
     * policy each carries.
     */
    public function leaveTypes(WizardLeaveTypesRequest $request): RedirectResponse
    {
        $created = SetupInstaller::leaveTypes(SetupDefinition::leaveTypes(
            $request->validated('codes'),
            $request->days(),
            $request->validated('custom'),
        ));

        ActivityLogger::log(
            event: 'created',
            description: "Set up {$created} leave ".str('type')->plural($created).' during company setup',
            logName: 'company-setup',
        );

        return $this->completed(
            CompanySetup::LEAVE_TYPES,
            $created.' leave '.str('type')->plural($created).' added.',
        );
    }

    /**
     * Step 4 — how the company's attendance days are judged (ADR 0038): a preset,
     * adopted or adjusted, which becomes the company default if it has none — and,
     * when asked for, the schedule most people work, which becomes the default
     * hours the same way.
     */
    public function attendance(WizardAttendanceRequest $request): RedirectResponse
    {
        $definition = SetupDefinition::attendance($request->validated());

        abort_if($definition === null, 422);

        ['policy' => $policy, 'schedule' => $schedule] = SetupInstaller::attendance($definition);

        ActivityLogger::log(
            event: 'created',
            description: "Set up attendance policy \"{$policy->name}\"".($schedule !== null ? " and schedule \"{$schedule->name}\"" : '').' during company setup',
            subject: $policy,
            logName: 'company-setup',
            subjectLabel: $policy->name,
        );

        return $this->completed(
            CompanySetup::ATTENDANCE,
            "Days are judged by \"{$policy->name}\"".($schedule !== null ? " on \"{$schedule->name}\"." : '.'),
        );
    }

    /**
     * Step 5 — the hiring process job postings will run on: one of the shapes on
     * offer, or the stages the company drew for itself.
     */
    public function recruitment(WizardRecruitmentRequest $request): RedirectResponse
    {
        $pipeline = SetupInstaller::pipeline(SetupDefinition::pipeline($request->validated()));

        ActivityLogger::log(
            event: 'created',
            description: "Created recruitment pipeline \"{$pipeline->name}\" during company setup",
            subject: $pipeline,
            logName: 'recruitment',
            subjectLabel: $pipeline->name,
        );

        return $this->completed(CompanySetup::RECRUITMENT, "\"{$pipeline->name}\" is ready to hire on.");
    }

    /**
     * Step 6 — the appraisal framework, together with the instruments and the
     * criteria catalogue it measures on. Adopted whole, or designed here section
     * by section; either way the framework editor reads it back unchanged.
     */
    public function performance(WizardPerformanceRequest $request): RedirectResponse
    {
        $template = SetupInstaller::framework(SetupDefinition::framework($request->validated()));

        ActivityLogger::log(
            event: 'created',
            description: "Created appraisal framework \"{$template->name}\" during company setup",
            subject: $template,
            logName: 'company-setup',
            subjectLabel: $template->name,
        );

        return $this->completed(CompanySetup::PERFORMANCE, "\"{$template->name}\" is ready to review against.");
    }

    /**
     * Schedules & Holidays — the holidays the company adopted from the
     * Philippine calendar. Its schedules are written by the schedule editor the
     * step carries, exactly as on Company Setup.
     */
    public function holidays(WizardHolidaysRequest $request): RedirectResponse
    {
        $created = SetupInstaller::holidays(SetupDefinition::holidays(
            $request->validated('keys'),
            OrganizationClock::now(),
        ));

        ActivityLogger::log(
            event: 'created',
            description: "Added {$created} ".str('holiday')->plural($created).' to the calendar during company setup',
            logName: 'company-setup',
        );

        return $this->completed(
            CompanySetup::SCHEDULE,
            $created.' '.str('holiday')->plural($created).' added to the calendar.',
        );
    }

    /**
     * Onboarding — the checklist every new hire starts with.
     */
    public function onboarding(WizardProgramRequest $request): RedirectResponse
    {
        $definition = SetupDefinition::onboardingProgram($request->validated());

        abort_if($definition === null, 422);

        $program = SetupInstaller::onboardingProgram($definition);

        ActivityLogger::log(
            event: 'created',
            description: "Created onboarding program \"{$program->name}\" during company setup",
            subject: $program,
            logName: 'onboarding',
            subjectLabel: $program->name,
        );

        return $this->completed(CompanySetup::ONBOARDING, "New hires will start with \"{$program->name}\".");
    }

    /**
     * Awards — the recognitions the company gives out.
     */
    public function awardTypes(WizardAwardTypesRequest $request): RedirectResponse
    {
        $created = SetupInstaller::awardTypes(SetupDefinition::awardTypes($request->validated('keys')));

        ActivityLogger::log(
            event: 'created',
            description: "Set up {$created} award ".str('type')->plural($created).' during company setup',
            logName: 'company-setup',
        );

        return $this->completed(
            CompanySetup::AWARDS,
            $created.' award '.str('type')->plural($created).' added.',
        );
    }

    /**
     * Offboarding — the clearance every exit runs through.
     */
    public function offboarding(WizardProgramRequest $request): RedirectResponse
    {
        $definition = SetupDefinition::offboardingProgram($request->validated());

        abort_if($definition === null, 422);

        $program = SetupInstaller::offboardingProgram($definition);

        ActivityLogger::log(
            event: 'created',
            description: "Created clearance template \"{$program->name}\" during company setup",
            subject: $program,
            logName: 'offboarding',
            subjectLabel: $program->name,
        );

        return $this->completed(CompanySetup::OFFBOARDING, "Exits will clear through \"{$program->name}\".");
    }

    /**
     * Move on from a step whose work was done with the Company Setup editors it
     * carries rather than its suggestions — recorded as done, because its module
     * now holds something (see {@see WizardContinueRequest}).
     */
    public function continue(WizardContinueRequest $request): RedirectResponse
    {
        CompanySetup::markStep($this->organization(), $request->validated('step'), CompanySetup::DONE);

        return back();
    }

    /**
     * Pass over one step. Recorded, so the wizard resumes past it — and so the
     * finish screen can say honestly what was left for later.
     */
    public function skip(WizardSkipRequest $request): RedirectResponse
    {
        CompanySetup::markStep($this->organization(), $request->validated('step'), CompanySetup::SKIPPED);

        return back();
    }

    /**
     * Close setup and go to the dashboard — or, from the send-off's "bring your
     * people in", to Employees → Access. Reached from the finish screen and from
     * "I'll do this later" on the way in — either way the company stops being
     * sent here, and every Company Setup screen stays exactly where it was.
     */
    public function finish(Request $request): RedirectResponse
    {
        $organization = $this->organization();

        if (! $organization->hasFinishedSetup()) {
            CompanySetup::complete($organization);

            ActivityLogger::log(
                event: 'updated',
                description: 'Finished company setup',
                subject: $organization,
                logName: 'company-setup',
                subjectLabel: $organization->name,
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$organization->name} is ready to go."]);

        // The send-off can hand straight over to bringing people in — the one
        // thing setup itself does not do — for somebody allowed to.
        $toPeople = $request->input('next') === 'people' && $request->user()->can('employees.invite');

        return redirect()->route($toPeople ? 'employees.access' : 'dashboard');
    }

    /**
     * Record a finished step, report it, and stay on the wizard so the client
     * decides what to show next.
     *
     * A step confirms itself the same way every other save in the app does — a
     * toast — and says what it actually created, which the screen it moves on to
     * no longer shows. The wizard lifts the toaster clear of its own footer
     * (see `setup/wizard.tsx`) so the confirmation never covers the button the
     * owner is about to press.
     */
    private function completed(string $step, string $message): RedirectResponse
    {
        CompanySetup::markStep($this->organization(), $step, CompanySetup::DONE);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * The appraisal blueprints with each line resolved to the criterion it
     * measures — the client shows a framework's actual contents rather than a
     * count, and the criteria catalogue stays defined in exactly one place.
     *
     * The criterion's own key rides along, so a company that opens a blueprint
     * up to change it starts from lines that are still catalogue-backed rather
     * than from copies of their wording.
     *
     * @return list<array<string, mixed>>
     */
    private function frameworkBlueprints(): array
    {
        $catalogue = SetupBlueprints::criteria();

        return array_map(function (array $framework) use ($catalogue): array {
            $framework['items'] = array_map(fn (array $item): array => [
                'criterion' => $item['criterion'],
                'section' => $item['section'],
                'weight' => $item['weight'],
                'name' => $catalogue[$item['criterion']]['name'],
                'description' => $catalogue[$item['criterion']]['description'],
                'scale' => $catalogue[$item['criterion']]['scale'],
            ], $framework['items']);

            return $framework;
        }, SetupBlueprints::frameworks());
    }

    /**
     * The criteria catalogue as a list the client can offer, each entry keyed by
     * what the server will resolve it back to. A company designing its own
     * framework picks from these — the same "a criterion is chosen, not typed"
     * rule the framework editor works by — and writes its own only where nothing
     * here says what it means to do the job well.
     *
     * @return list<array{key: string, name: string, description: string, weight: float, scale: string}>
     */
    private function criteriaCatalogue(): array
    {
        return array_values(array_map(
            fn (string $key, array $criterion): array => ['key' => $key] + $criterion,
            array_keys(SetupBlueprints::criteria()),
            SetupBlueprints::criteria(),
        ));
    }

    /**
     * The current tenant — the company being set up.
     */
    private function organization(): Organization
    {
        return $this->tenancy->organization() ?? request()->user()?->defaultOrganization() ?? abort(403);
    }
}
