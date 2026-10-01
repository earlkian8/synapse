<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Record an activity log entry for the current request/user.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function log(
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        string $logName = 'system',
        ?string $subjectLabel = null,
    ): ActivityLog {
        $request = request();

        return ActivityLog::create([
            'log_name' => $logName,
            'event' => $event,
            'description' => $description,
            'causer_id' => auth()->id(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel,
            'properties' => $properties !== [] ? $properties : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
