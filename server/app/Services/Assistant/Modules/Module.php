<?php

namespace App\Services\Assistant\Modules;

use App\Models\Employee;
use App\Models\User;
use App\Services\Assistant\Contracts\AssistantModule;
use App\Services\Assistant\Security\UntrustedText;
use App\Services\Assistant\ToolResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the concrete assistant modules: permission-scoped tool
 * exposure, case-insensitive name → id resolution, a uniform "permission denied"
 * result, and the result-card builder the chat UI renders.
 */
abstract class Module implements AssistantModule
{
    public function handles(string $tool): bool
    {
        return array_key_exists($tool, $this->toolMap());
    }

    /**
     * Map of tool name => handler method on the concrete module.
     *
     * @return array<string, string>
     */
    abstract protected function toolMap(): array;

    /**
     * Tools that only read although their name does not say so (a ranking, a
     * profile read-out). Everything named `find_`, `get_`, `list_`, `count_` or
     * `…_summary` is a read already.
     *
     * @return list<string>
     */
    protected function readTools(): array
    {
        return [];
    }

    /**
     * Tools that never run without the user pressing Confirm in the chat.
     *
     * @return list<string>
     */
    protected function confirmTools(): array
    {
        return [];
    }

    public function isReadOnly(string $tool): bool
    {
        return Str::startsWith($tool, ['find_', 'get_', 'list_', 'count_'])
            || Str::endsWith($tool, '_summary')
            || in_array($tool, $this->readTools(), true);
    }

    public function requiresConfirmation(string $tool): bool
    {
        return in_array($tool, $this->confirmTools(), true);
    }

    /**
     * Map of tool name => the permission required to run it. Tools absent from
     * the map need nothing beyond the module's own {@see isAvailable()} check.
     *
     * @return array<string, string>
     */
    protected function permissionMap(): array
    {
        return [];
    }

    /**
     * Drop the declarations this user could never run, so the model is offered
     * exactly the actions their role allows — fewer wasted tokens, no tool calls
     * that would only come back denied. The runtime check in each handler stays:
     * this narrows what is *offered*, it does not replace what is *enforced*.
     *
     * @param  array<int, array<string, mixed>>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function permitted(User $user, array $tools): array
    {
        $permissions = $this->permissionMap();

        return array_values(array_filter($tools, function (array $tool) use ($user, $permissions): bool {
            $permission = $permissions[$tool['name'] ?? ''] ?? null;

            return $permission === null || $user->can($permission);
        }));
    }

    /**
     * Whether this user holds every one of the given permissions — for guidance
     * fragments that only make sense when a capability is actually available.
     *
     * @param  list<string>  $permissions
     */
    protected function allows(User $user, string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->cannot($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list of record names for a guidance fragment — "Leave types: …",
     * "Review cycles: …".
     *
     * Guidance is the one place record text reaches the system instruction
     * itself rather than the fenced data block, so every name is cleaned on the
     * way in ({@see UntrustedText}): a department or a leave type named with a
     * line break and a fake rule stays one inert item in a list.
     *
     * @param  iterable<mixed>  $names
     */
    protected function catalog(iterable $names, string $glue = ', ', int $max = 40): string
    {
        $clean = [];

        foreach ($names as $name) {
            $text = UntrustedText::clean(is_scalar($name) ? (string) $name : null, 80);

            if ($text !== null) {
                $clean[] = $text;
            }

            if (count($clean) >= $max) {
                break;
            }
        }

        return $clean === [] ? 'none' : implode($glue, $clean);
    }

    /**
     * A calendar date the model passed, as "Y-m-d" — or null when it is not
     * one. Only ISO dates are taken: "next Friday" is the model's to resolve
     * against today's date, and a guess here would be a silent one.
     */
    protected function isoDate(mixed $value): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * Check values against a screen's own validation rules, so what the form
     * would refuse is refused here too, in the same words. The first problem,
     * or null when there is none.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    protected function invalid(array $data, array $rules): ?string
    {
        $validator = Validator::make($data, $rules);

        return $validator->fails() ? (string) $validator->errors()->first() : null;
    }

    /**
     * Apply a token-aware search to a query whose model has a `search` scope, so
     * a multi-word name like "Jane Doe" matches first_name *and* last_name rather
     * than failing because no single column contains the whole string. Each token
     * must match some searchable column (the scope's per-column LIKE, ANDed).
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function matchByTokens(Builder $query, ?string $needle): Builder
    {
        $tokens = preg_split('/\s+/', trim((string) $needle)) ?: [];

        foreach ($tokens as $token) {
            if ($token !== '') {
                $query->search($token);
            }
        }

        return $query;
    }

    /**
     * Exactly one employee for a name or employee number, or why not.
     *
     * An employee number is exact. A name must pick out one person: when several
     * match the words, the one whose name is exactly what was typed wins, and
     * otherwise nobody does — nothing is done to "the first Maria".
     *
     * @return array{0: Employee|null, 1: string}
     */
    protected function resolveEmployee(string $needle, string $missing = 'Say who.'): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, $missing];
        }

