<?php

namespace App\Support\Admin;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the admin activity log (ADR 0085) — the portal's counterpart of
 * {@see ActivityLogger}, which records what ERP users do inside one
 * organisation.
 *
 * The actor is the signed-in admin unless one is given (a sign-in, which has no
 * session yet). Their name and email are copied onto the row so the trail reads
 * the same after the account is gone. A failed sign-in for an unknown address has
 * no actor; the address that was tried goes in `actorEmail`.
 */
class AdminAudit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $event,
        string $description,
        ?Admin $actor = null,
        ?Model $subject = null,
        array $properties = [],
        ?string $subjectLabel = null,
        ?string $actorEmail = null,
    ): AdminActivityLog {
        $actor ??= auth('admin')->user();
        $request = request();

        return AdminActivityLog::create([
            'admin_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email ?? $actorEmail,
            'event' => $event,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel,
            'properties' => $properties !== [] ? $properties : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null ? mb_substr($request->userAgent(), 0, 512) : null,
        ]);
    }
}
