<?php

namespace App\Support\Recruitment\Skills\Esco;

use App\Models\EscoSkillTerm;
use App\Support\Recruitment\Skills\SkillCatalogue;
use App\Support\Recruitment\Skills\SkillTaxonomy;
use App\Support\Recruitment\Skills\TaxonomySkill;

/**
 * What the ATS has learned from ESCO (ADR 0100), read from the database for
 * the fit score. It never asks ESCO itself: {@see EscoSkills} does that after
 * a posting is saved, and `recruitment:esco-lookup` daily. A skill not
 * looked up yet is simply not known here yet.
 *
 * One instance per scorer: each term is read at most once, and a posting's
 * skills in one query.
 */
final class EscoTaxonomy implements SkillTaxonomy
{
    /** @var array<string, TaxonomySkill|null> term → its skill, or null when there is none */
    private array $known = [];

    public function __construct(private readonly SkillCatalogue $catalogue) {}

    public function concept(string $text): ?TaxonomySkill
    {
        $key = EscoSkillTerm::keyFor($text);

        if ($key === '') {
            return null;
        }

        if (! array_key_exists($key, $this->known)) {
            $this->preload([$text]);
        }

        return $this->known[$key] ?? null;
    }

    public function preload(array $texts): void
    {
        $keys = [];

        foreach ($texts as $text) {
            $key = EscoSkillTerm::keyFor($text);

            if ($key !== '' && ! array_key_exists($key, $this->known)) {
                $keys[$key] = true;
            }
        }

        if ($keys === []) {
            return;
        }

        // An all-digit key ("5") becomes an integer in a PHP array; the column is text.
        $keys = array_map('strval', array_keys($keys));

        foreach ($keys as $key) {
            $this->known[$key] = null;
        }

        $terms = EscoSkillTerm::query()
            ->whereIn('term', $keys)
            ->where('status', EscoSkillTerm::MATCHED)
            ->whereNotNull('esco_skill_id')
            ->with('skill')
            ->get();

        foreach ($terms as $term) {
            if ($term->skill !== null) {
                $this->known[$term->term] = TaxonomySkill::fromEsco($term->skill->toConcept(), $this->catalogue);
            }
        }
    }
}
