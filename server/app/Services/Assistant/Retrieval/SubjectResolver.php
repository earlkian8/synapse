<?php

namespace App\Services\Assistant\Retrieval;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Works out who a turn is about, by querying the workspace rather than asking
 * the model.
 *
 * A person's name arrives inside ordinary prose — "how's maria santos doing",
 * "tell me about EMP-0007", "how many leave days do I have left" — so the
 * message is reduced to its word tokens and those tokens are matched against
 * the actual name columns. That is deliberately the opposite way round from
 * letting the model extract a name and hand it back: a token that matches no
 * row resolves to nobody, so the retriever can never be talked into briefing on
 * somebody who does not exist, and a name is never spelled by the model.
 *
 * Three cases beyond a plain name, because they are most of what people type:
 *
 * - **Themselves.** "my leave", "am I regularised" resolves to the asker's own
 *   roster line, and needs no directory permission — the same reasoning as
 *   `get_my_employee_record` (ADR 0027).
 * - **A follow-up.** "how is his attendance?" carries no name at all, so the
 *   last few turns are re-read for one. This is what makes a conversation about
 *   somebody stay about them.
 * - **More than one match.** "maria" in a workspace with two Marias returns
 *   both, and the brief becomes a question instead of an answer.
 */
class SubjectResolver
{
    /** How many people a single name may match before we stop listing them. */
    private const MAX_ALTERNATIVES = 5;

    /** How far back to look for the subject of a follow-up question. */
    private const HISTORY_TURNS = 6;

    /** Shortest token that may match a name — "de", "jr" are not searches. */
    private const MIN_TOKEN = 3;

    /**
     * Words that are never a name in these questions. Deliberately short: a
     * false positive costs a little prompt, a false negative costs the answer.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'was', 'are', 'his', 'her', 'him', 'she', 'they', 'them', 'their', 'our', 'you', 'your',
        'who', 'what', 'when', 'where', 'why', 'how', 'which', 'that', 'this', 'these', 'those', 'about', 'tell',
        'show', 'give', 'list', 'find', 'have', 'has', 'had', 'does', 'did', 'can', 'could', 'would', 'should',
        'been', 'being', 'from', 'with', 'into', 'over', 'under', 'much', 'many', 'more', 'most', 'some',
        'any', 'all', 'now', 'today', 'yesterday', 'week', 'month', 'year', 'days', 'day', 'time', 'left',
        'employee', 'employees', 'staff', 'record', 'records', 'profile', 'performance', 'attendance', 'leave',
        'onboarding', 'recruitment', 'applicant', 'candidate', 'summary', 'status', 'doing', 'work', 'working',
        'please', 'thanks', 'okay', 'yes', 'not', 'but', 'per', 'out', 'off', 'get', 'got', 'let', 'see',
        'ang', 'ng', 'sa', 'si', 'ni', 'kay', 'ano', 'sino', 'kailan', 'paano', 'ilan', 'kumusta', 'niya',
    ];

    /**
     * First-person markers — the asker is the subject.
     *
     * "me" is deliberately absent: "tell me about Maria" is the commonest way
     * anybody asks about somebody else.
     */
    private const SELF_MARKERS = ['i', 'my', 'mine', 'myself', 'ako', 'ko', 'akin'];

    /** Third-person markers — the subject is whoever the last turns were about. */
    private const CARRY_MARKERS = ['he', 'she', 'they', 'him', 'her', 'his', 'hers', 'their', 'them', 'niya', 'siya'];

    /**
     * People this turn is about, best match first. More than one means the name
     * was ambiguous; none means the turn is not about a person we can find.
     *
     * @param  array<int, array{role?: string, text?: string}>  $history
     * @return list<RetrievedSubject>
     */
    public function match(User $user, string $message, array $history = []): array
    {
        $words = $this->words($message);
        $matches = $this->search($user, $this->nameTokens($words));

        // A name somebody actually typed outranks a pronoun: "show me Maria's
        // record" is about Maria, however first-person it sounds.
        if ($matches !== []) {
            return $matches;
        }

        if ($this->mentionsSelf($words)) {
            $own = $user->employee()->first();

            if ($own instanceof Employee) {
                return [RetrievedSubject::employee($own, isSelf: true)];
            }
        }

        // A follow-up carries its subject in the turns before it.
        if ($this->carriesSubject($words)) {
            foreach ($this->recentTurns($history) as $text) {
                $matches = $this->search($user, $this->nameTokens($this->words($text)));

                // Only a single, unambiguous earlier subject is worth carrying:
                // re-asking "which Maria?" two turns later helps nobody.
                if (count($matches) === 1) {
                    return $matches;
                }
            }
        }

        return [];
    }

