<?php

namespace App\Support\Recruitment\Skills;

/**
 * A taxonomy of skills beyond the ATS's own catalogue (ADR 0100), asked about
 * the skills the catalogue doesn't know. It answers from what it has already
 * learned and never reaches the network: the fit score is worked out on every
 * view.
 */
interface SkillTaxonomy
{
    /**
     * The taxonomy's skill for what HR wrote, or null when it has none.
     */
    public function concept(string $text): ?TaxonomySkill;

    /**
     * Learn about these at once, so a list of skills is one read.
     *
     * @param  list<string>  $texts
     */
    public function preload(array $texts): void;
}
