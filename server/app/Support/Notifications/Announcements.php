<?php

namespace App\Support\Notifications;

use App\Http\Requests\Notification\SendNotificationRequest;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;

/**
 * Sending an announcement to people in this workspace — one member, everyone
 * holding a role, or everyone (ADR 0059).
 *
 * The notification centre's compose form and the assistant both come through
 * here, so a message reaches the same people and is recorded the same way
 * whoever wrote it. Validation is {@see SendNotificationRequest}, which runs
 * first: recipients are members of this workspace, and a link is a path inside
 * the app — never an address elsewhere, which a message would otherwise carry
 * to every recipient as a phishing link.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class Announcements
{
    /**
     * Send it, and return how many people it reached.
     *
     * @param  'all'|'role'|'user'  $audience
     */
    public function send(string $audience, ?User $recipient, ?Role $role, string $title, string $body, ?string $url, string $level, User $actor, string $channel = ''): int
    {
        $count = match ($audience) {
            'user' => $recipient === null ? 0 : Notifier::toUser($recipient, $title, $body, $url, $level, 'announcement', $actor),
            'role' => $role === null ? 0 : Notifier::toRole($role, $title, $body, $url, $level, 'announcement', $actor),
            default => Notifier::toAll($title, $body, $url, $level, 'announcement', $actor),
        };

        ActivityLogger::log(
            event: 'sent',
            description: "Sent a notification to {$count} ".($count === 1 ? 'recipient' : 'recipients').$channel,
            properties: ['audience' => $audience, 'title' => $title, 'count' => $count],
            logName: 'notifications',
            subjectLabel: $title,
        );

        return $count;
    }
}
