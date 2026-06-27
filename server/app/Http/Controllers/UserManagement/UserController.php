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
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
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
            'assignableRoles' => $canAssignRoles ? $roles : [],
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
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = new User(Arr::except($request->validated(), [
            'password', 'photo', 'roles',
        ]));

        if (filled($request->validated('password'))) {
            $user->password = $request->validated('password');
            $user->password_changed_at = now();
        }

        if ($request->hasFile('photo')) {
            $user->profile_photo = $request->file('photo')->store('profile-photos', 'public');
        }

        $user->save();

        $this->syncRoles($request, $user);

        // New accounts start unverified — email a confirmation link so the holder
        // proves ownership of the address before they can sign in.
        $sent = $this->sendVerification($user);

        // Auto-notify the new account holder (in-app + email/push if enabled).
        Notifier::toUser(
            $user,
            'Welcome to SYNAPSE',
            'Your account has been created. Please check your inbox to verify your email address, then sign in to get started.',
            url: '/dashboard',
            level: 'success',
            category: 'account',
            actor: $request->user(),
        );

        ActivityLogger::log(
            event: 'created',
            description: "Created user {$user->full_name}",
            subject: $user,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return $sent
            ? $this->respond("User created. A verification email was sent to {$user->email}.")
            : $this->respond('User created, but the verification email could not be sent — you can resend it from the user’s actions.', 'warning');
    }

    /**
     * Update the given user.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill(Arr::except($request->validated(), [
            'photo', 'remove_photo', 'roles',
        ]));

        // A changed email must be re-confirmed: drop the verified state now and
        // send a fresh confirmation link to the new address after saving.
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if ($request->boolean('remove_photo')) {
            $this->deletePhoto($user);
        }

        if ($request->hasFile('photo')) {
            $this->deletePhoto($user);
            $user->profile_photo = $request->file('photo')->store('profile-photos', 'public');
        }

        $changed = array_values(array_diff(array_keys($user->getDirty()), ['updated_at']));

        $user->save();

        $changes = $this->syncRoles($request, $user);

        if ($emailChanged) {
            $this->sendVerification($user);
        }

        // Let the user know when they've been granted new role(s).
        if (! empty($changes['attached'])) {
            $roles = Role::whereIn('id', $changes['attached'])->pluck('label')->implode(', ');

            Notifier::toUser(
                $user,
                'Your access has changed',
                "You've been granted the following role(s): {$roles}.",
                url: '/dashboard',
                level: 'info',
                category: 'account',
                actor: $request->user(),
            );
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Updated user {$user->full_name}",
            subject: $user,
            properties: $changed !== [] ? ['changed' => $changed] : [],
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return $this->respond($emailChanged
            ? 'User updated. A verification email was sent to the new address.'
            : 'User updated.');
    }

    /**
     * Resend the email-verification (confirmation) link to an unverified user.
     */
    public function resendVerification(Request $request, User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return $this->respond('This email address is already verified.', 'error');
        }

        if (! $this->sendVerification($user)) {
            return $this->respond('The verification email could not be sent. Please try again.', 'error');
        }

        ActivityLogger::log(
            event: 'verification_sent',
            description: "Resent the email verification link to {$user->full_name}",
            subject: $user,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return $this->respond("Verification email sent to {$user->email}.");
    }

    /**
     * Archive (soft delete) the given user.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return $this->respond('You cannot archive your own account.', 'error');
        }

        $user->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived user {$user->full_name}",
            subject: $user,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return $this->respond('User archived.');
    }

    /**
     * Restore a previously archived user.
     */
    public function restore(int $user): RedirectResponse
    {
        $model = User::onlyTrashed()->findOrFail($user);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored user {$model->full_name}",
            subject: $model,
            logName: 'user_management',
            subjectLabel: $model->full_name,
        );

        return $this->respond('User restored.');
    }

    /**
     * Permanently delete a user.
     */
    public function forceDelete(Request $request, int $user): RedirectResponse
    {
        $model = User::withTrashed()->findOrFail($user);

        if ($model->is($request->user())) {
            return $this->respond('You cannot delete your own account.', 'error');
        }

        $label = $model->full_name;

        $this->deletePhoto($model);
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted user {$label}",
            logName: 'user_management',
            subjectLabel: $label,
        );

        return $this->respond('User permanently deleted.');
    }

    /**
     * Sync the user's role assignments, but only if the actor is permitted to.
     *
     * The role assignment is silently ignored when the actor lacks the
     * `roles.assign` permission, so a tampered payload cannot escalate access.
     *
     * @return array{attached?: list<int>, detached?: list<int>, updated?: list<int>}
     */
    private function syncRoles(Request $request, User $user): array
    {
        // The `manage_roles` flag is set by the form whenever the role picker is
        // shown, so an empty selection can intentionally clear all roles.
        if (! $request->user()->can('roles.assign') || ! $request->boolean('manage_roles')) {
            return [];
        }

        return $user->roles()->sync($request->validated('roles') ?? []);
    }

    /**
     * Email the verification (confirmation) link to a user.
     *
     * Sent synchronously so delivery doesn't depend on a running queue worker —
     * with verification enforced, a silently-dropped email would lock the user
     * out. Transport failures are swallowed (and reported) so a mail outage
     * never breaks the create/update itself. Returns whether it was dispatched.
     */
    private function sendVerification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Remove a user's stored profile photo from disk, if any.
     */
    private function deletePhoto(User $user): void
    {
        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
            $user->profile_photo = null;
        }
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
