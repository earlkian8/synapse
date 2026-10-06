<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Assistant\Attachments\ConversationAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in an {@see AssistantConversation}: a user message (optionally with
 * the files it carried — `{name, mime, size, path}` since ADR 0068, bare names
 * before) or an assistant reply (optionally carrying the agent `steps`
 * transcript and result `actions` cards so the thread re-renders faithfully).
 */
class AssistantMessage extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'conversation_id',
        'role',
        'body',
        'steps',
        'actions',
        'usage',
        'attachments',
        'failed',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'actions' => 'array',
            'usage' => 'array',
            'attachments' => 'array',
            'failed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /**
     * The shape the front-end consumes.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'body' => (string) $this->body,
            'steps' => $this->steps ?? [],
            'actions' => $this->actions ?? [],
            'attachments' => ConversationAttachments::names($this->attachments),
            'usage' => $this->usage,
            'failed' => $this->failed,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
