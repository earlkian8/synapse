<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

/**
 * Whether a message asks or instructs.
 *
 * Deliberately crude, and deliberately local. It decides whether a write the
 * model proposes may run straight away or waits for the user's OK (ADR 0049):
 * a write on a question is the signature of a model steered by what it read.
 * A wrong guess costs one extra click or one held card, never a wrong action.
 *
 * People rarely open with the verb. "hey can you put this CV in the analyst
 * posting" and "Paki-approve po yung leave ni Maria" are instructions, so
 * greetings and politeness are stripped first, and "can you / could you"
 * followed by a verb that changes something counts as the instruction (ADR
 * 0068 §6). A verb that only reads ("can you check…", "show me…") is a
 * question, whatever the punctuation.
 */
final class RequestIntent
{
    /**
     * Openings that make a turn an instruction rather than a question.
     *
     * @var list<string>
     */
    public const IMPERATIVES = [
        'add', 'create', 'file', 'approve', 'reject', 'cancel', 'hire', 'move', 'advance', 'schedule',
        'delete', 'remove', 'archive', 'update', 'set', 'change', 'nudge', 'remind', 'record', 'clock',
        'start', 'post', 'open', 'close', 'withdraw', 'assign', 'mark', 'send', 'make', 'rate', 'score',
        'submit', 'acknowledge', 'launch', 'sign', 'enroll', 'enrol', 'invite', 'give', 'award', 'recognise',
        'recognize', 'grade', 'drop', 'reschedule', 'offboard', 'complete', 'reopen', 'flag', 'clear', 'apply',
        'rename', 'restore', 'nest', 'declare', 'turn', 'enable', 'disable', 'require', 'base', 'retire',
        'reactivate', 'copy', 'grant', 'revoke', 'activate', 'deactivate', 'resend', 'take', 'permanently',
        'purge', 'run', 'rerun', 'train', 'switch', 'assess', 'rescore', 'decline', 'notify', 'announce',
        'broadcast', 'reapply',
        // ADR 0068: the verbs people actually type.
        'put', 'attach', 'upload', 'store', 'save', 'transfer', 'promote', 'register', 'onboard', 'place',
        'link', 'edit', 'fix', 'correct', 'extend', 'book', 'enter', 'insert', 'import', 'fill', 'reassign',
        'pull', 'bring', 'note', 'log', 'tag', 'label',
    ];

    /**
     * Requests to read or write something up — "show me…", "can you check…",
     * "draft an announcement". They change nothing, so they count as questions:
     * a write the model proposes on one waits for the user's OK, because that is
     * what a model steered by what it just read looks like (ADR 0049).
     *
     * @var list<string>
     */
    private const READS = [
        'list', 'show', 'find', 'get', 'check', 'compare', 'rank', 'shortlist', 'look', 'search', 'see',
        'view', 'tell', 'read', 'summarise', 'summarize', 'explain', 'describe', 'count', 'generate',
        'prepare', 'draft', 'write', 'suggest', 'recommend', 'analyse', 'analyze', 'review', 'verify',
        'confirm', 'help',
    ];

    /**
     * Openings that make a turn a question even without a question mark.
     *
     * @var list<string>
     */
    private const QUESTION_OPENERS = [
        'who', 'what', 'when', 'where', 'why', 'how', 'which', 'is ', 'are ', 'was ', 'were ', 'does ', 'do ',
        'did ', 'can ', 'could ', 'should ', 'has ', 'have ', 'any ', 'summarise', 'summarize', 'explain',
        'sino', 'ano', 'kailan', 'saan', 'bakit', 'paano', 'ilan', 'kumusta', 'may ',
    ];

    /**
     * Words that open a message without carrying its meaning.
     *
     * @var list<string>
     */
    private const PREAMBLE = [
        'hey', 'hi', 'hello', 'yo', 'please', 'pls', 'plz', 'kindly', 'po', 'uh', 'um', 'ok', 'okay', 'so',
        'and', 'also', 'now', 'then', 'alright', 'oh', 'well', 'good', 'morning', 'afternoon', 'evening',
        'assistant', 'synapse', 'just', 'quickly',
    ];

    /** "can you …", "could you …" — a request phrased as a question. */
    private const MODAL = '/^(?:can|could|would|will|pwede|pwedeng)\s+(?:you|u|mo|ba)?\s*(?:please\s+|pls\s+|kindly\s+)?(\S+)/u';

    public static function isQuestion(string $message): bool
    {
        $text = self::stripPreamble(Str::lower(trim($message)));

        if ($text === '') {
            return false;
        }

        // Filipino "paki-" is "please" fused onto the verb: always a request.
        if (preg_match('/^paki[\s-]?\p{L}/u', $text) === 1) {
            return false;
        }

        $verb = preg_match(self::MODAL, $text, $modal) === 1 ? $modal[1] : Str::before($text, ' ');

        if (self::isRead($verb) || Str::startsWith($text, ['give me', 'let me see', 'let me know'])) {
            return true;
        }

        if (self::isImperative($verb)) {
            return false;
        }

        if (str_contains($text, '?')) {
            return true;
        }

        return Str::startsWith($text, self::QUESTION_OPENERS)
            || Str::contains($text, ['tell me', 'how is', 'how are', 'how many', 'how much', 'what is', 'what are', 'kumusta', 'ilan ', 'sino ', 'ano ']);
    }

    private static function isRead(string $word): bool
    {
        return in_array(trim($word, " \t,.!:;?"), self::READS, true);
    }

    private static function isImperative(string $word): bool
    {
        return in_array(trim($word, " \t,.!:;"), self::IMPERATIVES, true);
    }

    /**
     * Drop greetings, fillers and punctuation from the front of a message.
     */
    private static function stripPreamble(string $text): string
    {
        $text = ltrim($text, " \t\n\r,.!:;-");

        while ($text !== '') {
            $word = Str::before($text, ' ');
            $bare = trim($word, ',.!:;-');

            if (! in_array($bare, self::PREAMBLE, true) || $word === $text) {
                break;
            }

            $text = ltrim(Str::after($text, ' '), " \t\n\r,.!:;-");
        }

        return $text;
    }
}
