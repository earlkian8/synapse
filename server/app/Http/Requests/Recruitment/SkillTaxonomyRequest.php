<?php

namespace App\Http\Requests\Recruitment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What the posting form asks ESCO about (ADR 0100): a skill HR typed, the
 * start of one, or a job title, as `q`. Only somebody who can write a posting
 * may ask, since each question can reach ESCO.
 */
class SkillTaxonomyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('recruitment.create') || $user->can('recruitment.update'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // As long as a skill (60) may be, or a posting's title (255).
            'q' => ['required', 'string', 'min:2', 'max:'.($this->routeIs('recruitment.skills.role') ? 255 : 60)],
        ];
    }

    public function text(): string
    {
        return trim((string) $this->validated('q'));
    }
}
