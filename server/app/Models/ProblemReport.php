<?php

namespace App\Models;

use App\Support\ProblemReports\ProblemReports;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A problem somebody reported to the people who run SYNAPSE (ADR 0096), from
 * the ERP or the app, and how the platform admins followed it up.
 *
 * Not tenant-scoped: it is written to the platform, not to the company. The
 * reporter reads their own by `user_id`, in whichever workspace; the admin
 * portal reads them all. `organization_id` is the workspace the reporter was
 * in, kept for context. Filed through {@see ProblemReports}.
 */
class ProblemReport extends Model
{
    /**
     * What kind of problem, as the reporter picks it.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'not_working' => 'Something isn’t working',
        'looks_wrong' => 'Something looks wrong',
        'slow' => 'It’s slow or won’t load',
        'other' => 'Something else',
    ];

    /**
     * How much it gets in the way, as the reporter says.
     *
     * @var array<string, string>
     */
    public const IMPACTS = [
        'blocked' => 'Can’t get work done',
        'workaround' => 'Can work around it',
        'minor' => 'Minor',
    ];

    /**
     * Where a report stands, as the admins see it and as the reporter is told.
     *
     * @var array<string, array{admin: string, reporter: string}>
     */
    public const STATUSES = [
        'new' => ['admin' => 'New', 'reporter' => 'Received'],
        'investigating' => ['admin' => 'Investigating', 'reporter' => 'Being looked into'],
        'resolved' => ['admin' => 'Resolved', 'reporter' => 'Resolved'],
        'closed' => ['admin' => 'Closed', 'reporter' => 'Closed'],
    ];

    /** Still waiting on the admins. */
    public const OPEN = ['new', 'investigating'];

    /**
     * @var array<string, string>
     */
    public const PLATFORMS = [
        'web' => 'Web',
        'ios' => 'iPhone app',
        'android' => 'Android app',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'user_id',
        'organization_id',
        'category',
        'impact',
        'subject',
        'description',
        'platform',
        'page',
        'app_version',
        'device',
        'context',
        'status',
        'reply',
        'replied_at',
        'internal_note',
        'handled_by',
        'status_changed_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'replied_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * The workspace the reporter was in, archived or not.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'handled_by');
    }

    /**
     * @return HasMany<ProblemReportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ProblemReportAttachment::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? Str::headline($this->category);
    }

    public function impactLabel(): string
    {
        return self::IMPACTS[$this->impact] ?? Str::headline($this->impact);
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? Str::headline($this->platform);
    }

    /** @param  'admin'|'reporter'  $audience */
    public function statusLabel(string $audience): string
    {
        return self::STATUSES[$this->status][$audience] ?? Str::headline($this->status);
    }

    /**
     * Scope to the reports still waiting on the admins.
     *
     * @param  Builder<ProblemReport>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /**
     * Scope to a free-text search: the reference, the summary, the reporter's
     * name or email, or the workspace's name. Every word must match one of
     * them, so "Ana login" finds Ana's report about signing in.
     *
     * @param  Builder<ProblemReport>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $words = array_slice(preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);

        if ($words === []) {
            return;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        foreach ($words as $word) {
            $needle = '%'.addcslashes($word, '%_\\').'%';

            $query->where(function (Builder $query) use ($needle, $like) {
                $query->where('reference', $like, $needle)
                    ->orWhere('subject', $like, $needle)
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->where('first_name', $like, $needle)
                        ->orWhere('last_name', $like, $needle)
                        ->orWhere('email', $like, $needle))
                    ->orWhereHas('organization', fn (Builder $organization) => $organization
                        ->where('name', $like, $needle));
            });
        }
    }
}
