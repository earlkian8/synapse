<?php

namespace App\Queries;

use App\Models\Interview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The interviews one person is assigned (ADR 0091) — what an interviewer sees
 * without the recruitment board: still to hold or record, then the ones they
 * have given a verdict on.
 *
 * Each carries what conducting it needs and nothing past it: the candidate's
 * profile, résumé, cover note and answers to the posting's questions. The
 * expected salary, the recruiter's rating and notes, the fit score and the
 * candidate's other documents (IDs among them) stay on HR's board.
 */
final class MyInterviews
{
    /** How many recorded interviews to list. */
    public const DONE_LIMIT = 50;

    /**
     * @return array{pending: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function for(User $user): array
    {
        $query = fn (): Builder => Interview::query()
            ->where('interviewer_id', $user->id)
            ->whereHas('application')
            ->with([
                'application.applicant',
                'application.pipelineStage:id,name',
                'application.jobPosting:id,title,department_id',
                'application.jobPosting.department:id,name',
                'application.jobPosting.screeningQuestions',
            ]);

        return [
            'pending' => $query()->where('result', 'pending')->orderBy('scheduled_at')->get()
                ->map(fn (Interview $interview): array => $this->row($interview))->all(),
            'done' => $query()->where('result', '!=', 'pending')->orderByDesc('scheduled_at')->limit(self::DONE_LIMIT)->get()
                ->map(fn (Interview $interview): array => $this->row($interview))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Interview $interview): array
    {
        $application = $interview->application;
        $applicant = $application->applicant;
        $posting = $application->jobPosting;
        $answers = $application->screening_answers ?? [];

        return [
            'id' => $interview->id,
            'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
            'scheduled_label' => $interview->scheduled_at?->format('D, M j, Y · g:i A'),
            'scheduled_human' => $interview->scheduled_at?->diffForHumans(),
            'is_past' => $interview->scheduled_at?->isPast() ?? false,
            'mode' => $interview->mode,
            'location' => $interview->location,
            'notes' => $interview->notes,
            'result' => $interview->result,
            'feedback' => $interview->feedback,
            'posting' => [
                'title' => $posting?->title,
                'department' => $posting?->department?->name,
            ],
            'stage' => $application->pipelineStage?->name,
            'candidate' => [
                'name' => $applicant->full_name,
                'initials' => $applicant->initials(),
                'headline' => $applicant->headline,
                'current_location' => $applicant->current_location,
                'years_experience' => $applicant->years_experience,
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'linkedin_url' => $applicant->linkedin_url,
                'portfolio_url' => $applicant->portfolio_url,
                'resume_url' => $applicant->resumeUrl(),
                'cover_note' => $application->cover_note,
            ],
            'screening' => ($posting?->screeningQuestions ?? collect())
                ->filter(fn ($question): bool => array_key_exists($question->id, $answers))
                ->map(fn ($question): array => ['label' => $question->label, 'answer' => (bool) $answers[$question->id]])
                ->values()
                ->all(),
        ];
    }
}
