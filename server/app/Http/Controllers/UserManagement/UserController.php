<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\StoreUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Queries\UsersIndexQuery;
use App\Queries\UserStatistics;
use App\Support\Roles\GrantRules;
use App\Support\Roles\RoleException;
use App\Support\Users\UserAccountException;
use App\Support\Users\UserAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Display the user management listing.
     */
    public function index(Request $request, UsersIndexQuery $query, UserStatistics $statistics): Response
    {
        [$sort, $direction] = $query->sort($request);

        $canAssignRoles = $request->user()->can('roles.assign');

        // Every role, ordered consistently — used for the role filter (any viewer)
        // and the bulk "assign role" picker / create-edit form (assigners only).
        $roles = Role::orderByDesc('is_system')->orderBy('label')->get(['id', 'name', 'label']);

        return Inertia::render('system/users/index', [
            'users' => UserResource::collection($query->paginate($request)),
            'stats' => $statistics->toArray(),
            'roles' => $roles,
            // Every role stays listed — one the assigner could not give is shown
            // locked (ADR 0057), so saving the form never drops it silently.
            'assignableRoles' => $canAssignRoles
                ? $roles->map(fn (Role $role): array => [...$role->only(['id', 'name', 'label']), 'givable' => GrantRules::canGive($request->user(), $role)])->values()
                : [],
            'can' => [
                'create' => $request->user()->can('users.create'),
                'update' => $request->user()->can('users.update'),
                'delete' => $request->user()->can('users.delete'),
                'restore' => $request->user()->can('users.restore'),
                'forceDelete' => $request->user()->can('users.force-delete'),
                'manageStatus' => $request->user()->can('users.manage-status'),
                'resetPassword' => $request->user()->can('users.reset-password'),
                'export' => $request->user()->can('users.export'),
                'assignRoles' => $canAssignRoles,
            ],
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request),
                'role' => $query->role($request),
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $query->perPage($request),
            ],
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request, UserAccounts $accounts): RedirectResponse
    {
        try {
            $result = $accounts->create(
                Arr::except($request->validated(), ['photo', 'roles']),
                $this->roleIds($request),
                $request->user(),
                $request->file('photo'),
            );
        } catch (UserAccountException|RoleException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        if ($result['linked']) {
            return $this->respond("{$result['user']->email} already had an account and was added to this organisation.");
        }

        return $result['verification_sent']
            ? $this->respond("User created. A verification email was sent to {$result['user']->email}.")
            : $this->respond('User created, but the verification email could not be sent — you can resend it from the user’s actions.', 'warning');
    }

    /**
     * Update the given user.
     */
    public function update(UpdateUserRequest $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        try {
            $result = $accounts->update(
                $user,
                Arr::except($request->validated(), ['photo', 'remove_photo', 'roles']),
                $this->roleIds($request),
                $request->user(),
                $request->file('photo'),
                $request->boolean('remove_photo'),
            );
        } catch (UserAccountException|RoleException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond($result['email_changed']
            ? 'User updated. A verification email was sent to the new address.'
            : 'User updated.');
    }

    /**
     * Resend the email-verification (confirmation) link to an unverified user.
     */
    public function resendVerification(User $user, UserAccounts $accounts): RedirectResponse
    {
        try {
            $accounts->resendVerification($user);
        } catch (UserAccountException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond("Verification email sent to {$user->email}.");
    }

    /**
     * Archive (soft delete) the given user — or, when their account belongs to
     * another workspace too, remove them from this one.
     */
    public function destroy(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        try {
            $outcome = $accounts->archive($user, $request->user());
        } catch (UserAccountException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond($outcome === 'removed'
            ? "{$user->full_name} was removed from this organisation. Their account belongs to another workspace too, so it stays."
            : 'User archived.');
    }

    /**
     * Restore a previously archived user.
     */
    public function restore(int $user, UserAccounts $accounts): RedirectResponse
    {
        $model = UserAccounts::findArchived($user);
        abort_if($model === null, 404);

        $accounts->restore($model);

        return $this->respond('User restored.');
    }

    /**
     * Permanently delete a user.
     */
    public function forceDelete(Request $request, int $user, UserAccounts $accounts): RedirectResponse
    {
        $model = User::withTrashed()->inCurrentOrganization()->findOrFail($user);

        try {
            $accounts->forceDelete($model, $request->user());
        } catch (UserAccountException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('User permanently deleted.');
    }

    /**
     * The roles the form sets, or null to leave them alone.
     *
     * Role assignment is ignored when the actor lacks `roles.assign`, so a
     * tampered payload cannot escalate access; the `manage_roles` flag is set by
     * the form whenever the role picker is shown, so an empty selection can
     * intentionally clear all roles. Which roles may change hands is
     * {@see GrantRules}'s to say.
     *
     * @return list<int>|null
     */
    private function roleIds(Request $request): ?array
    {
        if (! $request->user()->can('roles.assign') || ! $request->boolean('manage_roles')) {
            return null;
        }

        return array_map('intval', $request->validated('roles') ?? []);
    }

    /**
     * Flash a toast and bounce back to the listing.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
