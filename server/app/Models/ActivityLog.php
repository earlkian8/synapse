<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use BelongsToOrganization;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'log_name',
        'event',
        'description',
        'causer_id',
        'subject_type',
        'subject_id',
        'subject_label',
        'properties',
        'ip_address',
        'user_agent',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * The user who performed the action (kept even if archived).
     *
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id')->withTrashed();
    }

    /**
     * The model the action was performed on.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to a free-text search across the log and its causer.
     *
     * @param  Builder<ActivityLog>  $query
     */
    public function scopeSearch($query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function ($query) use ($needle, $like) {
            foreach (['description', 'event', 'ip_address', 'subject_label'] as $column) {
                $query->orWhere($column, $like, $needle);
            }

            $query->orWhereHas('causer', function ($causer) use ($needle, $like) {
                foreach (['first_name', 'last_name', 'email'] as $column) {
                    $causer->orWhere($column, $like, $needle);
                }
            });
        });
    }
}
