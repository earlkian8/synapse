<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Employee\StoreEmployeeCertificationRequest;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Security\UntrustedText;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Employees\EmployeeCertifications;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Employee Records capability (ADR 0059): what sits in an employee's 201 file
 * beside their details — certifications (licences, board exams, trainings) and
 * the documents on file.
 *
 * **Certifications** are read with `employees.view` — one person's, or across
 * the company by expiry ("whose licences lapse this quarter?") — and added or
 * removed with `employees.manage-documents` through
 * {@see EmployeeCertifications}, the profile's own path. Removing one waits for
 * the user's Confirm.
 *
 * **Documents** are files the assistant can neither upload nor open. It lists
 * one person's by title, type and date only, with `employees.manage-documents`
 * — a document list can say more than it seems (a medical certificate) — and
 * the read is audited as `viewed` (ADR 0027).
 */
class EmployeeRecordsModule extends Module implements ContributesTopicContext
{
    private const CHANNEL = ' via assistant';

    /** How many rows a list returns at most. */
    private const MAX_ROWS = 20;

    public function __construct(private readonly EmployeeCertifications $certifications) {}

    public function key(): string
    {
        return 'employee-records';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('employees.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_certifications' => 'findCertifications',
            'find_employee_documents' => 'findDocuments',
            'add_certification' => 'add',
            'remove_certification' => 'remove',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_certifications' => 'employees.view',
            'find_employee_documents' => 'employees.manage-documents',
            'add_certification' => 'employees.manage-documents',
            'remove_certification' => 'employees.manage-documents',
        ];
    }

