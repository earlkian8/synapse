<?php

namespace App\Support\Recruitment\Skills;

use App\Models\JobApplication;

/**
 * Everything a candidate has given that can show a skill, in the order it is
 * trusted (ADR 0097): the résumé first, then their headline, the cover note,
 * their supporting documents, and the recruiter's notes. Each piece is
 * indexed only when a skill is first looked for, and each skill is looked for
 * once.
 */
final class CandidateEvidence
{
    /** @var array<string, SkillText> */
    private array $texts = [];

    /** @var array<string, array{surface: string, source: string}|false> */
    private array $found = [];

    /**
     * @param  array<string, string|null>  $sources  label → text, most trusted first
     * @param  list<string>  $names  the candidate's own names, never read as skills
     */
    public function __construct(private readonly array $sources, private readonly array $names = []) {}

    public static function forApplication(JobApplication $application): self
    {
        $applicant = $application->applicant;

        return new self([
            'résumé' => $applicant?->resume_text,
            'headline' => $applicant?->headline,
            'cover note' => $application->cover_note,
            'documents' => $applicant?->documents_text,
            'notes' => $applicant?->notes,
        ], names: Words::tokens(trim(($applicant?->first_name ?? '').' '.($applicant?->last_name ?? ''))));
    }

    /**
     * Where the candidate shows this skill by name or alias.
     *
     * @return array{surface: string, source: string}|null
     */
    public function find(Skill $skill): ?array
    {
        if (! array_key_exists($skill->key, $this->found)) {
            $this->found[$skill->key] = false;

            foreach ($this->texts() as $source => $text) {
                $surface = $text->mentions($skill);

                if ($surface !== null) {
                    $this->found[$skill->key] = ['surface' => $surface, 'source' => $source];
                    break;
                }
            }
        }

        return $this->found[$skill->key] ?: null;
    }

    /**
     * Where the candidate writes the skill one letter off.
     *
     * @return array{surface: string, source: string}|null
     */
    public function misspelling(Skill $skill, SkillCatalogue $catalogue): ?array
    {
        foreach ($this->texts() as $source => $text) {
            $surface = $text->misspells($skill, $catalogue);

            if ($surface !== null) {
                return ['surface' => $surface, 'source' => $source];
            }
        }

        return null;
    }

    /**
     * Where the candidate uses all these words close together.
     *
     * @param  list<string>  $stems
     * @return array{surface: string, source: string}|null
     */
    public function phrase(array $stems, int $slack = 3): ?array
    {
        foreach ($this->texts() as $source => $text) {
            $surface = $text->phrase($stems, $slack);

            if ($surface !== null) {
                return ['surface' => $surface, 'source' => $source];
            }
        }

        return null;
    }

    /**
     * @return array<string, SkillText>
     */
    private function texts(): array
    {
        foreach ($this->sources as $source => $text) {
            if (! isset($this->texts[$source]) && filled($text)) {
                $this->texts[$source] = SkillText::of($text, $this->names);
            }
        }

        return $this->texts;
    }
}
