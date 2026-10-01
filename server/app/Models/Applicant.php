<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'current_location',
        'headline',
        'linkedin_url',
        'portfolio_url',
        'years_experience',
        'source',
        'resume',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'years_experience' => 'integer',
        ];
    }

    /**
     * @var list<string>
     */
    protected $appends = ['full_name'];

    // ── Relationships ────────────────────────────────────────────────────────

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    /**
     * Supporting files the candidate has attached (besides the primary résumé).
     *
     * @return HasMany<ApplicantDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim($this->first_name.' '.$this->last_name),
        );
    }

    /**
     * Build the applicant's initials from their first and last name.
     */
    public function initials(): string
    {
        return mb_strtoupper(
            mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)
        );
    }

    /**
     * The public URL of the applicant's résumé, if any.
     */
    public function resumeUrl(): ?string
    {
        return $this->resume ? Storage::disk('public')->url($this->resume) : null;
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * Free-text search across name, email and headline.
     *
     * @param  Builder<Applicant>  $query
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
            foreach (['first_name', 'last_name', 'email', 'headline'] as $column) {
                $query->orWhere($column, $like, $needle);
            }
        });
    }
}
