<?php

namespace App\Support\Recruitment\Skills;

/**
 * The catalogue alone (ADR 0100): what the matcher uses when no taxonomy is
 * configured, and in tests that need none.
 */
final class NoTaxonomy implements SkillTaxonomy
{
    public function concept(string $text): ?TaxonomySkill
    {
        return null;
    }

    public function preload(array $texts): void {}
}
