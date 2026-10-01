<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * One chat thread with the Synapse assistant, owned by a user and tenant-scoped.
 * The title is derived from the first user message; threads can be pinned to the
 * top of the history list. See {@see AssistantMessage} for the turns.
 */
class AssistantConversation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'title',
        'pinned',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The turns in this thread, oldest first.
     *
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }

    /**
     * The most recent turn, for the history list preview.
     *
     * @return HasOne<AssistantMessage, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(AssistantMessage::class, 'conversation_id')->latestOfMany();
    }

    /**
     * Limit to a given user's threads (most recently active first).
     *
     * @param  Builder<AssistantConversation>  $query
     */
    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId)
            ->orderByDesc('pinned')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id');
    }

    /**
     * Build a short title from a first message (no extra AI call).
     */
    public static function deriveTitle(string $message): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        if ($clean === '') {
            return 'New conversation';
        }

        return Str::limit($clean, 48, '…');
    }

    /**
     * The shape the front-end consumes. With `$withMessages`, includes the full
     * turn list; otherwise a one-line preview from the latest turn.
     *
     * @return array<string, mixed>
     */
    public function present(bool $withMessages = false): array
    {
        $data = [
            'id' => $this->id,
            'title' => $this->title ?: 'New conversation',
            'pinned' => $this->pinned,
            'last_activity_at' => ($this->last_activity_at ?? $this->created_at)?->toIso8601String(),
        ];

        if ($withMessages) {
            $data['messages'] = $this->messages->map(fn (AssistantMessage $m): array => $m->present())->all();
        } else {
            $preview = $this->latestMessage;
            $data['preview'] = $preview ? Str::limit((string) $preview->body, 60, '…') : null;
        }

        return $data;
    }
}
