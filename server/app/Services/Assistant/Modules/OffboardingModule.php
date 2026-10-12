<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Offboarding\FileResignationRequest;
use App\Http\Requests\Offboarding\StoreClearanceItemRequest;
use App\Http\Requests\Offboarding\UpdateOffboardingCaseRequest;
use App\Models\ClearanceItem;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\OffboardingCase;
use App\Models\OffboardingProgram;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Queries\OffboardingCasesIndexQuery;
use App\Queries\OffboardingStatistics;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Offboarding\ExitInterviewQuestionnaire;
use App\Support\Offboarding\ExitInterviews;
use App\Support\Offboarding\MyExit;
use App\Support\Offboarding\OffboardingException;
use App\Support\Offboarding\OffboardingWorkflow;
use App\Support\Offboarding\ResignationWorkflow;
use App\Support\OffboardingProvisioner;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Offboarding capability: who is leaving, how far their clearance has got, and
 * running the exit itself.
 *
 * **Reading** answers "who is leaving this month?", "what does IT still need to
 * sign off?", "how is Maria's clearance going?" from the board's own query
 * ({@see OffboardingCasesIndexQuery}) and statistics. **Doing** starts, edits,
 * completes, cancels, reopens and deletes exits and runs their checklists
 * through {@see OffboardingWorkflow}, the path the screens take.
 *
 * An exit is about as consequential as HR gets: completing one separates the
 * employee, and a started one tells the organisation somebody is leaving. So
 * starting, completing / cancelling / reopening, deleting, signing everything
 * off at once, and removing a checklist item all wait for the user's Confirm
 * (ADR 0049). Reading a named person's exit — its reason may be a termination's
 * — is audited as `viewed` (ADR 0027).
 *
 * **Resignations and exit interviews** (ADR 0076): HR lists the resignations
 * employees filed and accepts one (it becomes the exit, through
 * {@see ResignationWorkflow}) or declines it with a note; reads a leaver's exit
 * interview — audited as `viewed`, like the exit — and what leavers say
 * together; and sends or waives a survey. Deciding and waiving wait for Confirm.
 *
 * **The user's own exit** (`offboarding.resign`): where their resignation and
 * clearance stand ({@see MyExit}, what My resignation shows — HR's reason for
 * the exit left out), filing a resignation and withdrawing it, both confirmed.
 *
 * Disclosure follows the screens: `offboarding.view` to read anybody's exit,
 * `offboarding.manage` to change one, `offboarding.resign` for one's own.
 * An exit is addressed by its employee — there is one per person — and an item
 * by its label on that exit's checklist, resolved to exactly one or not at all.
 */
class OffboardingModule extends Module implements ContributesContext, ContributesTopicContext
{
    /** How many results a list returns. */
    private const MAX_RESULTS = 12;

    /** How many checklist items a read-out spells out. */
    private const MAX_ITEMS = 25;

    /** The clearance scopes a bulk sign-off can take. */
    private const SCOPES = ['all', 'department', 'unassigned'];

    /** The periods exit-interview insights read, in months (0 = all time). */
    private const PERIODS = [3, 6, 12, 0];

    public function __construct(
        private readonly OffboardingWorkflow $workflow,
        private readonly OffboardingCasesIndexQuery $board,
        private readonly OffboardingStatistics $statistics,
        private readonly ResignationWorkflow $resignations,
        private readonly ExitInterviews $interviews,
    ) {}

    public function key(): string
    {
        return 'offboarding';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('offboarding.view') || $user->can('offboarding.resign');
    }

    protected function toolMap(): array
    {
        return [
            'find_offboarding_cases' => 'findCases',
            'get_offboarding_case' => 'getCase',
            'find_clearance_items' => 'findItems',
            'offboarding_summary' => 'summary',
            'start_offboarding' => 'start',
            'update_offboarding_case' => 'updateCase',
            'set_offboarding_status' => 'transition',
            'delete_offboarding_case' => 'deleteCase',
            'add_clearance_item' => 'addItem',
            'update_clearance_item' => 'updateItem',
            'set_clearance_status' => 'setItemStatus',
            'remove_clearance_item' => 'removeItem',
            'clear_pending_clearance' => 'clearPending',
            'apply_clearance_template' => 'applyTemplate',
            'find_resignations' => 'findResignations',
            'decide_resignation' => 'decideResignation',
            'get_exit_interview' => 'getInterview',
            'exit_interview_summary' => 'interviewSummary',
            'send_exit_interview' => 'sendInterview',
            'waive_exit_interview' => 'waiveInterview',
            'get_my_exit' => 'myExit',
            'file_my_resignation' => 'fileMine',
            'withdraw_my_resignation' => 'withdrawMine',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_offboarding_cases' => 'offboarding.view',
            'get_offboarding_case' => 'offboarding.view',
            'find_clearance_items' => 'offboarding.view',
            'offboarding_summary' => 'offboarding.view',
            'start_offboarding' => 'offboarding.manage',
            'update_offboarding_case' => 'offboarding.manage',
            'set_offboarding_status' => 'offboarding.manage',
            'delete_offboarding_case' => 'offboarding.manage',
            'add_clearance_item' => 'offboarding.manage',
            'update_clearance_item' => 'offboarding.manage',
            'set_clearance_status' => 'offboarding.manage',
            'remove_clearance_item' => 'offboarding.manage',
            'clear_pending_clearance' => 'offboarding.manage',
            'apply_clearance_template' => 'offboarding.manage',
            'find_resignations' => 'offboarding.view',
            'decide_resignation' => 'offboarding.manage',
            'get_exit_interview' => 'offboarding.view',
            'exit_interview_summary' => 'offboarding.view',
            'send_exit_interview' => 'offboarding.manage',
            'waive_exit_interview' => 'offboarding.manage',
            'get_my_exit' => 'offboarding.resign',
            'file_my_resignation' => 'offboarding.resign',
            'withdraw_my_resignation' => 'offboarding.resign',
        ];
    }

