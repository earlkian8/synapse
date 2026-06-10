<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\StoreUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Queries\UsersIndexQuery;
use App\Queries\UserStatistics;
use App\Support\ActivityLogger;
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

        return Inertia::render('system/users/index', [
            'users' => UserResource::collection($query->paginate($request)),
            'stats' => $statistics->toArray(),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request),
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
            'password', 'photo', 'email_verified',
        ]));

        if (filled($request->validated('password'))) {
            $user->password = $request->validated('password');
            $user->password_changed_at = now();
        }

        if ($request->boolean('email_verified')) {
            $user->email_verified_at = now();
        }

        if ($request->hasFile('photo')) {
            $user->profile_photo = $request->file('photo')->store('profile-photos', 'public');
        }

        $user->save();

        ActivityLogger::log(
            event: 'created',
            description: "Created user {$user->full_name}",
            subject: $user,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return $this->respond('User created.');
    }

    /**
     * Update the given user.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill(Arr::except($request->validated(), [
            'photo', 'remove_photo', 'email_verified',
        ]));

        // The admin's verification toggle is authoritative — it overrides the
        // usual "email changed, so re-verify" behaviour.
        $user->email_verified_at = $request->boolean('email_verified')
            ? ($user->email_verified_at ?? now())
            : null;

        if ($request->boolean('remove_photo')) {
            $this->deletePhoto($user);
        }

        if ($request->hasFile('photo')) {
            $this->deletePhoto($user);
            $user->profile_photo = $request->file('photo')->store('profile-photos', 'public');
        }

        $changed = array_values(array_diff(array_keys($user->getDirty()), ['updated_at']));

        $user->save();

        ActivityLogger::log(
            event: 'updated',
            description: "Updated user {$user->full_name}",
            subject: $user,
            properties: $changed !== [] ? ['changed' => $changed] : [],
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return $this->respond('User updated.');
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