        $byNumber = Employee::query()->whereRaw('lower(employee_no) = ?', [Str::lower($needle)])->first();

        if ($byNumber !== null) {
            return [$byNumber, ''];
        }

        $matches = $this->matchByTokens(Employee::query(), $needle)->limit(10)->get();

        if ($matches->count() > 1) {
            $typed = Str::lower(preg_replace('/\s+/', ' ', $needle) ?? $needle);
            $exact = $matches->filter(fn (Employee $e): bool => in_array($typed, [
                Str::lower($e->full_name),
                Str::lower(trim($e->first_name.' '.$e->last_name)),
            ], true));

            if ($exact->count() === 1) {
                return [$exact->first(), ''];
            }
        }

        return match ($matches->count()) {
            0 => [null, 'No matching employee found.'],
            1 => [$matches->first(), ''],
            default => [null, 'More than one person matches “'.Str::limit($needle, 60).'”. Use their full name or employee number.'],
        };
    }

    /**
     * Exactly one active user *of this workspace* for a name, or why not — for
     * the people work is assigned to (a task's owner, an interviewer).
     *
     * Users are identities shared across workspaces and carry no tenant scope of
     * their own, so an unscoped lookup would find — and notify — somebody in
     * another company, and would tell the asker which names exist there.
     *
     * @return array{0: User|null, 1: string}
     */
    protected function resolveMember(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            return [null, 'Say who.'];
        }

        $matches = $this->matchByTokens(
            User::query()->where('is_active', true)->inCurrentOrganization(),
            $name,
        )->limit(10)->get();

        if ($matches->count() > 1) {
            $typed = Str::lower(preg_replace('/\s+/', ' ', $name) ?? $name);
            $exact = $matches->filter(fn (User $u): bool => in_array($typed, [
                Str::lower((string) $u->full_name),
                Str::lower(trim($u->first_name.' '.$u->last_name)),
                Str::lower((string) $u->email),
            ], true));

            if ($exact->count() === 1) {
                return [$exact->first(), ''];
            }
        }

        return match ($matches->count()) {
            0 => [null, 'No active user in this workspace is called “'.Str::limit($name, 60).'”.'],
            1 => [$matches->first(), ''],
            default => [null, 'More than one user matches “'.Str::limit($name, 60).'”. Use their full name or email.'],
        };
    }

    /**
     * Every name in a list resolved to exactly one employee — or the first one
     * that is not, and nobody. A list is acted on whole or not at all, so a
     * typo never quietly leaves somebody out.
     *
     * @param  array<int, mixed>  $names
     * @return array{0: Collection<int, Employee>|null, 1: string}
     */
    protected function resolveEmployees(array $names, int $max): array
    {
        $names = collect($names)
            ->map(fn (mixed $name): string => trim(is_scalar($name) ? (string) $name : ''))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique(fn (string $name): string => Str::lower($name))
            ->values();

        if ($names->isEmpty()) {
            return [null, 'Say who.'];
        }

        if ($names->count() > $max) {
            return [null, "At most {$max} people at a time here — use the screen for more."];
        }

        $employees = collect();

        foreach ($names as $name) {
            [$employee, $error] = $this->resolveEmployee($name);

            if ($employee === null) {
                return [null, '“'.Str::limit($name, 60).'”: '.$error];
            }

            $employees->put($employee->id, $employee);
        }

        return [$employees->values(), ''];
    }

    /**
     * Resolve a row id by a case-insensitive match on one column.
     *
     * @param  Builder<*>  $query
     */
    protected function resolveId(Builder $query, string $column, ?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $id = $query->whereRaw('lower('.$column.') = ?', [Str::lower($value)])->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * A standard "not permitted" outcome.
     */
    protected function denied(string $action): ToolResult
    {
        return ToolResult::error('Permission check', "You don't have permission to {$action}.");
    }

    /**
     * Build a result card for the chat timeline. `kind` and `tone` drive the icon
     * and colour on the frontend; everything else is display text.
     *
     * @param  list<string>  $meta
     * @param  array{name: string, initials: string, photo: ?string}|null  $avatar
     */
    protected function card(
        string $kind,
        string $tone,
        string $badge,
        string $title,
        ?string $subtitle = null,
        array $meta = [],
        ?array $avatar = null,
        int|string|null $id = null,
    ): array {
        return [
            'module' => $this->key(),
            'kind' => $kind,
            'tone' => $tone,
            'badge' => $badge,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => array_values(array_filter($meta, fn ($m): bool => filled($m))),
            'avatar' => $avatar,
            'id' => $id,
        ];
    }

    /**
     * Pull the first non-empty value among the given keys from an args array.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $keys
     */
    protected function firstFilled(array $args, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($args[$key] ?? null)) {
                return (string) $args[$key];
            }
        }

        return null;
    }
}
