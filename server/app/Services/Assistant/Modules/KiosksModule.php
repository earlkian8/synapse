<?php

namespace App\Services\Assistant\Modules;

use App\Models\AttendanceKiosk;
use App\Models\AttendancePunch;
use App\Models\EmployeeKioskPin;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\KioskWorkflow;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Kiosks capability (ADR 0092): the shared devices at a site where people who
 * have no phone clock in, and who holds a kiosk PIN.
 *
 * **Reading** answers "which kiosks are on line?", "who clocks in at a kiosk?",
 * "is Juan's PIN locked?". **Doing** signs a kiosk's device out and withdraws
 * somebody's PIN, through {@see KioskWorkflow} — the screen's own path — and
 * both wait for the user's Confirm (ADR 0049).
 *
 * Deliberately left to the screen, Attendance → Kiosks:
 *
 * - **Pairing codes and PINs.** Each is a secret shown once; in a chat it would
 *   sit in the conversation's history for as long as that is kept, readable by
 *   whoever opens it. The assistant says where to get one, and never sees one.
 * - **Adding a kiosk**, since it is only useful once a device is paired there.
 *
 * Everything needs `attendance.kiosks.manage`.
 */
class KiosksModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const PERMISSION = 'attendance.kiosks.manage';

    /** How many PIN holders a read-out spells out. */
    private const MAX_LISTED = 30;

    public function __construct(private readonly KioskWorkflow $workflow) {}

    public function key(): string
    {
        return 'kiosks';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can(self::PERMISSION);
    }

    protected function toolMap(): array
    {
        return [
            'find_kiosks' => 'findKiosks',
            'find_kiosk_pins' => 'findPins',
            'sign_out_kiosk' => 'signOut',
            'withdraw_kiosk_pin' => 'withdraw',
        ];
    }

    protected function permissionMap(): array
    {
        return array_fill_keys(array_keys($this->toolMap()), self::PERMISSION);
    }

    protected function confirmTools(): array
    {
        // Each stops people clocking in: a whole kiosk, or one person everywhere.
        return ['sign_out_kiosk', 'withdraw_kiosk_pin'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot(self::PERMISSION)) {
            return $this->denied('look after kiosks');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        KIOSKS — shared tablets at a site where people with no phone clock in with their employee number and a PIN (Attendance → Kiosks, /attendance/kiosks). A kiosk punch is placed at the kiosk's site and judged by the person's attendance policy like any other; a policy can leave kiosks out of its ways to punch.
        - find_kiosks lists kiosks: their site, whether a device is paired and on line, and today's punches. find_kiosk_pins lists who holds a PIN (one person by name or number, or by state: new — not yet replaced by their own; active; locked — too many wrong tries).
        - sign_out_kiosk signs a kiosk's device out; withdraw_kiosk_pin stops one person clocking in at any kiosk. Both wait for the user's confirmation.
        - Pairing codes and PINs are never given in chat: they are secrets shown once, on Attendance → Kiosks — "Pair a device" for a code, "Give PINs" for PINs (which also unlocks a locked one). Say so when asked for one, and do not offer a workaround. Adding a kiosk is done there too.
        TXT;
    }

    public function tools(User $user): array
    {
        return $this->permitted($user, [
            [
                'name' => 'find_kiosks',
                'description' => 'List attendance kiosks: site, paired device, on line or last seen, and punches today.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['query' => ['type' => 'STRING', 'description' => 'Part of the kiosk\'s name.']]],
            ],
            [
                'name' => 'find_kiosk_pins',
                'description' => 'List who holds a kiosk PIN and its state, or look up one person.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Name or employee number.'],
                        'status' => ['type' => 'STRING', 'enum' => ['new', 'active', 'locked']],
                    ],
                ],
            ],
            [
                'name' => 'sign_out_kiosk',
                'description' => 'Sign a kiosk\'s device out: it stops clocking people in until a device is paired again.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['kiosk' => ['type' => 'STRING', 'description' => 'The kiosk, by name.']], 'required' => ['kiosk']],
            ],
            [
                'name' => 'withdraw_kiosk_pin',
                'description' => 'Withdraw somebody\'s kiosk PIN: they can no longer clock in at any kiosk.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['employee' => ['type' => 'STRING', 'description' => 'Name or employee number.']], 'required' => ['employee']],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['kiosk', 'kiosks', 'kiosk pin', 'bundy', 'bundy clock', 'time clock', 'shared tablet', 'no phone', 'without a phone', 'pairing code'];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot(self::PERMISSION) || ! app(Tenancy::class)->check()) {
            return null;
        }

        $kiosks = AttendanceKiosk::query()->with('location:id,name')->orderBy('name')->get();
        $pins = EmployeeKioskPin::query()->whereHas('employee')->get(['id', 'must_change', 'locked_until']);

        return ContextSection::of('Kiosks', [
            $kiosks->isEmpty()
                ? 'No kiosks have been added.'
                : 'Kiosks: '.$kiosks->map(fn (AttendanceKiosk $k): string => $k->name.' ('.$this->deviceWords($k).')')->implode('; ').'.',
            $pins->isEmpty()
                ? 'Nobody holds a kiosk PIN.'
                : $pins->count().' '.Str::plural('person', $pins->count()).' hold a kiosk PIN; '
                    .$pins->where('must_change', true)->count().' still on the PIN HR issued, '
                    .$pins->filter(fn (EmployeeKioskPin $p): bool => $p->isLocked())->count().' locked.',
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool === 'sign_out_kiosk') {
            [$kiosk] = $this->locate((string) ($args['kiosk'] ?? ''));

            if ($kiosk === null) {
                return null;
            }

            $today = $this->punchesToday($kiosk);

            return "“{$kiosk->name}” stops clocking anybody in at once, until a device is paired again on Attendance → Kiosks. It took {$today} ".Str::plural('punch', $today).' today; those stay as they are.';
        }

        if ($tool === 'withdraw_kiosk_pin') {
            [$pin] = $this->pinOf((string) ($args['employee'] ?? ''));

            return $pin !== null
                ? "{$pin->employee->full_name} can no longer clock in at any kiosk. Their punches so far stay as they are; HR can give them a new PIN."
                : null;
        }

        return null;
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findKiosks(User $user, array $args): ToolResult
    {
        $query = AttendanceKiosk::query()->with('location:id,name')->orderBy('name');
        $needle = trim((string) ($args['query'] ?? ''));

        if ($needle !== '') {
            $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where('name', $like, '%'.addcslashes($needle, '%_\\').'%');
        }

        $cards = $query->get()->map(fn (AttendanceKiosk $kiosk): array => $this->card(
            kind: 'find',
            tone: $kiosk->isOnline() ? 'positive' : 'neutral',
            badge: match ($kiosk->status()) {
                'paired' => $kiosk->isOnline() ? 'On line' : 'Paired',
                'pairing' => 'Waiting for its code',
                default => 'No device',
            },
            title: $kiosk->name,
            subtitle: $kiosk->location !== null ? "At {$kiosk->location->name}" : 'Not at a site',
            meta: [
                ucfirst($this->deviceWords($kiosk)),
                $this->punchesToday($kiosk).' '.Str::plural('punch', $this->punchesToday($kiosk)).' today',
            ],
            id: $kiosk->hashid,
        ))->all();

        return ToolResult::found('Listed kiosks', $cards === [] ? 'No kiosks have been added. They are added on Attendance → Kiosks.' : count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPins(User $user, array $args): ToolResult
    {
        if (filled($args['employee'] ?? null)) {
            [$pin, $error] = $this->pinOf((string) $args['employee']);

            return $pin === null
                ? ToolResult::error('Looked up the kiosk PIN', $error)
                : ToolResult::found("Looked up {$pin->employee->full_name}’s kiosk PIN", null, [$this->pinCard($pin)]);
        }

        $status = in_array($args['status'] ?? null, ['new', 'active', 'locked'], true) ? $args['status'] : null;

        $pins = EmployeeKioskPin::query()
            ->with('employee:id,first_name,middle_name,last_name,suffix,employee_no,photo')
            ->whereHas('employee')
            ->get()
            ->filter(fn (EmployeeKioskPin $pin): bool => $status === null || $pin->status() === $status)
            ->sortBy(fn (EmployeeKioskPin $pin): string => Str::lower($pin->employee->full_name))
            ->values();

        $cards = $pins->take(self::MAX_LISTED)->map(fn (EmployeeKioskPin $pin): array => $this->pinCard($pin))->all();

        return ToolResult::found(
            'Listed kiosk PINs',
            $pins->isEmpty()
                ? 'Nobody '.($status !== null ? "has a {$status} kiosk PIN" : 'holds a kiosk PIN').'.'
                : $pins->count().' found'.($pins->count() > self::MAX_LISTED ? ', the first '.self::MAX_LISTED.' shown' : ''),
            $cards,
        );
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function signOut(User $user, array $args): ToolResult
    {
        [$kiosk, $error] = $this->locate((string) ($args['kiosk'] ?? ''));

        if ($kiosk === null) {
            return ToolResult::error('Looked up the kiosk', $error);
        }

        if ($kiosk->status() !== 'paired') {
            return ToolResult::error('Signed the kiosk’s device out', "“{$kiosk->name}” has no device paired with it.");
        }

        $this->workflow->unpair($kiosk, ' via assistant');

        return ToolResult::ok(
            "Signed the device out of {$kiosk->name}",
            'Pair a device again on Attendance → Kiosks to bring it back.',
            $this->card(kind: 'cancel', tone: 'warning', badge: 'Signed out', title: $kiosk->name, subtitle: $kiosk->location?->name, id: $kiosk->hashid),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function withdraw(User $user, array $args): ToolResult
    {
        [$pin, $error] = $this->pinOf((string) ($args['employee'] ?? ''));

        if ($pin === null) {
            return ToolResult::error('Looked up the kiosk PIN', $error);
        }

        $card = $this->pinCard($pin);
        $this->workflow->withdrawPin($pin, ' via assistant');

        return ToolResult::ok(
            "Withdrew {$pin->employee->full_name}’s kiosk PIN",
            'They can be given a new one on Attendance → Kiosks.',
            [...$card, 'kind' => 'cancel', 'tone' => 'warning', 'badge' => 'Withdrawn'],
        );
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one kiosk by name — an exact name first, then a partial name only
     * one kiosk has.
     *
     * @return array{0: AttendanceKiosk|null, 1: string}
     */
    private function locate(string $needle): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which kiosk.'];
        }

        $matches = AttendanceKiosk::query()->with('location:id,name')->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $query = AttendanceKiosk::query()->with('location:id,name');
            $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $matches = $query->where('name', $like, '%'.addcslashes($needle, '%_\\').'%')->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, 'No kiosk matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one kiosk matches “'.Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * Somebody's kiosk PIN, or why there is none to speak of.
     *
     * @return array{0: EmployeeKioskPin|null, 1: string}
     */
    private function pinOf(string $needle): array
    {
        [$employee, $error] = $this->resolveEmployee($needle);

        if ($employee === null) {
            return [null, $error];
        }

        $pin = EmployeeKioskPin::query()->with('employee')->where('employee_id', $employee->id)->first();

        return $pin === null
            ? [null, "{$employee->full_name} has no kiosk PIN. One can be given on Attendance → Kiosks."]
            : [$pin, ''];
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    private function deviceWords(AttendanceKiosk $kiosk): string
    {
        return match ($kiosk->status()) {
            'paired' => ($kiosk->device_label ?? 'a device').($kiosk->isOnline() ? ', on line' : ', last seen '.($kiosk->last_seen_at?->diffForHumans() ?? 'never')),
            'pairing' => 'waiting for its pairing code',
            default => 'no device paired',
        };
    }

    private function punchesToday(AttendanceKiosk $kiosk): int
    {
        $start = CarbonImmutable::parse(OrganizationClock::today(), OrganizationClock::timezone())->utc();

        return AttendancePunch::query()->where('attendance_kiosk_id', $kiosk->id)->where('punched_at', '>=', $start)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function pinCard(EmployeeKioskPin $pin): array
    {
        $employee = $pin->employee;
        $zone = OrganizationClock::timezone();

        return $this->card(
            kind: 'find',
            tone: $pin->status() === 'locked' ? 'warning' : 'neutral',
            badge: match ($pin->status()) {
                'locked' => 'Locked',
                'new' => 'Not chosen yet',
                default => 'Their own',
            },
            title: $employee->full_name,
            subtitle: (string) $employee->employee_no,
            meta: [
                $pin->isLocked() ? 'Locked until '.OrganizationClock::local($pin->locked_until, $zone)->format('g:i A') : null,
                $pin->last_used_at !== null ? 'Last used '.$pin->last_used_at->diffForHumans() : 'Not used yet',
                $pin->issued_at !== null ? 'Issued '.OrganizationClock::local($pin->issued_at, $zone)->format('M j, Y') : null,
            ],
            avatar: ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
        );
    }
}
