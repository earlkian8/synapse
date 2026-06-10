<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * The single entry point for emitting notifications.
 *
 * Mirrors {@see ActivityLogger}: a thin static facade the rest of the app calls
 * without caring about channels. Resolves an audience (one user, a role, or
 * everyone) and fans a {@see SystemNotification} out to it. Returns the number
 * of recipients reached so callers can surface a confirmation.
 */
class Notifier
{
    /**
     * Notify a single user.
     */
    public static function toUser(
        User $user,
        string $title,
        string $body,
        ?string $url = null,
        string $level = 'info',
        string $category = 'general',
        ?User $actor = null,
    ): int {
        return self::deliver([$user], $title, $body, $url, $level, $category, $actor);
    }

    /**
     * Notify every (active) user assigned the given role.
     */
    public static function toRole(
        Role|string $role,
        string $title,
        string $body,
        ?string $url = null,
        string $level = 'info',
        string $category = 'general',
        ?User $actor = null,
    ): int {
        $role = $role instanceof Role
            ? $role
            : Role::where('name', $role)->first();

        if (! $role) {
            return 0;
        }

        $recipients = $role->users()->where('is_active', true)->get();

        return self::deliver($recipients, $title, $body, $url, $level, $category, $actor);
    }

    /**
     * Notify every active user in the system.
     */
    public static function toAll(
        string $title,
        string $body,
        ?string $url = null,
        string $level = 'info',
        string $category = 'general',
        ?User $actor = null,
    ): int {
        $recipients = User::query()->where('is_active', true)->get();

        return self::deliver($recipients, $title, $body, $url, $level, $category, $actor);
    }

    /**
     * Fan the notification out to a resolved set of recipients.
     *
     * @param  iterable<User>  $recipients
     */
    private static function deliver(
        iterable $recipients,
        string $title,
        string $body,
        ?string $url,
        string $level,
        string $category,
        ?User $actor,
    ): int {
        /** @var Collection<int, User> $recipients */
        $recipients = Collection::make($recipients)->filter()->values();

        if ($recipients->isEmpty()) {
            return 0;
        }

        Notification::send(
            $recipients,
            new SystemNotification($title, $body, $url, $level, $category, $actor?->full_name),
        );

        return $recipients->count();
    }
}
