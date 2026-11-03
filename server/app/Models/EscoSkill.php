<?php

namespace App\Models;

use App\Support\Recruitment\Skills\Esco\EscoConcept;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ESCO skill the ATS has learned (ADR 0100). Public reference data, the
 * same for every workspace, so it has no organization.
 */
class EscoSkill extends Model
{
    protected $fillable = [
        'uri',
        'label',
        'alt_labels',
        'description',
        'skill_type',
        'broader',
        'narrower',
    ];

    protected function casts(): array
    {
        return [
            'alt_labels' => 'array',
            'broader' => 'array',
            'narrower' => 'array',
        ];
    }

    /**
     * @return HasMany<EscoSkillTerm, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(EscoSkillTerm::class);
    }

    public function toConcept(): EscoConcept
    {
        return EscoConcept::fromArray([
            'uri' => $this->uri,
            'label' => $this->label,
            'alt_labels' => $this->alt_labels ?? [],
            'description' => $this->description,
            'skill_type' => $this->skill_type,
            'broader' => $this->broader ?? [],
            'narrower' => $this->narrower ?? [],
        ]);
    }

    /**
     * Keep a concept, by its URI, as ESCO last described it. One statement, so
     * two lookups that land on the same skill at once can't both insert it.
     */
    public static function remember(EscoConcept $concept): self
    {
        $row = $concept->toArray();

        // An upsert skips the model's casts.
        foreach (['alt_labels', 'broader', 'narrower'] as $column) {
            $row[$column] = json_encode($row[$column], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        self::query()->upsert([$row], ['uri'], ['label', 'alt_labels', 'description', 'skill_type', 'broader', 'narrower']);

        return self::query()->where('uri', $concept->uri)->firstOrFail();
    }
}
