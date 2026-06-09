<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\StoreUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Queries\UsersIndexQuery;
use App\Queries\UserStatistics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['password_changed_at'] = now();
        }

        User::create($data);

        return $this->respond('User created.');
    }

    /**
     * Update the given user.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

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

        return $this->respond('User archived.');
    }

    /**
     * Restore a previously archived user.
     */
    public function restore(int $user): RedirectResponse
    {
        User::onlyTrashed()->findOrFail($user)->restore();

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

        $model->forceDelete();

        return $this->respond('User permanently deleted.');
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
