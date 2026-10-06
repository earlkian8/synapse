<?php

namespace App\Support\Search\Sources;

use App\Models\Event;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Events and meetings, opened on the event. */
class EventSource extends RecordSource
{
    public function key(): string
    {
        return 'events';
    }

    public function label(): string
    {
        return 'Events';
    }

    protected function permission(): string
    {
        return 'events.view';
    }

    protected function query(): Builder
    {
        return Event::query()->select(['id', 'title', 'type', 'starts_at', 'location']);
    }

    protected function rankColumns(): array
    {
        return ['title'];
    }

    protected function order(Builder $query): void
    {
        $query->orderByDesc('starts_at')->orderByDesc('id');
    }

    /**
     * @param  Event  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'event:'.$row->id,
            title: $row->title,
            subtitle: $row->location,
            hint: $row->starts_at?->format('M j, Y'),
            href: route('events.show', $row, absolute: false),
        );
    }
}
