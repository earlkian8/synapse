<?php

namespace App\Models;

use App\Support\Admin\AdminAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing an admin did in the portal, or tried to (ADR 0085).
 *
 * Append-only: rows are written by {@see AdminAudit} and nothing in the portal
 * edits or deletes them. Not tenant-scoped — the portal stands above every
 * organisation.
 */
class AdminActivityLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * Every event the portal records, and how the log names it.
     *
     * @var array<string, string>
     */
    public const EVENTS = [
        'sign_in' => 'Signed in',
        'sign_in_failed' => 'Sign-in failed',
        'sign_out' => 'Signed out',
        'two_factor_enrolled' => 'Authenticator set up',
        'two_factor_failed' => 'Wrong authenticator code',
        'password_changed' => 'Password changed',
        'sessions_revoked' => 'Other sessions ended',
        'admin_created' => 'Admin created',
        'admin_updated' => 'Admin updated',
        'admin_activated' => 'Admin activated',
        'admin_deactivated' => 'Admin deactivated',
        'admin_password_reset' => 'Password reset',
        'admin_two_factor_reset' => 'Authenticator reset',
        'admin_deleted' => 'Admin deleted',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'admin_id',
        'actor_name',
        'actor_email',
        'event',
        'description',
        'subject_type',
        'subject_id',
        'subject_label',
        'properties',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? ucfirst(str_replace('_', ' ', $this->event));
    }

    /**
     * Scope to a free-text search over the description, actor, target and IP.
     *
     * @param  Builder<AdminActivityLog>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $needle = '%'.$term.'%';
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query->where(function (Builder $query) use ($needle, $like) {
            foreach (['description', 'actor_name', 'actor_email', 'subject_label', 'ip_address'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