    protected function confirmTools(): array
    {
        // Starting an exit announces that somebody is leaving; completing one
        // separates them; cancelling, reopening or deleting undoes one; a bulk
        // sign-off attests to many items in the user's name. Accepting a
        // resignation starts an exit; declining one answers a person; waiving a
        // survey closes it for good. Filing one's own resignation — or taking it
        // back — is the most personal thing this module does.
        return [
            'start_offboarding',
            'set_offboarding_status',
            'delete_offboarding_case',
            'remove_clearance_item',
            'clear_pending_clearance',
            'decide_resignation',
            'waive_exit_interview',
            'file_my_resignation',
            'withdraw_my_resignation',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'offboarding.view';

        if ($user->cannot($permission)) {
            return $this->denied(match ($permission) {
                'offboarding.manage' => 'change offboarding',
                'offboarding.resign' => 'file a resignation yourself',
                default => 'view offboarding',
            });
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $view = $this->allows($user, 'offboarding.view')
            ? <<<'TXT'

            - find_offboarding_cases lists exits (in progress by default); get_offboarding_case reads one person's exit and checklist; find_clearance_items lists sign-offs across exits (what is pending or flagged, by department); offboarding_summary reads the whole picture.
            - find_resignations lists resignations employees filed themselves (waiting for a decision by default). get_exit_interview reads one leaver's exit-interview answers; exit_interview_summary adds every answered survey up — why people leave, how they rate their time here, whether they would recommend the company — over the last 3, 6 or 12 months (0 = all time).
            - The clearance form and the Certificate of Employment are PDFs downloaded from the exit's page (Documents); the certificate once the exit is completed. Point to the page; they are not made in chat.
            TXT
            : '';

        $manage = '';

        if ($this->allows($user, 'offboarding.manage')) {
            $templates = $this->catalog(OffboardingProgram::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name'));
            $types = implode(', ', OffboardingCase::TYPES);

            $manage = <<<TXT

            - update_offboarding_case changes an exit's type, notice date, last working day or reason; add_clearance_item / update_clearance_item edit the checklist; set_clearance_status signs an item off (cleared), flags it with the reason in remarks (flagged), or resets it (pending); apply_clearance_template adds a template's missing items. send_exit_interview sends the survey for an exit that has none (a termination, or one started before surveys).
            - start_offboarding (types: {$types}), set_offboarding_status (complete separates the employee; cancel and reopen return them to active), delete_offboarding_case, remove_clearance_item and clear_pending_clearance (signs off every pending item at once — flagged ones are left) wait for the user's confirmation. Only start an exit on a clear, explicit request.
            - decide_resignation accepts a waiting resignation — it becomes the exit, with the last working day agreed (the proposed one unless another is given) and an optional clearance template — or declines it with a note the employee reads. waive_exit_interview stops waiting for a survey. Both wait for the user's confirmation.
              Clearance templates: {$templates}
            TXT;
        }

        $resign = $this->allows($user, 'offboarding.resign')
            ? <<<'TXT'

            - For the user's OWN exit: get_my_exit reads where their resignation, clearance and exit interview stand; file_my_resignation files a resignation with the last day they propose (30 days' notice is what the Labor Code asks; shorter is HR's to agree to) and an optional reason; withdraw_my_resignation takes a waiting one back. Both wait for the user's confirmation, and only on the user's clear, explicit request about themselves. The exit interview is answered on the My resignation page or in the app, not in chat.
            TXT
            : '';

        return <<<TXT
        OFFBOARDING — employees leaving: one exit per person (initiated → clearance → completed, or cancelled) with a clearance checklist signed off by the responsible departments. An employee may file their own resignation (pending → accepted, declined or withdrawn); accepting it starts their exit. Every exit but a termination sends the leaver an exit-interview survey.
        - An exit is identified by the employee (name or employee number); a checklist item by (part of) its label. Dates are YYYY-MM-DD.{$view}{$manage}{$resign}
        TXT;
    }

    public function tools(User $user): array
    {
        $employee = ['type' => 'STRING', 'description' => 'The departing employee: name or employee number.'];
        $item = ['type' => 'STRING', 'description' => 'The checklist item, by (part of) its label.'];
        $date = fn (string $what): array => ['type' => 'STRING', 'description' => "{$what}, YYYY-MM-DD."];

        return $this->permitted($user, [
            [
                'name' => 'find_offboarding_cases',
                'description' => 'List exits, optionally by status (in progress by default), exit type, department or employee.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'status' => ['type' => 'STRING', 'enum' => OffboardingCasesIndexQuery::STATUSES, 'description' => '"active" is initiated or in clearance.'],
                        'exit_type' => ['type' => 'STRING', 'enum' => OffboardingCase::TYPES],
                        'department' => ['type' => 'STRING', 'description' => 'Department name.'],
                        'employee' => $employee,
                    ],
                ],
            ],
            [
                'name' => 'get_offboarding_case',
                'description' => "Read one person's exit: type, status, dates, reason, and every clearance item with who signed it off or why it is flagged.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'find_clearance_items',
                'description' => 'List clearance items across exits in progress — by status (pending by default), department or employee.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'status' => ['type' => 'STRING', 'enum' => ClearanceItem::STATUSES],
                        'department' => ['type' => 'STRING', 'description' => 'The department that signs it off.'],
                        'employee' => $employee,
                    ],
                ],
            ],
            [
                'name' => 'offboarding_summary',
                'description' => 'The offboarding picture: exits in progress, who leaves soon, flagged clearance items, and completions this month.',
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'start_offboarding',
                'description' => 'Start an exit for an employee and seed their clearance checklist (from a template, the best match, or the standard list).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'exit_type' => ['type' => 'STRING', 'enum' => OffboardingCase::TYPES],
                        'notice_date' => $date('When notice was given'),
                        'last_working_day' => $date('Their last working day'),
                        'reason' => ['type' => 'STRING', 'description' => 'Why they are leaving.'],
                        'template' => ['type' => 'STRING', 'description' => 'Clearance template name; defaults to the best match.'],
                    ],
                    'required' => ['employee', 'exit_type'],
                ],
            ],
            [
                'name' => 'update_offboarding_case',
                'description' => "Change an exit's type, notice date, last working day or reason. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'exit_type' => ['type' => 'STRING', 'enum' => OffboardingCase::TYPES],
                        'notice_date' => $date('When notice was given'),
                        'last_working_day' => $date('Their last working day'),
                        'reason' => ['type' => 'STRING', 'description' => 'Why they are leaving.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'set_offboarding_status',
                'description' => 'Complete an exit (the employee becomes separated), cancel it, or reopen a closed one (both return the employee to active).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'action' => ['type' => 'STRING', 'enum' => array_keys(OffboardingWorkflow::ACTIONS)],
                    ],
                    'required' => ['employee', 'action'],
                ],
            ],
            [
                'name' => 'delete_offboarding_case',
                'description' => "Delete an exit and its checklist. The employee's employment status is not changed.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'add_clearance_item',
                'description' => "Add an item to an exit's clearance checklist.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'label' => ['type' => 'STRING', 'description' => 'What must be settled.'],
                        'department' => ['type' => 'STRING', 'description' => 'The department that signs it off.'],
                        'remarks' => ['type' => 'STRING'],
                    ],
                    'required' => ['employee', 'label'],
                ],
            ],
            [
                'name' => 'update_clearance_item',
                'description' => "Change a checklist item's label, department or remarks.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'item' => $item,
                        'new_label' => ['type' => 'STRING'],
                        'department' => ['type' => 'STRING', 'description' => 'The department that signs it off.'],
                        'remarks' => ['type' => 'STRING'],
                    ],
                    'required' => ['employee', 'item'],
                ],
            ],
            [
                'name' => 'set_clearance_status',
                'description' => 'Sign a checklist item off (cleared), flag it as an outstanding issue (flagged — say why in remarks), or reset it (pending).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'item' => $item,
                        'status' => ['type' => 'STRING', 'enum' => ClearanceItem::STATUSES],
                        'remarks' => ['type' => 'STRING', 'description' => 'The sign-off note, or why it is flagged.'],
                    ],
                    'required' => ['employee', 'item', 'status'],
                ],
            ],
            [
                'name' => 'remove_clearance_item',
                'description' => "Remove an item from an exit's checklist.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'item' => $item],
                    'required' => ['employee', 'item'],
                ],
            ],
            [
                'name' => 'clear_pending_clearance',
                'description' => 'Sign off every pending item on an exit at once — all of them, one department\'s, or the unassigned ones. Flagged items are left alone.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'scope' => ['type' => 'STRING', 'enum' => self::SCOPES],
                        'department' => ['type' => 'STRING', 'description' => 'With scope "department": which one.'],
                    ],
                    'required' => ['employee', 'scope'],
                ],
            ],
            [
                'name' => 'apply_clearance_template',
                'description' => "Add a clearance template's items that are not on an exit's checklist yet.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee, 'template' => ['type' => 'STRING', 'description' => 'Clearance template name.']],
                    'required' => ['employee', 'template'],
                ],
            ],
            [
                'name' => 'find_resignations',
                'description' => 'List resignations employees filed themselves — waiting for a decision by default — with the proposed last day, the notice it gives and any reason.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'status' => ['type' => 'STRING', 'enum' => [...ResignationRequest::STATUSES, 'all']],
                        'employee' => $employee,
                    ],
                ],
            ],
            [
                'name' => 'decide_resignation',
                'description' => "Accept an employee's waiting resignation — it becomes their exit and their clearance starts — or decline it, saying why.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => $employee,
                        'decision' => ['type' => 'STRING', 'enum' => ['accept', 'decline']],
                        'last_working_day' => $date('On accept: the last working day agreed; defaults to the one they proposed'),
                        'template' => ['type' => 'STRING', 'description' => 'On accept: clearance template name; defaults to the best match.'],
                        'note' => ['type' => 'STRING', 'description' => 'Required on decline: why, for the employee to read. Optional on accept.'],
                    ],
                    'required' => ['employee', 'decision'],
                ],
            ],
            [
                'name' => 'get_exit_interview',
                'description' => "Read one leaver's exit interview: whether it was answered, and their answers — reason for leaving, ratings, whether they would recommend the company or come back, and what they wrote.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'exit_interview_summary',
                'description' => 'What leavers said, added up: surveys sent and answered, the reasons people leave, each rating\'s average, and how many would recommend the company or come back.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'months' => ['type' => 'INTEGER', 'description' => 'Surveys sent in the last 3, 6 or 12 months; 0 = all time. Defaults to 12.'],
                    ],
                ],
            ],
            [
                'name' => 'send_exit_interview',
                'description' => 'Send the exit-interview survey for an exit that has none yet (a termination, or an exit started before surveys were sent).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'waive_exit_interview',
                'description' => "Stop waiting for a leaver's exit-interview survey: they can no longer answer it and are not reminded.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['employee' => $employee],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'get_my_exit',
                'description' => "The user's own resignation and exit: the resignations they filed and what was decided, whether they may file one, their clearance item by item, their exit interview and whether their Certificate of Employment is ready.",
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'file_my_resignation',
                'description' => 'File a resignation for the user themselves, with the last day they propose to work and, if they want, why. HR then accepts or declines it.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'proposed_last_day' => $date('The last day they plan to work, not in the past'),
                        'reason' => ['type' => 'STRING', 'description' => 'Why they are leaving, in their words. Optional.'],
                    ],
                    'required' => ['proposed_last_day'],
                ],
            ],
            [
                'name' => 'withdraw_my_resignation',
                'description' => "Take back the user's own resignation while it still waits for HR.",
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * A person's exit, when they have one: what kind, where it stands, when
     * they leave, and what is still outstanding.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        // No self-service exception: there is no "my exit" view to mirror.
        if ($employee === null || $user->cannot('offboarding.view')) {
            return null;
        }

        $case = OffboardingCase::query()->where('employee_id', $employee->id)->with('clearanceItems.department:id,name')->first();

        if ($case === null) {
            return null;
        }

        $items = $case->clearanceItems;
        $flagged = $items->where('status', 'flagged');
        $pending = $items->where('status', 'pending');

        return ContextSection::of('Offboarding', [
            $this->describeCase($case),
            filled($case->reason) ? 'Reason given: '.Str::limit((string) $case->reason, 200) : null,
            $flagged->isNotEmpty() ? 'Flagged: '.$flagged->map(fn (ClearanceItem $i): string => $this->describeItem($i, withStatus: false))->implode('; ').'.' : null,
            $pending->isNotEmpty() ? 'Still pending: '.$pending->take(8)->map(fn (ClearanceItem $i): string => $this->describeItem($i, withStatus: false))->implode('; ').($pending->count() > 8 ? ' and '.($pending->count() - 8).' more' : '').'.' : null,
        ]);
    }

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'offboarding', 'exit', 'exits', 'exiting', 'leaving', 'resignation', 'resignations', 'resigning',
            'resigned', 'clearance', 'clearances', 'separation', 'separations', 'last day', 'last working day',
            'quitting', 'departures', 'pag-alis', 'magre-resign',
        ];
    }

    /**
     * The offboarding board, read aloud.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('offboarding.view')) {
            return null;
        }

        return ContextSection::of('Offboarding', $this->pictureLines());
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCases(User $user, array $args): ToolResult
    {
        $status = in_array($args['status'] ?? null, OffboardingCasesIndexQuery::STATUSES, true) ? (string) $args['status'] : 'active';
        $type = in_array($args['exit_type'] ?? null, OffboardingCase::TYPES, true) ? (string) $args['exit_type'] : '';
        $department = 0;
        $employee = null;

        if (filled($args['department'] ?? null)) {
            $department = (int) $this->resolveId(Department::query(), 'name', (string) $args['department']);

            if ($department === 0) {
                return ToolResult::error('Looked up the department', 'No department is called “'.Str::limit((string) $args['department'], 60).'”.');
            }
        }

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        $cases = $this->board->filtered($status, $department, $type)
            ->when($employee !== null, fn (Builder $q) => $q->where('employee_id', $employee->id))
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $cases->map(fn (OffboardingCase $c): array => $this->caseCard($c, 'find', 'neutral', Str::headline($c->status)))->all();

        return ToolResult::found('Searched exits', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getCase(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $case->load(['program:id,name', 'clearanceItems.department:id,name', 'clearanceItems.clearedBy:id,first_name,middle_name,last_name,suffix']);

        // A named person's exit — its reason may be a termination's — was read.
        ActivityLogger::log(
            event: 'viewed',
            description: "Viewed the offboarding for {$case->employee?->full_name} via assistant",
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $case->employee?->full_name,
        );

        $items = $case->clearanceItems;
        $card = $this->caseCard($case, 'insight', $items->where('status', 'flagged')->isNotEmpty() ? 'warning' : 'info', Str::headline($case->status));
        $card['meta'] = array_values(array_filter([
            $this->clearanceLine($case),
            $case->program ? 'Checklist from the “'.$case->program->name.'” template' : null,
            filled($case->reason) ? 'Reason: '.Str::limit((string) $case->reason, 240) : null,
            ...$items->take(self::MAX_ITEMS)->map(fn (ClearanceItem $i): string => $this->describeItem($i))->all(),
            $items->count() > self::MAX_ITEMS ? ($items->count() - self::MAX_ITEMS).' more items on the case page' : null,
        ]));

        return ToolResult::found("Read {$case->employee?->full_name}'s exit", $this->describeCase($case), [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findItems(User $user, array $args): ToolResult
    {
        $status = in_array($args['status'] ?? null, ClearanceItem::STATUSES, true) ? (string) $args['status'] : 'pending';
        $departmentId = null;
        $employee = null;

        if (filled($args['department'] ?? null)) {
            $departmentId = $this->resolveId(Department::query(), 'name', (string) $args['department']);

            if ($departmentId === null) {
                return ToolResult::error('Looked up the department', 'No department is called “'.Str::limit((string) $args['department'], 60).'”.');
            }
        }

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        $items = ClearanceItem::query()
            ->with(['department:id,name', 'case.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo'])
            ->where('status', $status)
            ->whereHas('case', fn (Builder $q) => $q
                ->whereIn('status', ['initiated', 'clearance'])
                ->when($employee !== null, fn (Builder $c) => $c->where('employee_id', $employee->id)))
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->orderBy('offboarding_case_id')
            ->orderBy('sort_order')
            ->limit(self::MAX_RESULTS * 2)
            ->get();

        $cards = $items->map(fn (ClearanceItem $i): array => $this->card(
            kind: 'find',
            tone: $i->status === 'flagged' ? 'warning' : 'neutral',
            badge: Str::headline($i->status),
            title: $i->item,
            subtitle: trim(($i->case?->employee?->full_name ?? 'Employee').' · '.($i->department?->name ?? 'Unassigned')),
            meta: [filled($i->remarks) ? Str::limit((string) $i->remarks, 160) : null],
            id: $i->id,
        ))->all();

        return ToolResult::found('Searched clearance items', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function summary(User $user, array $args): ToolResult
    {
        $lines = $this->pictureLines();

        return ToolResult::found('Read the offboarding picture', null, [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Offboarding',
                title: 'Who is leaving',
                subtitle: array_shift($lines),
                meta: $lines,
            ),
        ]);
    }

    // ── Writes: the exit ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function start(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''), 'Say who is leaving.');

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $type = in_array($args['exit_type'] ?? null, OffboardingCase::TYPES, true) ? (string) $args['exit_type'] : null;

        if ($type === null) {
            return ToolResult::error('Started the exit', 'Say what kind of exit it is: '.implode(', ', OffboardingCase::TYPES).'.');
        }

        [$details, $error] = $this->caseFields($args);

        if ($error !== null) {
            return ToolResult::error('Started the exit', $error);
        }

        if (($problem = $this->invalid(['type' => $type, ...$details], (new UpdateOffboardingCaseRequest)->rules())) !== null) {
            return ToolResult::error('Started the exit', $problem);
        }

        $program = null;

        if (filled($args['template'] ?? null)) {
            [$program, $error] = $this->locateTemplate((string) $args['template']);

            if ($program === null) {
                return ToolResult::error('Looked up the template', $error);
            }
        }

        try {
            $case = $this->workflow->start($employee, ['type' => $type, ...$details], $program, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Started the exit', $e->getMessage());
        }

        $case = $this->reload($case);

        return ToolResult::ok(
            "Started {$employee->full_name}'s exit",
            $this->clearanceLine($case),
            $this->caseCard($case, 'start', 'warning', 'Started'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateCase(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        [$changes, $error] = $this->caseFields($args);

        if ($error !== null) {
            return ToolResult::error('Updated the exit', $error);
        }

        if (in_array($args['exit_type'] ?? null, OffboardingCase::TYPES, true)) {
            $changes['type'] = (string) $args['exit_type'];
        }

        if ($changes === []) {
            return ToolResult::error('Updated the exit', 'Say what to change: the exit type, a date or the reason.');
        }

        $merged = [
            'type' => $case->type,
            'notice_date' => $case->notice_date?->toDateString(),
            'last_working_day' => $case->last_working_day?->toDateString(),
            'reason' => $case->reason,
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, (new UpdateOffboardingCaseRequest)->rules())) !== null) {
            return ToolResult::error('Updated the exit', $problem);
        }

        $this->workflow->update($case, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$case->employee?->full_name}'s exit",
            implode(', ', array_map(fn (string $key): string => str_replace('_', ' ', $key), array_keys($changes))),
            $this->caseCard($this->reload($case), 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function transition(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $action = array_key_exists((string) ($args['action'] ?? ''), OffboardingWorkflow::ACTIONS) ? (string) $args['action'] : null;

        if ($action === null) {
            return ToolResult::error('Changed the exit', 'Say whether to complete, cancel or reopen it.');
        }

        $outstanding = $case->clearanceItems()->where('status', '!=', 'cleared')->count();

        try {
            $this->workflow->transition($case, $action, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Changed the exit', $e->getMessage());
        }

        $case = $this->reload($case);
        $name = $case->employee?->full_name;

        $detail = match ($action) {
            'complete' => "{$name} is now ".$case->employee?->employment_status.'.'.($outstanding > 0 ? " {$outstanding} clearance ".Str::plural('item', $outstanding).($outstanding === 1 ? ' was' : ' were').' not signed off.' : ''),
            default => "{$name} is active again.",
        };

        return ToolResult::ok(
            OffboardingWorkflow::ACTIONS[$action]." {$name}'s exit",
            $detail,
            $this->caseCard($case, $action === 'complete' ? 'approve' : 'cancel', $action === 'complete' ? 'positive' : 'info', OffboardingWorkflow::ACTIONS[$action]),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteCase(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $card = $this->caseCard($case, 'archive', 'warning', 'Deleted');
        $name = $case->employee?->full_name;

        $this->workflow->delete($case, ' via assistant');

        return ToolResult::ok("Deleted {$name}'s exit", 'Their employment status was not changed.', $card);
    }

    // ── Writes: the checklist ────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function addItem(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $data = ['item' => trim((string) ($args['label'] ?? '')), 'remarks' => filled($args['remarks'] ?? null) ? trim((string) $args['remarks']) : null];

        if (filled($args['department'] ?? null)) {
            $data['department_id'] = $this->resolveId(Department::query(), 'name', (string) $args['department']);

            if ($data['department_id'] === null) {
                return ToolResult::error('Looked up the department', 'No department is called “'.Str::limit((string) $args['department'], 60).'”.');
            }
        }

        if (($problem = $this->invalid($data, $this->itemRules())) !== null) {
            return ToolResult::error('Added the item', $problem);
        }

        $item = $this->workflow->addItem($case, $data, ' via assistant');

        return ToolResult::ok("Added “{$item->item}”", $case->employee?->full_name, $this->itemCard($item->load('department:id,name'), $case, 'add', 'info', 'Added'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateItem(User $user, array $args): ToolResult
    {
        [$item, $case, $error] = $this->locateItem($args);

        if ($item === null) {
            return ToolResult::error('Looked up the item', $error);
        }

        $changes = [];

        if (filled($args['new_label'] ?? null)) {
            $changes['item'] = trim((string) $args['new_label']);
        }

        if (filled($args['remarks'] ?? null)) {
            $changes['remarks'] = trim((string) $args['remarks']);
        }

        if (filled($args['department'] ?? null)) {
            $changes['department_id'] = $this->resolveId(Department::query(), 'name', (string) $args['department']);

            if ($changes['department_id'] === null) {
                return ToolResult::error('Looked up the department', 'No department is called “'.Str::limit((string) $args['department'], 60).'”.');
            }
        }

        if ($changes === []) {
            return ToolResult::error('Updated the item', 'Say what to change: its label, department or remarks.');
        }

        $merged = ['item' => $item->item, 'department_id' => $item->department_id, 'remarks' => $item->remarks, ...$changes];

        if (($problem = $this->invalid($merged, $this->itemRules())) !== null) {
            return ToolResult::error('Updated the item', $problem);
        }

        $this->workflow->updateItem($item, $changes, ' via assistant');

        return ToolResult::ok("Updated “{$item->item}”", $case->employee?->full_name, $this->itemCard($item->refresh()->load('department:id,name'), $case, 'edit', 'info', 'Updated'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setItemStatus(User $user, array $args): ToolResult
    {
        [$item, $case, $error] = $this->locateItem($args);

        if ($item === null) {
            return ToolResult::error('Looked up the item', $error);
        }

        $status = in_array($args['status'] ?? null, ClearanceItem::STATUSES, true) ? (string) $args['status'] : null;

        if ($status === null) {
            return ToolResult::error('Updated the item', 'Say whether it is cleared, flagged or pending.');
        }

        $remarks = filled($args['remarks'] ?? null) ? trim((string) $args['remarks']) : null;

        if (($problem = $this->invalid(['remarks' => $remarks], ['remarks' => ['nullable', 'string', 'max:2000']])) !== null) {
            return ToolResult::error('Updated the item', $problem);
        }

        $this->workflow->setItemStatus($item, $status, $user, $remarks, setRemarks: $remarks !== null, channel: ' via assistant');

        $item->refresh()->load('department:id,name', 'clearedBy:id,first_name,middle_name,last_name,suffix');

        return ToolResult::ok(
            match ($status) {
                'cleared' => "Signed off “{$item->item}”",
                'flagged' => "Flagged “{$item->item}”",
                default => "Reset “{$item->item}”",
            },
            $this->clearanceLine($this->reload($case)),
            $this->itemCard($item, $case, $status === 'cleared' ? 'approve' : 'edit', $status === 'flagged' ? 'warning' : ($status === 'cleared' ? 'positive' : 'info'), Str::headline($status)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function removeItem(User $user, array $args): ToolResult
    {
        [$item, $case, $error] = $this->locateItem($args);

        if ($item === null) {
            return ToolResult::error('Looked up the item', $error);
        }

        $card = $this->itemCard($item->load('department:id,name'), $case, 'cancel', 'warning', 'Removed');
        $label = $item->item;

        $this->workflow->removeItem($item, ' via assistant');

        return ToolResult::ok("Removed “{$label}”", $case->employee?->full_name, $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function clearPending(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $scope = in_array($args['scope'] ?? null, self::SCOPES, true) ? (string) $args['scope'] : null;

        if ($scope === null) {
            return ToolResult::error('Signed off the pending items', 'Say whether to clear everything, one department\'s items, or the unassigned ones.');
        }

        $departmentId = null;

        if ($scope === 'department') {
            $departmentId = $this->resolveId(Department::query(), 'name', (string) ($args['department'] ?? ''));

            if ($departmentId === null) {
                return ToolResult::error('Looked up the department', 'Say which department — by its exact name.');
            }
        }

        try {
            $cleared = $this->workflow->clearPending($case, $scope, $departmentId, $user, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Signed off the pending items', $e->getMessage());
        }

        $case = $this->reload($case);

        return ToolResult::ok(
            "Signed off {$cleared} ".Str::plural('item', $cleared),
            $this->clearanceLine($case),
            $this->caseCard($case, 'approve', 'positive', 'Cleared'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function applyTemplate(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        [$program, $error] = $this->locateTemplate((string) ($args['template'] ?? ''));

        if ($program === null) {
            return ToolResult::error('Looked up the template', $error);
        }

        try {
            $added = $this->workflow->applyTemplate($case, $program, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Applied the template', $e->getMessage());
        }

        return ToolResult::ok(
            "Added {$added} ".Str::plural('item', $added)." from {$program->name}",
            $this->clearanceLine($this->reload($case)),
            $this->caseCard($case, 'add', 'info', 'Template applied'),
        );
    }

    // ── Resignations and exit interviews (ADR 0076) ──────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findResignations(User $user, array $args): ToolResult
    {
        $status = (string) ($args['status'] ?? '');
        $status = $status === 'all' || in_array($status, ResignationRequest::STATUSES, true) ? $status : 'pending';
        $employee = null;

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }
        }

        $resignations = ResignationRequest::query()
            ->whereHas('employee')
            ->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id', 'employee.department:id,name', 'decider:id,first_name,middle_name,last_name,suffix'])
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->when($employee !== null, fn (Builder $q) => $q->where('employee_id', $employee->id))
            ->latest('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $resignations->map(fn (ResignationRequest $r): array => $this->resignationCard($r, 'find', $r->isPending() ? 'warning' : 'neutral', Str::headline($r->status)))->all();

        return ToolResult::found('Searched resignations', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function decideResignation(User $user, array $args): ToolResult
    {
        [$resignation, $error] = $this->locatePendingResignation((string) ($args['employee'] ?? ''));

        if ($resignation === null) {
            return ToolResult::error('Looked up the resignation', $error);
        }

        $decision = in_array($args['decision'] ?? null, ['accept', 'decline'], true) ? (string) $args['decision'] : null;
        $note = filled($args['note'] ?? null) ? trim((string) $args['note']) : null;
        $name = $resignation->employee?->full_name ?? 'the employee';

        if ($decision === null) {
            return ToolResult::error('Decided the resignation', 'Say whether to accept or decline it.');
        }

        if (($problem = $this->invalid(['note' => $note], ['note' => ['nullable', 'string', 'max:1000']])) !== null) {
            return ToolResult::error('Decided the resignation', $problem);
        }

        if ($decision === 'decline') {
            if ($note === null) {
                return ToolResult::error('Declined the resignation', 'Say why, so they know what to do next.');
            }

            try {
                $this->resignations->decline($resignation, $user, $note, ' via assistant');
            } catch (OffboardingException $e) {
                return ToolResult::error('Declined the resignation', $e->getMessage());
            }

            return ToolResult::ok("Declined {$name}’s resignation", 'They were told why.', $this->resignationCard($resignation->refresh(), 'cancel', 'info', 'Declined'));
        }

        $lastDay = filled($args['last_working_day'] ?? null)
            ? $this->isoDate($args['last_working_day'])
            : $resignation->proposed_last_day?->toDateString();

        if ($lastDay === null) {
            return ToolResult::error('Accepted the resignation', 'Give the last working day as YYYY-MM-DD.');
        }

        $program = null;

        if (filled($args['template'] ?? null)) {
            [$program, $error] = $this->locateTemplate((string) $args['template']);

            if ($program === null) {
                return ToolResult::error('Looked up the template', $error);
            }
        }

        try {
            $case = $this->resignations->accept($resignation, $user, $lastDay, $program, $note, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Accepted the resignation', $e->getMessage());
        }

        $case = $this->reload($case);

        return ToolResult::ok(
            "Accepted {$name}’s resignation",
            'Their exit has started: '.$this->clearanceLine($case).'.',
            $this->caseCard($case, 'approve', 'positive', 'Accepted'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getInterview(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $interview = $case->exitInterview()->first();
        $name = $case->employee?->full_name;

        if ($interview === null) {
            return ToolResult::error('Read the exit interview', "No exit interview was sent to {$name}.");
        }

        // What a named leaver said about the company was read.
        ActivityLogger::log(
            event: 'viewed',
            description: "Viewed the exit interview of {$name} via assistant",
            subject: $case,
            logName: 'offboarding',
            subjectLabel: $name,
        );

        $answers = $interview->answers ?? [];
        $status = match ($interview->status) {
            'submitted' => 'answered '.$this->localDay($interview->submitted_at),
            'waived' => 'waived',
            default => 'sent '.$this->localDay($interview->sent_at).', not answered yet',
        };

        $lines = [];

        if ($interview->status === 'submitted') {
            $average = ExitInterviewQuestionnaire::averageRating($answers);

            foreach ($interview->questions ?? ExitInterviewQuestionnaire::questions() as $question) {
                $value = $answers[$question['key']] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $lines[] = match ($question['type']) {
                    'rating' => "{$question['label']}: {$value}/5 (".(ExitInterviewQuestionnaire::SCALE[(int) $value] ?? '').')',
                    'choice', 'yes_no' => "{$question['label']} ".($question['options'][$value] ?? $value),
                    default => "{$question['label']} “".Str::limit((string) $value, 300).'”',
                };
            }

            if ($average !== null) {
                array_unshift($lines, "Average rating {$average} of 5");
            }
        }

        $card = $this->caseCard($case, 'insight', $interview->status === 'submitted' ? 'info' : 'neutral', Str::headline($interview->status === 'submitted' ? 'answered' : $interview->status));
        $card['subtitle'] = 'Exit interview — '.$status;
        $card['meta'] = $lines;

        return ToolResult::found("Read {$name}’s exit interview", ucfirst($status), [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function interviewSummary(User $user, array $args): ToolResult
    {
        $months = isset($args['months']) && in_array((int) $args['months'], self::PERIODS, true) ? (int) $args['months'] : 12;

        $interviews = ExitInterview::query()
            ->whereHas('offboardingCase')
            ->whereHas('employee')
            ->when($months > 0, fn (Builder $q) => $q->where('sent_at', '>=', now()->subMonths($months)))
            ->get(['id', 'status', 'answers', 'sent_at']);

        $submitted = $interviews->where('status', 'submitted')->values();
        $asked = $interviews->where('status', '!=', 'waived')->count();
        $summary = ExitInterviewQuestionnaire::summarize($submitted);
        $period = $months > 0 ? "the last {$months} months" : 'all time';

        $tally = fn (array $counts): string => collect(ExitInterviewQuestionnaire::YES_MAYBE_NO)
            ->map(fn (string $label, string $key): string => $label.' '.($counts[$key] ?? 0))
            ->implode(', ');

        $meta = array_values(array_filter([
            sprintf(
                '%d sent, %d answered, %d waiting, %d waived%s.',
                $interviews->count(),
                $submitted->count(),
                $interviews->where('status', 'pending')->count(),
                $interviews->where('status', 'waived')->count(),
                $asked > 0 ? ' — '.round($submitted->count() / $asked * 100).'% answered' : '',
            ),
            $summary['reasons'] !== []
                ? 'Why they leave: '.collect($summary['reasons'])->take(6)->map(fn (array $r): string => "{$r['label']} {$r['count']} (".round($r['share'] * 100).'%)')->implode('; ').'.'
                : null,
            $summary['responses'] > 0
                ? 'Ratings (of 5): '.collect($summary['ratings'])->filter(fn (array $r): bool => $r['average'] !== null)->sortBy('average')->map(fn (array $r): string => "{$r['label']} {$r['average']}")->implode('; ').'.'
                : null,
            $summary['responses'] > 0 ? 'Would recommend us: '.$tally($summary['recommend']).'.' : null,
            $summary['responses'] > 0 ? 'Would come back: '.$tally($summary['return']).'.' : null,
        ]));

        return ToolResult::found('Read the exit interviews', $period, [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Exit interviews',
                title: 'What leavers say',
                subtitle: $summary['average'] !== null ? "Surveys sent in {$period} — average rating {$summary['average']} of 5" : "Surveys sent in {$period}",
                meta: $meta,
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function sendInterview(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        try {
            $this->interviews->send($case, $user, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Sent the exit interview', $e->getMessage());
        }

        $name = $case->employee?->full_name;

        return ToolResult::ok(
            "Sent the exit interview to {$name}",
            $case->employee?->user_id !== null ? 'They were told.' : 'They have no account to answer it with; HR can take their answers by hand.',
            $this->caseCard($case, 'add', 'info', 'Survey sent'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function waiveInterview(User $user, array $args): ToolResult
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return ToolResult::error('Looked up the exit', $error);
        }

        $interview = $case->exitInterview()->first();

        if ($interview === null) {
            return ToolResult::error('Waived the exit interview', "No exit interview was sent to {$case->employee?->full_name}.");
        }

        try {
            $this->interviews->waive($interview, $user, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Waived the exit interview', $e->getMessage());
        }

        return ToolResult::ok("Waived {$case->employee?->full_name}’s exit interview", 'It is no longer waiting for answers.', $this->caseCard($case, 'cancel', 'info', 'Survey waived'));
    }

    // ── The user's own exit ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function myExit(User $user, array $args): ToolResult
    {
        $employee = $user->employee()->first();

        if ($employee === null) {
            return ToolResult::error('Read your exit', 'Your account is not linked to an employee record.');
        }

        $mine = MyExit::for($employee);
        $cards = [];

        if ($mine['exit'] !== null) {
            $exit = $mine['exit'];
            $clearance = $exit['clearance'];
            $outstanding = collect($exit['items'])->where('status', '!=', 'cleared');

            $cards[] = $this->card(
                kind: 'insight',
                tone: $clearance['flagged'] > 0 ? 'warning' : 'info',
                badge: Str::headline($exit['status']),
                title: 'Your exit',
                subtitle: trim(Str::headline($exit['type']).' · '.($exit['last_working_day'] ? 'last day '.CarbonImmutable::parse($exit['last_working_day'])->format('M j, Y') : 'no last day set')),
                meta: [
                    "Clearance: {$clearance['cleared']} of {$clearance['total']} signed off".($clearance['flagged'] > 0 ? ", {$clearance['flagged']} flagged" : ''),
                    ...$outstanding->take(self::MAX_ITEMS)->map(fn (array $item): string => Str::headline($item['status']).": {$item['item']} (".($item['department'] ?? 'Unassigned').')'.(filled($item['remarks']) ? ' — '.Str::limit((string) $item['remarks'], 140) : ''))->all(),
                    $mine['interview'] !== null ? 'Exit interview: '.match (true) {
                        $mine['interview']['can_answer'] => 'waiting for your answers — on My resignation (/offboarding/me) or in the app',
                        $mine['interview']['status'] === 'submitted' => 'answered',
                        default => 'not needed',
                    } : null,
                    $exit['certificate']['ready']
                        ? 'Certificate of Employment: ready to download on My resignation'.($exit['certificate']['number'] ? " ({$exit['certificate']['number']})" : '')
                        : 'Certificate of Employment: available once your exit is completed',
                ],
                id: $exit['id'],
            );
        }

        foreach (array_slice($mine['resignations'], 0, 3) as $resignation) {
            $cards[] = $this->card(
                kind: 'find',
                tone: $resignation['status'] === 'pending' ? 'warning' : 'neutral',
                badge: Str::headline($resignation['status']),
                title: 'Your resignation',
                subtitle: 'Last day '.CarbonImmutable::parse($resignation['proposed_last_day'])->format('M j, Y')." · {$resignation['notice_days']} days’ notice",
                meta: [
                    $resignation['filed_on'] ? 'Filed '.CarbonImmutable::parse($resignation['filed_on'])->format('M j, Y') : null,
                    filled($resignation['decision_note']) ? 'HR’s note: '.Str::limit((string) $resignation['decision_note'], 300) : null,
                ],
                id: $resignation['id'],
            );
        }

        $detail = match (true) {
            $mine['exit'] !== null => 'Your exit is '.$mine['exit']['status'].'.',
            collect($mine['resignations'])->contains('status', 'pending') => 'Your resignation is waiting for HR.',
            default => 'You have no exit under way.'.($mine['can_resign'] ? ' You can file a resignation.' : ''),
        };

        return ToolResult::found('Read your exit', $detail, $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function fileMine(User $user, array $args): ToolResult
    {
        $employee = $user->employee()->first();

        if ($employee === null) {
            return ToolResult::error('Filed your resignation', 'Your account is not linked to an employee record.');
        }

        $data = [
            'proposed_last_day' => filled($args['proposed_last_day'] ?? null) ? ($this->isoDate($args['proposed_last_day']) ?? (string) $args['proposed_last_day']) : null,
            'reason' => filled($args['reason'] ?? null) ? trim((string) $args['reason']) : null,
        ];

        if (($problem = $this->invalid($data, FileResignationRequest::rulesFor(), (new FileResignationRequest)->messages())) !== null) {
            return ToolResult::error('Filed your resignation', $problem);
        }

        try {
            $resignation = $this->resignations->file($employee, $user, $data, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Filed your resignation', $e->getMessage());
        }

        $notice = $resignation->noticeDays();

        return ToolResult::ok(
            'Filed your resignation',
            $notice < ResignationRequest::NOTICE_DAYS
                ? "HR will decide. It gives {$notice} days’ notice — less than the 30 the Labor Code asks for, which HR has to agree to."
                : 'HR will decide, and you will be told.',
            $this->resignationCard($resignation->load('employee'), 'add', 'warning', 'Filed'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function withdrawMine(User $user, array $args): ToolResult
    {
        $employee = $user->employee()->first();

        if ($employee === null) {
            return ToolResult::error('Withdrew your resignation', 'Your account is not linked to an employee record.');
        }

        $resignation = ResignationRequest::query()->where('employee_id', $employee->id)->pending()->with('employee')->first();

        if ($resignation === null) {
            return ToolResult::error('Withdrew your resignation', 'You have no resignation waiting for HR.');
        }

        try {
            $this->resignations->withdraw($resignation, $user, ' via assistant');
        } catch (OffboardingException $e) {
            return ToolResult::error('Withdrew your resignation', $e->getMessage());
        }

        return ToolResult::ok('Withdrew your resignation', 'HR was told.', $this->resignationCard($resignation->refresh(), 'cancel', 'info', 'Withdrawn'));
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * The exit of exactly one employee, or why not.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: OffboardingCase|null, 1: string}
     */
    private function locateCase(array $args): array
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''), 'Say whose exit.');

        if ($employee === null) {
            return [null, $error];
        }

        $case = OffboardingCase::query()->where('employee_id', $employee->id)->first();

        return $case === null
            ? [null, "{$employee->full_name} is not being offboarded."]
            : [$this->reload($case), ''];
    }

    /**
     * One item on one exit's checklist by (part of) its label: an exact label
     * first, then a partial match only one item has.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: ClearanceItem|null, 1: OffboardingCase|null, 2: string}
     */
    private function locateItem(array $args): array
    {
        [$case, $error] = $this->locateCase($args);

        if ($case === null) {
            return [null, null, $error];
        }

        $needle = Str::lower(trim((string) ($args['item'] ?? '')));

        if ($needle === '') {
            return [null, $case, 'Say which checklist item.'];
        }

        $items = $case->clearanceItems()->get();
        $exact = $items->filter(fn (ClearanceItem $i): bool => Str::lower(trim($i->item)) === $needle);
        $matches = $exact->isNotEmpty() ? $exact : $items->filter(fn (ClearanceItem $i): bool => str_contains(Str::lower($i->item), $needle));

        return match (true) {
            $matches->isEmpty() => [null, $case, 'No item on '.$case->employee?->full_name.'\'s checklist matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), $case, ''],
            default => [null, $case, 'More than one item matches: '.$matches->take(6)->pluck('item')->implode('; ').'.'],
        };
    }

    /**
     * An active clearance template by name: exact, then a unique partial.
     *
     * @return array{0: OffboardingProgram|null, 1: string}
     */
    private function locateTemplate(string $name): array
    {
        $needle = Str::lower(trim($name));
        $templates = OffboardingProgram::query()->where('is_active', true)->get();
        $exact = $templates->filter(fn (OffboardingProgram $p): bool => Str::lower($p->name) === $needle);
        $matches = $exact->isNotEmpty() ? $exact : $templates->filter(fn (OffboardingProgram $p): bool => $needle !== '' && str_contains(Str::lower($p->name), $needle));

        return match (true) {
            $matches->isEmpty() => [null, 'No active clearance template matches “'.Str::limit($name, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one template matches: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * The resignation waiting for a decision from exactly one employee, or why
     * not.
     *
     * @return array{0: ResignationRequest|null, 1: string}
     */
    private function locatePendingResignation(string $needle): array
    {
        [$employee, $error] = $this->resolveEmployee($needle, 'Say whose resignation.');

        if ($employee === null) {
            return [null, $error];
        }

        $resignation = ResignationRequest::query()->where('employee_id', $employee->id)->pending()->with('employee')->first();

        return $resignation === null
            ? [null, "{$employee->full_name} has no resignation waiting for a decision."]
            : [$resignation, ''];
    }

    /**
     * The exit fields present in the arguments — or why one is not usable.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function caseFields(array $args): array
    {
        $fields = [];

        foreach (['notice_date', 'last_working_day'] as $key) {
            if (! filled($args[$key] ?? null)) {
                continue;
            }

            if (($date = $this->isoDate($args[$key])) === null) {
                return [[], 'Give the '.str_replace('_', ' ', $key).' as YYYY-MM-DD.'];
            }

            $fields[$key] = $date;
        }

        if (filled($args['reason'] ?? null)) {
            $fields['reason'] = trim((string) $args['reason']);
        }

        return [$fields, null];
    }

    /**
     * The checklist item form's own rules — the department already resolved
     * within this workspace.
     *
     * @return array<string, mixed>
     */
    private function itemRules(): array
    {
        return [...(new StoreClearanceItemRequest)->rules(), 'department_id' => ['nullable', 'integer']];
    }

    private function reload(OffboardingCase $case): OffboardingCase
    {
        return OffboardingCase::query()
            ->with(['employee.department:id,name', 'employee.position:id,title'])
            ->withCount([
                'clearanceItems as items_count',
                'clearanceItems as cleared_items_count' => fn (Builder $q) => $q->where('status', 'cleared'),
                'clearanceItems as flagged_items_count' => fn (Builder $q) => $q->where('status', 'flagged'),
            ])
            ->findOrFail($case->id);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * The board in lines — its tiles, who leaves next, and what is flagged.
     *
     * @return list<string>
     */
    private function pictureLines(): array
    {
        $stats = $this->statistics->toArray();

        $leaving = $this->board->filtered('active')
            ->whereNotNull('last_working_day')
            ->whereDate('last_working_day', '>=', today())
            ->limit(5)
            ->get();

        /** @var Collection<int, ClearanceItem> $flagged */
        $flagged = ClearanceItem::query()
            ->with(['department:id,name', 'case.employee:id,first_name,middle_name,last_name,suffix'])
            ->where('status', 'flagged')
            ->whereHas('case', fn (Builder $q) => $q->whereIn('status', ['initiated', 'clearance']))
            ->limit(5)
            ->get();

        return array_values(array_filter([
            sprintf(
                'In offboarding: %d; leaving in the next 14 days: %d; flagged clearance items: %d; completed this month: %d.',
                $stats['active'],
                $stats['leaving_soon'],
                $stats['flagged_items'],
                $stats['completed_this_month'],
            ),
            $leaving->isNotEmpty()
                ? 'Leaving next: '.$leaving->map(fn (OffboardingCase $c): string => sprintf(
                    '%s (%s, last day %s; %d of %d cleared)',
                    $c->employee?->full_name ?? 'Unknown',
                    Str::headline($c->type),
                    $c->last_working_day?->format('M j'),
                    (int) $c->cleared_items_count,
                    (int) $c->items_count,
                ))->implode('; ').'.'
                : null,
            $flagged->isNotEmpty()
                ? 'Flagged: '.$flagged->map(fn (ClearanceItem $i): string => ($i->case?->employee?->full_name ?? 'Unknown').' — '.$this->describeItem($i, withStatus: false))->implode('; ').'.'
                : null,
        ]));
    }

    private function describeCase(OffboardingCase $case): string
    {
        $case->loadMissing('clearanceItems');

        $lastDay = $case->last_working_day;
        $when = $lastDay === null
            ? 'no last working day set'
            : 'last working day '.$lastDay->format('M j, Y').($case->isActive()
                ? ($lastDay->isPast() && ! $lastDay->isToday() ? ' (passed)' : ' (in '.(int) today()->diffInDays($lastDay).' days)')
                : '');

        return sprintf(
            '%s — %s, %s; %s.',
            Str::headline($case->type),
            $case->status,
            $when,
            $this->clearanceLine($case),
        );
    }

    private function clearanceLine(OffboardingCase $case): string
    {
        $total = (int) ($case->items_count ?? $case->clearanceItems()->count());
        $cleared = (int) ($case->cleared_items_count ?? $case->clearanceItems()->where('status', 'cleared')->count());
        $flagged = (int) ($case->flagged_items_count ?? $case->clearanceItems()->where('status', 'flagged')->count());

        return sprintf(
            'clearance %s: %d of %d signed off%s',
            str_replace('_', ' ', OffboardingProvisioner::clearanceStatus($total, $cleared)),
            $cleared,
            $total,
            $flagged > 0 ? ", {$flagged} flagged" : '',
        );
    }

    private function describeItem(ClearanceItem $item, bool $withStatus = true): string
    {
        $by = $item->relationLoaded('clearedBy') && $item->clearedBy ? $item->clearedBy->full_name : null;

        return trim(sprintf(
            '%s%s (%s)%s%s',
            $withStatus ? Str::headline($item->status).': ' : '',
            $item->item,
            $item->department?->name ?? 'Unassigned',
            $by !== null && $item->status === 'cleared' ? ' — signed off by '.$by.($item->cleared_at ? ' on '.$item->cleared_at->format('M j') : '') : '',
            filled($item->remarks) ? ' — '.Str::limit((string) $item->remarks, 140) : '',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function caseCard(OffboardingCase $case, string $kind, string $tone, string $badge): array
    {
        $employee = $case->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: trim(Str::headline($case->type).' · '.($case->last_working_day ? 'last day '.$case->last_working_day->format('M j, Y') : 'no last day set')),
            meta: [$this->clearanceLine($case), $employee?->department?->name],
            avatar: $employee instanceof Employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $case->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function itemCard(ClearanceItem $item, OffboardingCase $case, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $item->item,
            subtitle: trim(($case->employee?->full_name ?? 'Employee').' · '.($item->department?->name ?? 'Unassigned')),
            meta: [filled($item->remarks) ? Str::limit((string) $item->remarks, 160) : null],
            id: $item->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resignationCard(ResignationRequest $resignation, string $kind, string $tone, string $badge): array
    {
        $employee = $resignation->employee;
        $notice = $resignation->noticeDays();

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: 'Resignation · last day '.$resignation->proposed_last_day?->format('M j, Y')." · {$notice} ".Str::plural('day', $notice).'’ notice'
                .($notice < ResignationRequest::NOTICE_DAYS ? ' (short)' : ''),
            meta: [
                'Filed '.$this->localDay($resignation->created_at),
                filled($resignation->reason) ? 'Reason: '.Str::limit((string) $resignation->reason, 240) : null,
                ! $resignation->isPending() && $resignation->decider !== null ? 'Decided by '.$resignation->decider->full_name.' '.$this->localDay($resignation->decided_at) : null,
                filled($resignation->decision_note) ? 'Note: '.Str::limit((string) $resignation->decision_note, 240) : null,
            ],
            avatar: $employee instanceof Employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $resignation->hashid,
        );
    }

    /**
     * "Oct 3, 2026", on the organisation's calendar.
     */
    private function localDay(?\DateTimeInterface $at): string
    {
        return $at === null ? '—' : CarbonImmutable::parse(OrganizationClock::localDate(CarbonImmutable::instance($at)))->format('M j, Y');
    }
}
