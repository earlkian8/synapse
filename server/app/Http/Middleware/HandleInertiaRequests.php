<?php

namespace App\Http\Middleware;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'organization' => $user?->organization
                    ? ['id' => $user->organization->id, 'name' => $user->organization->name]
                    : null,
                'roles' => $user ? $user->roles->pluck('name')->all() : [],
                'permissions' => $user ? $user->permissionNames()->all() : [],
                'is_super_admin' => $user?->isSuperAdmin() ?? false,
            ],
            'notifications' => $user ? [
                'items' => NotificationResource::collection(
                    $user->notifications()->latest()->limit(8)->get()
                )->resolve($request),
                'unread' => $user->unreadNotifications()->count(),
            ] : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
