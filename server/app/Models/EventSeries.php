<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Events\Recurrence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The rule a repeating event follows (ADR 0070). Its occurrences are ordinary
 * {@see Event} rows that point here, made when the series is scheduled
 * ({@see Recurrence}); the rule is kept to describe them.
 */
class EventSeries extends Model
{
    use BelongsToOrganization;

    /** How often a series can repeat. */
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $table = 'event_series';

    protected $fillable = [
        'organization_id',
        'frequency',
        'interval',
        'weekdays',
        'until',
        'count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'interval' => 'integer',
            'weekdays' => 'array',
            'until' => 'date',
            'count' => 'integer',
        ];
    }

    /**
     * Every occurrence, archived ones included, soonest first.
     *
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'series_id')->withTrashed()->orderBy('starts_at');
    }

    /**
     * The rule in words: "Every week on Mon, Wed", "Every 2 days", "Every month".
     */
    public function summary(): string
    {
        $unit = ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$this->frequency] ?? 'week';
        $every = $this->interval > 1 ? "Every {$this->interval} {$unit}s" : "Every {$unit}";

        if ($this->frequency === 'weekly' && ! empty($this->weekdays)) {
            $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
            $days = collect($this->weekdays)->sort()->map(fn (int $day): string => $names[$day] ?? '')->filter()->implode(', ');
            $every .= " on {$days}";
        }

        return $every;
    }
}
