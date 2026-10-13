<?php

namespace App\Support\Admin;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The portal's API sends every moment as ISO 8601 and lets the browser format it.
 * Pivot columns and aggregates arrive as strings; this reads either.
 */
class Dates
{
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value))->toIso8601String();
    }
}