    protected function confirmTools(): array
    {
        return ['remove_certification'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot('employees.view') || $user->cannot($this->permissionMap()[$tool])) {
            return $this->denied('do that with employee records');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        EMPLOYEE RECORDS — certifications (licences, board exams, trainings with an issuer and dates) and the documents on an employee's 201 file. find_certifications lists one person's, or everyone's expiring within some days; add_certification adds one; remove_certification waits for confirmation. find_employee_documents lists one person's documents by title and type — files cannot be opened, uploaded or deleted in chat.
        TXT;
    }

    public function tools(User $user): array
    {
        $employee = ['type' => 'STRING', 'description' => 'Full name or employee number.'];
        $date = ['type' => 'STRING', 'description' => 'YYYY-MM-DD'];

        return $this->permitted($user, [
            ['name' => 'find_certifications', 'description' => "One employee's certifications, or everyone's that expire within some days.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => $employee, 'expiring_within_days' => ['type' => 'INTEGER'], 'include_expired' => ['type' => 'BOOLEAN']]]],
            ['name' => 'find_employee_documents', 'description' => "The documents on an employee's file, by title and type.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => $employee], 'required' => ['employee']]],
            ['name' => 'add_certification', 'description' => 'Add a certification to an employee.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => $employee, 'name' => ['type' => 'STRING'], 'issuer' => ['type' => 'STRING'], 'issued_date' => $date, 'expiry_date' => $date], 'required' => ['employee', 'name']]],
            ['name' => 'remove_certification', 'description' => "Remove a certification from an employee's file.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => $employee, 'certification' => ['type' => 'STRING', 'description' => 'Its name.']], 'required' => ['employee', 'certification']]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['certification', 'certifications', 'license', 'licenses', 'licence', 'licences', 'board exam', 'expiring', 'prc'];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('employees.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $today = OrganizationClock::today();
        $soon = OrganizationClock::now()->addDays(90)->toDateString();

        return ContextSection::of('Certifications', [
            $this->onFile()->whereBetween('expiry_date', [$today, $soon])->count().' certifications expire in the next 90 days; '
                .$this->onFile()->where('expiry_date', '<', $today)->count().' have already expired.',
        ]);
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findCertifications(User $user, array $args): ToolResult
    {
        $query = $this->onFile()->with('employee:id,first_name,middle_name,last_name,suffix,employee_no');
        $label = 'Listed certifications';

        if (filled($args['employee'] ?? null)) {
            [$employee, $error] = $this->resolveEmployee((string) $args['employee']);

            if ($employee === null) {
                return ToolResult::error('Looked up the employee', $error);
            }

            $query->where('employee_id', $employee->id);
            $label = "Listed {$employee->full_name}'s certifications";
        } elseif (is_int($args['expiring_within_days'] ?? null)) {
            $days = max(1, min(730, $args['expiring_within_days']));
            $query->where('expiry_date', '<=', OrganizationClock::now()->addDays($days)->toDateString());
            $label = "Listed certifications expiring within {$days} days";
        } else {
            return ToolResult::error('Listed certifications', 'Say whose, or how many days ahead to look for expiring ones.');
        }

        if (($args['include_expired'] ?? false) !== true && ! filled($args['employee'] ?? null)) {
            $query->where('expiry_date', '>=', OrganizationClock::today());
        }

        $total = (clone $query)->count();
        $cards = $query->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('name')->limit(self::MAX_ROWS)->get()
            ->filter(fn (EmployeeCertification $c): bool => $c->employee !== null)
            ->map(fn (EmployeeCertification $c): array => $this->certificationCard($c))
            ->values()
            ->all();

        return ToolResult::found($label, $total === 0 ? 'None' : ($total > count($cards) ? "{$total}; the first ".count($cards).' shown' : "{$total}"), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findDocuments(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        // A named person's file was read — ADR 0027's audit rule.
        ActivityLogger::log(
            event: 'viewed',
            description: "Viewed the document list of {$employee->full_name} via assistant",
            subject: $employee,
            logName: 'employees',
            subjectLabel: $employee->full_name,
        );

        $cards = $employee->documents()->latest('id')->limit(self::MAX_ROWS)->get()
            ->map(fn (EmployeeDocument $d): array => $this->card(
                kind: 'find',
                tone: 'neutral',
                badge: Str::headline((string) ($d->type ?: 'Document')),
                title: UntrustedText::clean((string) $d->title, 120) ?? 'Document',
                subtitle: $employee->full_name,
                meta: ['Added '.$d->created_at?->diffForHumans()],
            ))
            ->all();

        return ToolResult::found("Listed {$employee->full_name}'s documents", $cards === [] ? 'None on file' : count($cards).' on file — open them from the employee profile', $cards);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function add(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $data = collect(Arr::only($args, ['name', 'issuer', 'issued_date', 'expiry_date']))
            ->map(fn (mixed $v): string => trim(is_scalar($v) ? (string) $v : ''))
            ->filter(fn (string $v): bool => $v !== '')
            ->all();

        foreach (['issued_date', 'expiry_date'] as $key) {
            if (isset($data[$key]) && $this->isoDate($data[$key]) === null) {
                return ToolResult::error('Added the certification', "“{$data[$key]}” is not a date. Use YYYY-MM-DD.");
            }
        }

        $problem = $this->invalid($data, Arr::except(StoreEmployeeCertificationRequest::rulesFor(), ['file']));

        if ($problem !== null) {
            return ToolResult::error('Added the certification', $problem);
        }

        $duplicate = $employee->certifications()->whereRaw('lower(name) = ?', [Str::lower($data['name'])])->exists();

        if ($duplicate) {
            return ToolResult::error('Added the certification', "{$employee->full_name} already has a certification called “{$data['name']}”.");
        }

        $certification = $this->certifications->add($employee, $data, null, self::CHANNEL);

        return ToolResult::ok("Added “{$certification->name}” to {$employee->full_name}", null, $this->certificationCard($certification->setRelation('employee', $employee)));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function remove(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $needle = Str::lower(trim((string) ($args['certification'] ?? '')));
        $matches = $employee->certifications()->get()->filter(fn (EmployeeCertification $c): bool => str_contains(Str::lower($c->name), $needle));
        $exact = $matches->filter(fn (EmployeeCertification $c): bool => Str::lower($c->name) === $needle);
        $certification = $exact->count() === 1 ? $exact->first() : ($matches->count() === 1 ? $matches->first() : null);

        if ($needle === '' || $certification === null) {
            return ToolResult::error('Removed the certification', $matches->count() > 1
                ? 'More than one certification matches: '.$this->catalog($matches->pluck('name')).'.'
                : "{$employee->full_name} has no certification called “".Str::limit(trim((string) ($args['certification'] ?? '')), 60).'”.');
        }

        $name = $certification->name;
        $this->certifications->remove($employee, $certification, self::CHANNEL);

        return ToolResult::ok("Removed “{$name}” from {$employee->full_name}");
    }

    /**
     * Certifications of employees still on the roster — one in the trash is
     * neither listed nor counted.
     *
     * @return Builder<EmployeeCertification>
     */
    private function onFile(): Builder
    {
        return EmployeeCertification::query()->whereHas('employee');
    }

    /**
     * @return array<string, mixed>
     */
    private function certificationCard(EmployeeCertification $c): array
    {
        $today = OrganizationClock::today();
        $expired = $c->expiry_date !== null && $c->expiry_date->toDateString() < $today;

        return $this->card(
            kind: 'find',
            tone: $expired ? 'warning' : 'neutral',
            badge: $c->expiry_date === null ? 'No expiry' : ($expired ? 'Expired' : 'Valid'),
            title: UntrustedText::clean($c->name, 120) ?? 'Certification',
            subtitle: $c->employee?->full_name,
            meta: [
                filled($c->issuer) ? 'Issued by '.UntrustedText::clean($c->issuer, 120) : null,
                $c->issued_date !== null ? 'Issued '.$c->issued_date->format('M j, Y') : null,
                $c->expiry_date !== null ? ($expired ? 'Expired ' : 'Expires ').$c->expiry_date->format('M j, Y') : null,
            ],
            id: $c->id,
        );
    }
}
