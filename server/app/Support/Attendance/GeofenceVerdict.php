<?php

namespace App\Support\Attendance;

use App\Models\WorkLocation;

/**
 * Where a punch was, as {@see GeofenceCheck} judged it: the site that matters,
 * how far from it, and whether it counted as on site.
 */
final readonly class GeofenceVerdict
{
    public function __construct(
        public WorkLocation $location,
        public int $distanceMeters,
        public bool $inside,
    ) {}
}