    /**
     * Match the tokens against the people this user is allowed to look up.
     *
     * @param  list<string>  $tokens
     * @return list<RetrievedSubject>
     */
    private function search(User $user, array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $scored = [];

        if ($user->can('employees.view')) {
            foreach ($this->employees($tokens) as $employee) {
                $score = $this->score($tokens, [
                    $employee->first_name,
                    $employee->last_name,
                    $employee->employee_no,
                ], $employee->middle_name);

                if ($score > 0) {
                    $scored[] = [$score, RetrievedSubject::employee($employee)];
                }
            }
        }

        if ($user->can('recruitment.view')) {
            foreach ($this->applicants($tokens) as $applicant) {
                $score = $this->score($tokens, [$applicant->first_name, $applicant->last_name]);

                if ($score > 0) {
                    $scored[] = [$score, RetrievedSubject::applicant($applicant)];
                }
            }
        }

        if ($scored === []) {
            return [];
        }

        $best = max(array_column($scored, 0));

        $winners = array_values(array_map(
            fn (array $row): RetrievedSubject => $row[1],
            array_filter($scored, fn (array $row): bool => $row[0] === $best),
        ));

        return array_slice($winners, 0, self::MAX_ALTERNATIVES);
    }

    /**
     * Employees whose name or number contains one of the tokens. Matching in SQL
     * keeps this a small indexed read rather than a scan of the directory.
     *
     * @param  list<string>  $tokens
     * @return Collection<int, Employee>
     */
    private function employees(array $tokens)
    {
        return Employee::query()
            ->where(fn (Builder $query) => $this->whereAnyToken($query, $tokens, ['first_name', 'last_name', 'employee_no']))
            ->with(['department:id,name', 'position:id,title'])
            ->limit(self::MAX_ALTERNATIVES * 4)
            ->get();
    }

    /**
     * @param  list<string>  $tokens
     * @return Collection<int, Applicant>
     */
    private function applicants(array $tokens)
    {
        return Applicant::query()
            ->where(fn (Builder $query) => $this->whereAnyToken($query, $tokens, ['first_name', 'last_name']))
            ->limit(self::MAX_ALTERNATIVES * 4)
            ->get();
    }

    /**
     * `lower(column) in (tokens)` across the given columns — an exact,
     * case-insensitive token match rather than a LIKE, so "man" does not find
     * "Manuel" and a one-letter typo finds nobody instead of everybody.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $tokens
     * @param  list<string>  $columns
     */
    private function whereAnyToken(Builder $query, array $tokens, array $columns): void
    {
        foreach ($columns as $column) {
            $query->orWhereIn(DB::raw('lower('.$column.')'), $tokens);
        }
    }

    /**
     * How strongly a person matches: 2 for something that identifies them (a
     * number, or both parts of a name), 1 for a single name token, 0 for
     * nothing. A middle name only ever adds to an existing match — nobody is
     * "the Reyes in accounting" by their middle name.
     *
     * @param  list<string>  $tokens
     * @param  list<string|null>  $identifiers
     */
    private function score(array $tokens, array $identifiers, ?string $middle = null): int
    {
        $hits = 0;

        foreach ($identifiers as $identifier) {
            if ($identifier !== null && in_array(Str::lower(trim($identifier)), $tokens, true)) {
                $hits++;
            }
        }

        if ($hits === 0) {
            return 0;
        }

        if ($middle !== null && in_array(Str::lower(trim($middle)), $tokens, true)) {
            $hits++;
        }

        return $hits >= 2 ? 2 : 1;
    }

    /**
     * Every word in the message, lowercased. Hyphens are kept, because an
     * employee number is "emp-0007" — one token, not two.
     *
     * @return list<string>
     */
    private function words(string $message): array
    {
        $parts = preg_split('/[^\p{L}\p{N}-]+/u', Str::lower(trim($message)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(
            fn (string $part): string => trim($part, '-'),
            $parts,
        ), fn (string $part): bool => $part !== ''));
    }

    /**
     * The words worth matching a name against: long enough to be one, and not
     * one of the words every question of this shape contains. Pronouns are
     * dropped here on purpose — they are read from the raw words instead, where
     * they mean "the subject is the asker" or "the subject is whoever we were
     * just discussing".
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private function nameTokens(array $words): array
    {
        $tokens = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_TOKEN || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $tokens[] = $word;
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $words
     */
    private function mentionsSelf(array $words): bool
    {
        return array_intersect($words, self::SELF_MARKERS) !== [];
    }

    /**
     * @param  list<string>  $words
     */
    private function carriesSubject(array $words): bool
    {
        return array_intersect($words, self::CARRY_MARKERS) !== [];
    }

    /**
     * The last few turns, newest first — where a follow-up's subject was named.
     *
     * @param  array<int, array{role?: string, text?: string}>  $history
     * @return list<string>
     */
    private function recentTurns(array $history): array
    {
        $texts = [];

        foreach (array_reverse(array_slice($history, -self::HISTORY_TURNS)) as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}
