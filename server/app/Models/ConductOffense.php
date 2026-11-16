<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One offense in the company's code of conduct (ADR 0105): how grave it is, the
 * Labor Code ground it rests on, the penalty for each time it happens again,
 * and how long one counts towards the next.
 *
 * The penalties are a ladder: the first upheld case of it gets the first rung,
 * the second the next, and so on, the last rung repeating. An upheld case
 * stops counting once {@see $reckoning_months} have passed since its decision.
 */
class ConductOffense extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** How grave, lightest first. */
    public const GRAVITIES = [
        'minor' => 'Minor',
        'less_serious' => 'Less serious',
        'serious' => 'Serious',
        'grave' => 'Grave',
    ];

    /**
     * The just causes of Article 297 of the Labor Code (formerly 282), and a
     * company rule that is none of them — which, by itself, is never a ground to
     * dismiss.
     */
    public const GROUNDS = [
        'serious_misconduct' => 'Serious misconduct (Art. 297 a)',
        'willful_disobedience' => 'Willful disobedience of a lawful order (Art. 297 a)',
        'gross_neglect' => 'Gross and habitual neglect of duties (Art. 297 b)',
        'fraud' => 'Fraud or willful breach of trust (Art. 297 c)',
        'crime' => 'A crime against the employer, their family or representative (Art. 297 d)',
        'analogous' => 'A cause analogous to these (Art. 297 e)',
        'company_rule' => 'A company rule',
    ];

    /** The grounds on which a dismissal can rest. */
    public const JUST_CAUSES = ['serious_misconduct', 'willful_disobedience', 'gross_neglect', 'fraud', 'crime', 'analogous'];

    protected $fillable = [
        'organization_id',
        'code',
        'title',
        'description',
        'gravity',
        'ground',
        'penalties',
        'reckoning_months',
        'is_active',
    ];

    protected $attributes = [
        'gravity' => 'minor',
        'ground' => 'company_rule',
        'reckoning_months' => 12,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'penalties' => 'array',
            'reckoning_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<DisciplinaryCase, $this>
     */
    public function cases(): HasMany
    {
        return $this->hasMany(DisciplinaryCase::class);
    }

    /**
     * The penalty for the nth upheld case of this offense — the ladder's last
     * rung once it runs out.
     *
     * @return array{action: string, days: ?int}|null
     */
    public function penaltyFor(int $occurrence): ?array
    {
        $ladder = array_values((array) $this->penalties);

        if ($ladder === []) {
            return null;
        }

        $step = $ladder[min(max(1, $occurrence), count($ladder)) - 1];

        return ['action' => (string) ($step['action'] ?? 'written_warning'), 'days' => isset($step['days']) ? (int) $step['days'] : null];
    }

    public function isJustCause(): bool
    {
        return in_array($this->ground, self::JUST_CAUSES, true);
    }
}
