<?php

namespace App\Services\Assistant\Modules;

use App\Services\Assistant\Contracts\AssistantModule;
use App\Services\Assistant\ToolResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Shared plumbing for the concrete assistant modules: case-insensitive name → id
 * resolution, a uniform "permission denied" result, and the result-card builder
 * the chat UI renders.
 */
abstract class Module implements AssistantModule
{
    public function handles(string $tool): bool
    {
        return array_key_exists($tool, $this->toolMap());
    }

    /**
     * Map of tool name => handler method on the concrete module.
     *
     * @return array<string, string>
     */
    abstract protected function toolMap(): array;

    /**
     * Apply a token-aware search to a query whose model has a `search` scope, so
     * a multi-word name like "Jane Doe" matches first_name *and* last_name rather
     * than failing because no single column contains the whole string. Each token
     * must match some searchable column (the scope's per-column LIKE, ANDed).
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function matchByTokens(Builder $query, ?string $needle): Builder
    {
        $tokens = preg_split('/\s+/', trim((string) $needle)) ?: [];

        foreach ($tokens as $token) {
            if ($token !== '') {
                $query->search($token);
            }
        }

        return $query;
    }

    /**
     * Resolve a row id by a case-insensitive match on one column.
     *
     * @param  Builder<*>  $query
     */
    protected function resolveId(Builder $query, string $column, ?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $id = $query->whereRaw('lower('.$column.') = ?', [Str::lower($value)])->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * A standard "not permitted" outcome.
     */
    protected function denied(string $action): ToolResult
    {
        return ToolResult::error('Permission check', "You don't have permission to {$action}.");
    }

    /**
     * Build a result card for the chat timeline. `kind` and `tone` drive the icon
     * and colour on the frontend; everything else is display text.
     *
     * @param  list<string>  $meta
     * @param  array{name: string, initials: string, photo: ?string}|null  $avatar
     */
    protected function card(
        string $kind,
        string $tone,
        string $badge,
        string $title,
        ?string $subtitle = null,
        array $meta = [],
        ?array $avatar = null,
        int|string|null $id = null,
    ): array {
        return [
            'module' => $this->key(),
            'kind' => $kind,
            'tone' => $tone,
            'badge' => $badge,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => array_values(array_filter($meta, fn ($m): bool => filled($m))),
            'avatar' => $avatar,
            'id' => $id,
        ];
    }

    /**
     * Pull the first non-empty value among the given keys from an args array.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $keys
     */
    protected function firstFilled(array $args, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (filled($args[$key] ?? null)) {
                return (string) $args[$key];
            }
        }

        return null;
    }
}
