<?php

namespace App\Services\Assistant\Retrieval;

use App\Models\User;
use App\Services\Assistant\Contracts\AssistantModule;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * Reads the workspace before the model is asked anything.
 *
 * The assistant's tools are how it *acts*; this is how it *knows*. Given a turn,
 * the retriever works out who it is about and asks every module what it can say
 * about that person, then hands the result to the model as ground truth. So
 * "how is she doing?" is answered from her actual attendance, leave and
 * onboarding rather than from a tool the model had to think to call — and it is
 * answered in one request, because the reading already happened.
 *
 * What it deliberately is not: an index. Retrieval is live, tenant-scoped and
 * permission-checked at read time for the same reasons ADR 0027 gave for the
 * read tools — a second copy of the workforce would sit outside row-level
 * tenancy, go stale the moment somebody is archived, and have no idea who is
 * asking. Everything here is the same query the screens run.
 */
class Retriever
{
    /**
     * @param  array<int, AssistantModule>  $modules
     */
    public function __construct(
        private readonly SubjectResolver $resolver,
        private readonly array $modules,
    ) {}

    /**
     * What this turn should be answered from: a person's brief when the turn is
     * about somebody we can find, a workspace brief when it raises a topic a
     * module owns, or null — in which case the model works from its tools alone.
     *
     * @param  array<int, array{role?: string, text?: string}>  $history
     */
    public function retrieve(User $user, string $message, array $history = []): ?ContextBrief
    {
        // Isolation lives in a global scope that switches itself off when no
        // organisation is bound, so "no tenant" is the one state in which these
        // queries would see the whole instance. Refuse rather than run.
        if (! app(Tenancy::class)->check()) {
            return null;
        }

        $matches = $this->resolver->match($user, $message, $history);

        // Not about anybody: it may still be about the workspace — "how are we
        // doing today?", "how is the review cycle going?".
        if ($matches === []) {
            return $this->topical($user, $message);
        }

        if (count($matches) > 1) {
            return new ContextBrief(
                $matches[0],
                alternatives: array_map(
                    fn (RetrievedSubject $subject): string => $this->describe($subject),
                    $matches,
                ),
            );
        }

        $subject = $matches[0];
        $sections = [];

        foreach ($this->modules as $module) {
            if (! $module instanceof ContributesContext) {
                continue;
            }

            // A module the user cannot otherwise use is still asked about their
            // OWN record — the same reasoning that makes `get_my_employee_record`
            // and `/attendance/me` need no permission. What it may actually
            // disclose stays the module's own decision.
            if (! $module->isAvailable($user) && ! $subject->isSelf) {
                continue;
            }

            $section = $module->contextFor($user, $subject);

            if ($section !== null && ! $section->isEmpty()) {
                $sections[] = $section;
            }
        }

        // Nothing to say is not a brief. It also matters for what the timeline
        // shows: announcing "read X's record" when every module declined would
        // tell somebody without directory permission that X exists.
        return $sections === [] ? null : new ContextBrief($subject, $sections);
    }

    /**
     * The workspace-level brief for a turn that named nobody: every module whose
     * topic the user's own words raised, and that this user may use, says what it
     * can. Null when no topic was raised or nobody had anything to say.
     */
    private function topical(User $user, string $message): ?ContextBrief
    {
        $text = ' '.Str::lower(trim((string) preg_replace('/[^\p{L}\p{N}\'’ -]+/u', ' ', $message))).' ';
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $sections = [];

        foreach ($this->modules as $module) {
            if (! $module instanceof ContributesTopicContext || ! $module->isAvailable($user)) {
                continue;
            }

            if (! $this->raises($text, $module->topicTriggers())) {
                continue;
            }

            $section = $module->topicContext($user);

            if ($section !== null && ! $section->isEmpty()) {
                $sections[] = $section;
            }
        }

        return $sections === [] ? null : ContextBrief::workspace($sections);
    }

    /**
     * Whether the message raises one of the triggers, as a whole word or phrase —
     * "rating" raises performance, "narrating" does not.
     *
     * @param  list<string>  $triggers
     */
    private function raises(string $text, array $triggers): bool
    {
        foreach ($triggers as $trigger) {
            if (str_contains($text, ' '.Str::lower(trim($trigger)).' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * One ambiguous match, in enough detail to tell two people apart.
     */
    private function describe(RetrievedSubject $subject): string
    {
        $employee = $subject->employeeModel();

        if ($employee === null) {
            return $subject->label.' (job applicant)';
        }

        $where = array_values(array_filter([
            $employee->position?->title,
            $employee->department?->name,
        ]));

        return $subject->label.($where !== [] ? ' ('.implode(', ', $where).')' : '');
    }
}
