<?php

namespace App\Services\Assistant\Attachments;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One file the user sent in a conversation, as the assistant knows it: the
 * number it is listed under, what it was called, and where it is kept on the
 * private `assistant` disk. The path never reaches the model — a tool names an
 * attachment by its number or name, and {@see ConversationAttachments} decides
 * which file that is.
 */
final class StoredAttachment
{
    public function __construct(
        public readonly int $number,
        public readonly string $name,
        public readonly string $mime,
        public readonly int $size,
        public readonly string $path,
        /** Sent with the message being handled now. */
        public readonly bool $current = false,
    ) {}

    public function contents(): string
    {
        $contents = Storage::disk(ConversationAttachments::DISK)->get($this->path);

        if ($contents === null) {
            throw new RuntimeException('The attachment is no longer available.');
        }

        return $contents;
    }

    /** The file's extension, lower-cased, from its name (else its stored path). */
    public function extension(): string
    {
        $extension = Str::lower(pathinfo($this->name, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : Str::lower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    /**
     * Copy the file onto a record's own disk, named the way an upload through
     * the screen would be (40 random characters and the extension), and return
     * the new path. The record owns the copy: deleting the conversation later
     * does not take the record's file with it.
     */
    public function copyTo(string $disk, string $directory): string
    {
        $path = trim($directory, '/').'/'.Str::random(40).'.'.$this->extension();

        if (! Storage::disk($disk)->put($path, $this->contents())) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        return $path;
    }

    /** How the attachment is listed to the model: "[1] Ana_CV.pdf (PDF, 120 KB, sent with this message)". */
    public function label(): string
    {
        $kind = Str::upper($this->extension() ?: 'file');
        $size = max(1, (int) round($this->size / 1024)).' KB';

        return "[{$this->number}] {$this->name} ({$kind}, {$size}".($this->current ? ', sent with this message' : '').')';
    }
}
