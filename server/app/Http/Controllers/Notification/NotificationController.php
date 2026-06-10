<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\SendNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * The personal notification centre.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filter = $request->string('filter')->toString();
        $filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';

        $query = $user->notifications()->getQuery();

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        $canSend = $user->can('notifications.send');

        return Inertia::render('notifications/index', [
            'notifications' => NotificationResource::collection(
                $query->paginate(15)->withQueryString()
            ),
            'stats' => [
                'total' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
            ],
            'preferences' => [
                'email' => (bool) $user->email_notifications,
                'push' => (bool) $user->push_notifications,
            ],
            'webPush' => [
                'vapidPublicKey' => config('webpush.vapid.public_key'),
                'subscribed' => $user->pushSubscriptions()->exists(),
            ],
            'canSend' => $canSend,
            'audiences' => $canSend ? $this->audiences() : null,
            'filters' => ['filter' => $filter],
        ]);
    }

    /**
     * Compose & broadcast a notification to a chosen audience.
     */
    public function store(SendNotificationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();

        $count = match ($data['audience']) {
            'user' => Notifier::toUser(
                User::findOrFail($data['user_id']),
                $data['title'], $data['body'], $data['url'] ?? null, $data['level'], 'announcement', $actor,
            ),
            'role' => Notifier::toRole(
                Role::findOrFail($data['role_id']),
                $data['title'], $data['body'], $data['url'] ?? null, $data['level'], 'announcement', $actor,
            ),
            default => Notifier::toAll(
                $data['title'], $data['body'], $data['url'] ?? null, $data['level'], 'announcement', $actor,
            ),
        };

        ActivityLogger::log(
            event: 'sent',
            description: "Sent a notification to {$count} ".($count === 1 ? 'recipient' : 'recipients'),
            properties: ['audience' => $data['audience'], 'title' => $data['title'], 'count' => $count],
            logName: 'notifications',
            subjectLabel: $data['title'],
        );

        return $this->respond(
            $count === 0
                ? 'No matching recipients — nothing was sent.'
                : "Notification sent to {$count} ".($count === 1 ? 'person' : 'people').'.',
            $count === 0 ? 'warning' : 'success',
        );
    }

    /**
     * Mark a single notification as read.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->where('id', $notification)->first()?->markAsRead();

        return back();
    }

    /**
     * Mark every notification as read.
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Delete a single notification.
     */
    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->where('id', $notification)->delete();

        return back();
    }

    /**
     * Clear the whole notification list.
     */
    public function clear(Request $request): RedirectResponse
    {
        $request->user()->notifications()->delete();

        return $this->respond('Notifications cleared.');
    }

    /**
     * The audience options for the compose form (roles + active users).
     *
     * @return array<string, mixed>
     */
    private function audiences(): array
    {
        return [
            'roles' => Role::orderBy('label')->get(['id', 'name', 'label']),
            'users' => User::query()
                ->where('is_active', true)
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'email'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->full_name,
                    'email' => $user->email,
                ])
                ->all(),
        ];
    }

    /**
     * Flash a toast and bounce back.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
