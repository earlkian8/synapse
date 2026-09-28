<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\UpdateCompanyProfileRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Setup\CompanyProfileWriter;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Company Profile capability: the company's own record — its display and
 * registered names, contact details, and the time zone every attendance day is
 * judged on (ADR 0036). The tenant *is* the profile (ADR 0005), so this only
 * ever reads and writes the workspace the user is in.
 *
 * **Reading** answers "what's our registered name?", "what time zone are we
 * on?", "is our company profile complete?". **Doing** edits the names and
 * contact details, and changes the time zone, through
 * {@see CompanyProfileWriter::save()} — the Company Profile screen's own path —
 * against the screen's own rules ({@see UpdateCompanyProfileRequest}).
 *
 * Deliberately left to the screen:
 *
 * - **Statutory employer numbers** (TIN, SSS, PhilHealth, Pag-IBIG). They are
 *   what payroll remits against: a digit wrong in chat misdirects remittances,
 *   and a conversation is not where they should be kept. The assistant says
 *   which are on file, never what they are.
 * - **The join code**, a credential (ADR 0026): never read, rotated or switched
 *   here. Whether joining by code is on is said to those who manage it.
 * - **The logo**, which is an upload.
 *
 * Changing the time zone waits for the user's Confirm (ADR 0049), and the card
 * says what it moves. Everything needs `setup.company.view`; changes
 * `setup.company.manage`.
 */
class CompanyProfileModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    /**
     * The contact and identity fields the assistant may edit, as the tool names
     * them.
     *
     * @var array<string, string>
     */
    private const EDITABLE = [
        'name' => 'display name',
        'legal_name' => 'registered legal name',
        'email' => 'email',
        'phone' => 'phone',
        'address' => 'address',
    ];

    /**
     * The statutory employer numbers, by column — reported as on file or not.
     *
     * @var array<string, string>
     */
    private const STATUTORY = [
        'tin' => 'TIN',
        'sss_employer_no' => 'SSS',
        'philhealth_employer_no' => 'PhilHealth',
        'pagibig_employer_no' => 'Pag-IBIG',
    ];

    public function key(): string
    {
        return 'company';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.company.view');
    }

    protected function toolMap(): array
    {
        return [
            'get_company_profile' => 'getProfile',
            'update_company_profile' => 'updateProfile',
            'set_company_timezone' => 'setTimezone',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'get_company_profile' => 'setup.company.view',
            'update_company_profile' => 'setup.company.manage',
            'set_company_timezone' => 'setup.company.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // The zone is the clock "today", lateness and every time on every
        // screen are read on — one word changes all of them.
        return ['set_company_timezone'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        $permission = $this->permissionMap()[$tool] ?? 'setup.company.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.company.manage' ? 'change the company profile' : 'view the company profile');
        }

        $organization = app(Tenancy::class)->organization();

        if ($organization === null) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        return $this->{$this->toolMap()[$tool]}($user, $organization, $args);
    }

    public function guidance(User $user): string
    {
        $manage = $this->allows($user, 'setup.company.manage')
            ? <<<'TXT'

            - update_company_profile changes the display name, registered legal name, email, phone or address; clear empties one of them.
            - set_company_timezone changes the time zone (an IANA zone such as Asia/Manila, or a city); it waits for the user's confirmation.
            - The logo, the statutory employer numbers and the join code are changed on the Company Profile screen (/setup/company), not here — say so if asked.
            TXT
            : '';

        return <<<TXT
        COMPANY PROFILE — the company's own record: its display and registered names, contact details, and the time zone attendance is judged on.
        - get_company_profile reads it. Statutory employer numbers (TIN, SSS, PhilHealth, Pag-IBIG) are only ever reported as on file or missing, never the numbers themselves.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        return $this->permitted($user, [
            [
                'name' => 'get_company_profile',
                'description' => "The company's profile: names, contact details, time zone, and which statutory employer numbers are on file.",
                'parameters' => ['type' => 'OBJECT', 'properties' => new \stdClass],
            ],
            [
                'name' => 'update_company_profile',
                'description' => "Change the company's display name, registered legal name, email, phone or address. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING', 'description' => 'The display name.'],
                        'legal_name' => ['type' => 'STRING', 'description' => 'The registered legal name.'],
                        'email' => ['type' => 'STRING'],
                        'phone' => ['type' => 'STRING'],
                        'address' => ['type' => 'STRING'],
                        'clear' => [
                            'type' => 'ARRAY',
                            'description' => 'Fields to empty.',
                            'items' => ['type' => 'STRING', 'enum' => ['legal_name', 'email', 'phone', 'address']],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'set_company_timezone',
                'description' => "Change the company's time zone — the clock attendance is judged on and every time is shown in.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'timezone' => ['type' => 'STRING', 'description' => 'An IANA zone such as Asia/Manila, or its city.'],
                    ],
                    'required' => ['timezone'],
                ],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'company profile', 'company details', 'company info', 'company information', 'company name',
            'legal name', 'registered name', 'time zone', 'timezone', 'company address', 'company email',
            'company phone', 'employer numbers', 'statutory numbers',
        ];
    }

    public function topicContext(User $user): ?ContextSection
    {
        $organization = app(Tenancy::class)->organization();

        if ($user->cannot('setup.company.view') || $organization === null) {
            return null;
        }

        return ContextSection::of(
            'Company profile',
            $this->profileLines($user, $organization),
            'Statutory employer numbers are only reported as on file or missing.',
        );
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool !== 'set_company_timezone') {
            return null;
        }

        [$zone] = $this->zone((string) ($args['timezone'] ?? ''));

        if ($zone === null) {
            return null;
        }

        $current = OrganizationClock::timezone();

        return sprintf(
            'It is %s in %s and %s on the current clock (%s). From now on "today", lateness and every time shown follow %s; days already recorded keep their times.',
            CarbonImmutable::now($zone)->format('D H:i'),
            $zone,
            CarbonImmutable::now($current)->format('D H:i'),
            $current,
            $zone,
        );
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function getProfile(User $user, Organization $organization, array $args): ToolResult
    {
        $lines = $this->profileLines($user, $organization);

        return ToolResult::found('Read the company profile', null, [
            $this->card(
                kind: 'insight',
                tone: 'info',
                badge: 'Company profile',
                title: $organization->name,
                subtitle: array_shift($lines),
                meta: $lines,
            ),
        ]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateProfile(User $user, Organization $organization, array $args): ToolResult
    {
        $changes = [];

        foreach (array_keys(self::EDITABLE) as $field) {
            if (filled($args[$field] ?? null)) {
                $changes[$field] = trim((string) $args[$field]);
            }
        }

        foreach ((array) ($args['clear'] ?? []) as $field) {
            if (is_string($field) && $field !== 'name' && array_key_exists($field, self::EDITABLE)) {
                if (array_key_exists($field, $changes)) {
                    return ToolResult::error('Updated the company profile', 'Either set the '.self::EDITABLE[$field].' or clear it, not both.');
                }

                $changes[$field] = null;
            }
        }

        if ($changes === []) {
            return ToolResult::error('Updated the company profile', 'Say what to change: the display name, registered legal name, email, phone or address.');
        }

        // The profile as it would be, against the screen's own rules — the time
        // zone included, because the form requires it on every save.
        $fields = [...array_keys(self::EDITABLE), 'timezone'];
        $merged = [...Arr::only($organization->getAttributes(), $fields), ...$changes];
        $request = new UpdateCompanyProfileRequest;

        if (($problem = $this->invalid($merged, Arr::only($request->rules(), $fields), $request->messages())) !== null) {
            return ToolResult::error('Updated the company profile', $problem);
        }

        CompanyProfileWriter::save($organization, $changes, ' via assistant');

        $said = array_map(
            fn (string $field): string => self::EDITABLE[$field].($changes[$field] === null ? ' cleared' : ''),
            array_keys($changes),
        );

        return ToolResult::ok('Updated the company profile', Str::ucfirst(implode(', ', $said)).'.', $this->card(
            kind: 'edit',
            tone: 'info',
            badge: 'Updated',
            title: $organization->name,
            subtitle: 'Company profile',
            meta: array_map(
                fn (string $field): string => Str::ucfirst(self::EDITABLE[$field]).': '.($changes[$field] ?? 'cleared'),
                array_keys($changes),
            ),
        ));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setTimezone(User $user, Organization $organization, array $args): ToolResult
    {
        [$zone, $error] = $this->zone((string) ($args['timezone'] ?? ''));

        if ($zone === null) {
            return ToolResult::error('Changed the time zone', $error);
        }

        $previous = $organization->timezone;

        if ($zone === $previous) {
            return ToolResult::error('Changed the time zone', "The company is already on {$zone}.");
        }

        $request = new UpdateCompanyProfileRequest;

        if (($problem = $this->invalid(['timezone' => $zone], Arr::only($request->rules(), ['timezone']), $request->messages())) !== null) {
            return ToolResult::error('Changed the time zone', $problem);
        }

        CompanyProfileWriter::save($organization, ['timezone' => $zone], ' via assistant');

        return ToolResult::ok(
            "Changed the time zone to {$zone}",
            "From now on \"today\", lateness and every time shown follow {$zone}; days already recorded keep their times.",
            $this->card(
                kind: 'edit',
                tone: 'info',
                badge: 'Time zone',
                title: $zone,
                subtitle: 'Was '.$previous,
                meta: ['It is '.CarbonImmutable::now($zone)->format('D, M j g:i A').' there now'],
            ),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The profile in lines — the registered name first. Statutory numbers are
     * said to be on file or missing; the join code is never read.
     *
     * @return list<string>
     */
    private function profileLines(User $user, Organization $organization): array
    {
        $zone = $organization->timezone ?: OrganizationClock::DEFAULT_TIMEZONE;
        $onFile = array_values(array_filter(self::STATUTORY, fn (string $label, string $column): bool => filled($organization->{$column}), ARRAY_FILTER_USE_BOTH));
        $missing = array_values(array_diff(self::STATUTORY, $onFile));

        return array_values(array_filter([
            filled($organization->legal_name) ? 'Registered as '.$organization->legal_name : 'No registered legal name on file',
            'Email: '.($organization->email ?: 'not set'),
            'Phone: '.($organization->phone ?: 'not set'),
            'Address: '.($organization->address ? Str::limit((string) $organization->address, 200) : 'not set'),
            'Time zone: '.$zone.' — it is '.CarbonImmutable::now($zone)->format('D, M j g:i A').' there now',
            'Statutory employer numbers on file: '.($onFile === [] ? 'none' : implode(', ', $onFile)).($missing !== [] ? '; missing: '.implode(', ', $missing) : ''),
            $organization->logo ? 'Logo: on file' : 'Logo: none',
            // Only to those who manage it, as on the screen (ADR 0026).
            $user->can('setup.company.manage')
                ? 'Joining by company code: '.($organization->join_code_enabled ? 'on' : 'off')
                : null,
        ]));
    }

    /**
     * One of PHP's canonical zones for what somebody typed — the identifier
     * itself ("Asia/Manila"), or its city ("manila", "New York") when exactly one
     * zone has it. An offset is refused: "UTC+8" does not say which
     * daylight-saving rules apply.
     *
     * @return array{0: string|null, 1: string}
     */
    private function zone(string $typed): array
    {
        $typed = trim($typed);

        if ($typed === '') {
            return [null, 'Say which time zone, e.g. Asia/Manila.'];
        }

        if (preg_match('/^(utc|gmt)?\s*[+\-−]\s*\d/iu', $typed) === 1) {
            return [null, 'Name the zone by its region and city, e.g. Asia/Singapore — an offset alone does not say which daylight-saving rules apply.'];
        }

        $identifiers = OrganizationClock::identifiers();
        $wanted = Str::lower(str_replace(' ', '_', $typed));

        foreach ($identifiers as $identifier) {
            if (Str::lower($identifier) === $wanted) {
                return [$identifier, ''];
            }
        }

        $byCity = array_values(array_filter(
            $identifiers,
            fn (string $identifier): bool => Str::lower((string) Str::afterLast($identifier, '/')) === $wanted,
        ));

        return match (count($byCity)) {
            1 => [$byCity[0], ''],
            0 => [null, '“'.Str::limit($typed, 60).'” is not a time zone on the list. Use its region and city, e.g. Asia/Manila.'],
            default => [null, 'More than one zone matches “'.Str::limit($typed, 60).'”: '.implode(', ', $byCity).'.'],
        };
    }
}
