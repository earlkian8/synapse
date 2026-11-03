<?php

namespace App\Models;

use App\Support\Recruitment\Skills\Words;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A skill a posting asked for, folded, and what ESCO said about it (ADR 0100):
 * the ESCO skill it names, that ESCO has none by that name, or that ESCO
 * couldn't be reached. Reference data with no organization: the term is a
 * skill's name, never who asked for it.
 */
class EscoSkillTerm extends Model
{
    /** ESCO has a skill by this name. */
    public const MATCHED = 'matched';

    /** ESCO has no skill by this name. */
    public const NONE = 'none';

    /** ESCO couldn't be reached; asked again later. */
    public const FAILED = 'failed';

    /** How long an answer is trusted before ESCO is asked again, in days. */
    public const FRESH_DAYS = [self::MATCHED => 180, self::NONE => 30];

    /** A term that failed this often is asked again daily at most. */
    public const BACKOFF_AFTER = 3;

    protected $fillable = [
        'term',
        'esco_skill_id',
        'status',
        'attempts',
        'looked_up_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'looked_up_at' => 'datetime',
        ];
    }

    /** The longest term kept; a skill is at most 60 characters. */
    public const MAX_TERM = 120;

    /**
     * What a skill is stored under: folded, as the matcher compares words, so
     * "Forklift operation" and "forklift-operation" are asked about once.
     */
    public static function keyFor(string $text): string
    {
        return mb_substr(Words::fold($text), 0, self::MAX_TERM);
    }

    /**
     * @return BelongsTo<EscoSkill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(EscoSkill::class, 'esco_skill_id');
    }

    /**
     * Whether ESCO's answer still stands, or it is time to ask again. A failed
     * lookup is asked again at once, and daily once it has failed a few times.
     */
    public function isFresh(): bool
    {
        if ($this->looked_up_at === null) {
            return false;
        }

        if ($this->status === self::FAILED) {
            return $this->attempts >= self::BACKOFF_AFTER && $this->looked_up_at->gt(now()->subDay());
        }

        return $this->looked_up_at->gt(now()->subDays(self::FRESH_DAYS[$this->status] ?? 0));
    }
}
