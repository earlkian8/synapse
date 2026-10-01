<?php

namespace App\Support\Trash;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The canonical catalogue of soft-deletable ("archivable") entities the Trash
 * Bin manages. Adding a new trashable model is a one-entry change here.
 *
 * Each entry binds a stable string key (used in URLs and the UI) to its model
 * and the *existing* per-module permissions — so the trash bin can never be used
 * to bypass RBAC: you only see types you can already view, and restore / delete
 * require the owning module's own permission.
 */
class TrashRegistry
{
    /**
     * @return array<string, array{
     *     model: class-string,
     *     label: string,
     *     plural: string,
     *     icon: string,
     *     view: string,
     *     restore: string,
     *     forceDelete: string,
     * }>
     */
    public static function types(): array
    {
        return [
            'user' => [
                'model' => User::class,
                'label' => 'User',
                'plural' => 'Users',
                'icon' => 'user-cog',
                'view' => 'users.view',
                'restore' => 'users.restore',
                'forceDelete' => 'users.force-delete',
            ],
            'employee' => [
                'model' => Employee::class,
                'label' => 'Employee',
                'plural' => 'Employees',
                'icon' => 'users',
                'view' => 'employees.view',
                'restore' => 'employees.restore',
                'forceDelete' => 'employees.force-delete',
            ],
            'department' => [
                'model' => Department::class,
                'label' => 'Department',
                'plural' => 'Departments',
                'icon' => 'building-2',
                'view' => 'setup.departments.view',
                'restore' => 'setup.departments.manage',
                'forceDelete' => 'setup.departments.manage',
            ],
            'leave_type' => [
                'model' => LeaveType::class,
                'label' => 'Leave type',
                'plural' => 'Leave types',
                'icon' => 'calendar-range',
                'view' => 'setup.leave-types.view',
                'restore' => 'setup.leave-types.manage',
                'forceDelete' => 'setup.leave-types.manage',
            ],
        ];
    }

    /**
     * Resolve a single type definition, or null when the key is unknown.
     *
     * @return array<string, mixed>|null
     */
    public static function definition(string $type): ?array
    {
        return self::types()[$type] ?? null;
    }

    /**
     * The archived records of a type in this workspace.
     *
     * Tenant-owned models are confined by their global scope. Users are not
     * tenant rows — an identity is shared across workspaces (ADR 0023) — so
     * without the membership filter the bin would list, count, restore and purge
     * the archived accounts of every company on the instance.
     *
     * @return Builder<Model>
     */
    public static function trashed(string $type): Builder
    {
        $model = self::types()[$type]['model'];
        $query = $model::onlyTrashed();

        return $model === User::class ? $query->inCurrentOrganization() : $query;
    }

    /**
     * One archived record of a type in this workspace, by id.
     */
    public static function find(string $type, int $id): ?Model
    {
        return self::definition($type) === null ? null : self::trashed($type)->find($id);
    }

    /**
     * Whether the actor may see the type and do the given thing to it —
     * "restore" or "forceDelete" — by the owning module's own permissions.
     */
    public static function allows(User $actor, string $type, string $ability): bool
    {
        $definition = self::definition($type);

        return $definition !== null && $actor->can($definition['view']) && $actor->can($definition[$ability]);
    }

    /**
     * The type keys the actor is allowed to at least view.
     *
     * @return list<string>
     */
    public static function viewableTypes(User $actor): array
    {
        return array_values(array_keys(array_filter(
            self::types(),
            fn (array $def): bool => $actor->can($def['view']),
        )));
    }
}
