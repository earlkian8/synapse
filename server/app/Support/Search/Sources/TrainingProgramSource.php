<?php

namespace App\Support\Search\Sources;

use App\Models\TrainingProgram;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Training programs, opened on the program. */
class TrainingProgramSource extends RecordSource
{
    public function key(): string
    {
        return 'training';
    }

    public function label(): string
    {
        return 'Training';
    }

    protected function permission(): string
    {
        return 'training.view';
    }

    protected function query(): Builder
    {
        return TrainingProgram::query()->select(['id', 'name', 'provider', 'start_date']);
    }

    protected function rankColumns(): array
    {
        return ['name'];
    }

    /**
     * @param  TrainingProgram  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'training:'.$row->id,
            title: $row->name,
            subtitle: $row->provider,
            hint: $row->start_date?->format('M j, Y'),
            href: route('training.show', $row, absolute: false),
        );
    }
}
