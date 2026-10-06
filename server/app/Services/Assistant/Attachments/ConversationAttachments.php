<?php

namespace App\Services\Assistant\Attachments;

use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Services\Assistant\Security\UntrustedText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The files a person has sent in the conversation being handled (ADR 0068 §4).
 *
 * Uploads are kept on the private `assistant` disk under
 * `<organisation>/<conversation>/`, and numbered 1, 2, 3… in the order they
 * were sent. A tool that files something ("put this CV on the candidate")
 * names an attachment by number or name; this class is the only thing that
 * turns that into a file, and it only ever looks in **the bound conversation,
 * owned by the signed-in user**. The model never supplies a path, and a path
 * stored on a row is only read when it sits under that conversation's own
 * folder.
 *
 * Bound per request ({@see use()}): by the chat endpoint for a turn, and by the
 * confirm endpoint for the conversation a plan was proposed in.
 */
final class ConversationAttachments
{
    public const DISK = 'assistant';

    /** How much file content one request may carry inline (Gemini caps a request at 20 MB). */
    public const INLINE_BUDGET = 14 * 1024 * 1024;

    /**
     * Words that mean a message is about a file sent earlier.
     *
     * @var list<string>
     */
    private const REFERS_TO_FILE = [
        'cv', 'cvs', 'resume', 'resumes', 'résumé', 'résumés', 'attachment', 'attachments', 'attached', 'pdf',
        'upload', 'uploaded', 'scan', 'scanned',
    ];

    /**
     * Phrases that point at a file — "file" and "document" alone are too often
     * verbs ("file a leave", "document the reason").
     *
     * @var list<string>
     */
    private const POINTS_AT_FILE = [
        'the file', 'this file', 'that file', 'the document', 'this document', 'that document', 'the image',
        'this image', 'the photo', 'this photo', 'the picture', 'the contract', 'this contract', 'the certificate',
        'this certificate', 'the transcript', 'the portfolio', 'i sent', 'i attached', 'i uploaded',
    ];

    private ?AssistantConversation $conversation = null;

    private ?int $currentMessageId = null;

    /** @var list<StoredAttachment>|null */
    private ?array $cache = null;

    /**
     * Bind the conversation whose files may be used. A conversation that does
     * not belong to the user binds nothing.
     */
    public function use(?AssistantConversation $conversation, ?User $user, ?int $currentMessageId = null): void
    {
        $this->conversation = $conversation !== null && $user !== null && (int) $conversation->user_id === (int) $user->id
            ? $conversation
            : null;
        $this->currentMessageId = $currentMessageId;
        $this->cache = null;
    }

    /**
     * Every file in the bound conversation, numbered in the order it was sent.
     *
     * @return list<StoredAttachment>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if ($this->conversation === null) {
            return $this->cache = [];
        }

        $prefix = $this->folder($this->conversation).'/';
        $files = [];

        $messages = AssistantMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('role', 'user')
            ->whereNotNull('attachments')
            ->orderBy('id')
            ->get(['id', 'attachments']);

        foreach ($messages as $message) {
            foreach ((array) $message->attachments as $file) {
                // Legacy rows hold bare names: nothing was kept, so nothing to number.
                if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                    continue;
                }

                // A row is data; a path that points outside this conversation's
                // own folder is never followed, whatever the row says — and it
                // takes no number, so the numbers the model sees are the ones
                // that resolve.
                if (! str_starts_with($file['path'], $prefix) || str_contains($file['path'], '..')) {
                    continue;
                }

                $files[] = new StoredAttachment(
                    number: count($files) + 1,
                    name: UntrustedText::clean((string) ($file['name'] ?? ''), 120) ?? 'file',
                    mime: (string) ($file['mime'] ?? 'application/octet-stream'),
                    size: (int) ($file['size'] ?? 0),
                    path: $file['path'],
                    current: $message->id === $this->currentMessageId,
                );
            }
        }

        return $this->cache = $files;
    }

    /**
     * The attachment a tool argument names: its number ("2", "#2", 2) or its
     * file name, with or without the extension, in any case. Null when nothing
     * in this conversation matches.
     */
    public function resolve(mixed $reference): ?StoredAttachment
    {
        if (! is_scalar($reference)) {
            return null;
        }

        $reference = trim((string) $reference);
        $files = $this->all();

        if (preg_match('/^(?:#|no\.?\s*|attachment\s*)?(\d{1,3})$/i', $reference, $m) === 1) {
            return $files[(int) $m[1] - 1] ?? null;
        }

        $needle = Str::lower($reference);

        foreach ([fn (StoredAttachment $f): string => Str::lower($f->name), fn (StoredAttachment $f): string => Str::lower(pathinfo($f->name, PATHINFO_FILENAME))] as $key) {
            $matches = array_values(array_filter($files, fn (StoredAttachment $f): bool => $key($f) === $needle));

            if ($matches !== []) {
                // The most recent wins when the same name was sent twice.
                return $matches[array_key_last($matches)];
            }
        }

        return null;
    }

    /**
     * Files sent before this message that it seems to be talking about: when a
     * message mentions "the CV", "that document", "the attachment" and carries
     * none of its own, the model is shown the most recent earlier upload again
     * so it can read what it is being asked to file.
     *
     * @return list<StoredAttachment>
     */
    public function replayFor(string $message): array
    {
        $text = Str::lower($message);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];

        if (array_intersect($words, self::REFERS_TO_FILE) === [] && ! Str::contains($text, self::POINTS_AT_FILE)) {
            return [];
        }

        $earlier = array_values(array_filter($this->all(), fn (StoredAttachment $f): bool => ! $f->current));

        if ($earlier === []) {
            return [];
        }

        return [$earlier[array_key_last($earlier)]];
    }

    /**
     * Keep one upload for a conversation, and return what the message row
     * records about it.
     *
     * @return array{name: string, mime: string, size: int, path: string}
     */
    public static function store(AssistantConversation $conversation, UploadedFile $file): array
    {
        return [
            'name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
            'path' => (string) $file->store(self::folder($conversation), self::DISK),
        ];
    }

    /** Delete every file kept for a conversation. */
    public static function purge(AssistantConversation $conversation): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::folder($conversation));
    }

    /**
     * The names to show for a row's attachments — new rows hold objects, rows
     * from before ADR 0068 hold bare names.
     *
     * @return list<string>
     */
    public static function names(?array $attachments): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $file): ?string => is_array($file) ? ($file['name'] ?? null) : (is_string($file) ? $file : null),
            $attachments ?? [],
        )));
    }

    private static function folder(AssistantConversation $conversation): string
    {
        return $conversation->organization_id.'/'.$conversation->id;
    }
}
