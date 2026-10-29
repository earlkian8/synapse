<?php

namespace App\Models;

use App\Jobs\ReadApplicantFiles;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Files\Uploads;
use Database\Factories\ApplicantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Applicant extends Model
{
    /** @use HasFactory<ApplicantFactory> */
    use BelongsToOrganization, HasFactory;

    /** The résumé is waiting to be read (ADR 0097). */
    public const READ_PENDING = 'pending';

    /** The résumé was read and its text kept. */
    public const READ_DONE = 'read';

    /** The résumé holds no text that could be read (or its type can't be). */
    public const READ_UNREADABLE = 'unreadable';

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
            // Whole or half years: "1.5" (ADR 0091).
            'years_experience' => 'float',
        ];
    }

    /**
     * @var list<string>
     */
    protected $appends = ['full_name'];

    /**
     * The text read out of the candidate's files is for matching only; it never
     * rides along when a model is turned into JSON.
     *
     * @var list<string>
     */
    protected $hidden = ['resume_text', 'documents_text'];

    /** Set while saving when a new résumé arrived, so it is read once saved. */
    private bool $resumeArrived = false;

    /**
     * A new résumé forgets what was read from the old one and is read once the
     * response has gone, however it arrived (ADR 0097); a removed résumé
     * leaves nothing behind.
     */
    protected static function booted(): void
    {
        static::saving(function (Applicant $applicant): void {
            if (! $applicant->isDirty('resume')) {
                return;
            }

            $applicant->resume_text = null;
            $applicant->resume_read_status = $applicant->resume === null ? null : self::READ_PENDING;
            $applicant->resumeArrived = $applicant->resume !== null;
        });

        static::saved(function (Applicant $applicant): void {
            if ($applicant->resumeArrived) {
                $applicant->resumeArrived = false;
                ReadApplicantFiles::dispatchAfterResponse($applicant->organization_id, $applicant->id);
            }
        });
    }

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
     * A link to the applicant's résumé, if any, through the app (ADR 0087).
     */
    public function resumeUrl(): ?string
    {
        return $this->resume ? Uploads::url($this->resume) : null;
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
