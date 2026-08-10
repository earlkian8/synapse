<?php

namespace App\Support;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * `exists` and `unique` rules that respect the tenant boundary.
 *
 * Validation rules run as raw queries against the table — they do not go through
 * Eloquent, so {@see OrganizationScope} never sees them. A
 * bare `Rule::exists('departments', 'id')` therefore answers for *every*
 * organisation on the instance, which costs two things:
 *
 * - **Integrity.** A foreign key can be pointed at another tenant's row. The
 *   read side hides it (the relation is scoped, so it resolves to null), which
 *   makes it a silent corruption rather than a visible one.
 * - **Disclosure.** Pass/fail is an oracle. Submitting ids in a loop tells you
 *   which ones exist somewhere on the box, and a global `unique` on a column
 *   like `employee_no` tells you a value is taken by an organisation you cannot
 *   otherwise see.
 *
 * These helpers pin both to the current organisation. When no tenant is bound
 * they fall through to the unscoped rule, exactly as the global scope does, so
 * console and seeding paths behave as before.
 */
final class TenantRule
{
    /**
     * `exists`, confined to the current organisation's rows.
     */
    public static function exists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        return self::confine($rule);
    }

    /**
     * `unique`, confined to the current organisation's rows — so two tenants can
     * hold the same employee number without colliding.
     */
    public static function unique(string $table, string $column): Unique
    {
        $rule = Rule::unique($table, $column);

        return self::confine($rule);
    }

    /**
     * @template T of Exists|Unique
     *
     * @param  T  $rule
     * @return T
     */
    private static function confine(Exists|Unique $rule): Exists|Unique
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->check()) {
            $rule->where('organization_id', $tenancy->id());
        }

        return $rule;
    }
}
