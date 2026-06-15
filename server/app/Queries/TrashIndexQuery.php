<?php

namespace App\Queries;

use App\Models\User;
use App\Support\Trash\TrashPresenter;
use App\Support\Trash\TrashRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Builds the unified Trash Bin listing: every soft-deleted record across the
 * registered trashable types the actor may view, filtered + sorted by most
 * recently archived, then paginated.
 *
 * Trash sets are small (archived records, typically pruned), so each type's
 * `onlyTrashed()` query is materialised and merged in PHP — this reuses every
 * model's `search` scope and organisation scope for free and stays portable
 * across Postgres and SQLite, with no cross-table UNION dialect concerns.
 */
class TrashIndexQuery
{
    /** @var list<int> */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 15;

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(Request $request, User $actor): LengthAwarePaginator
    {
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim($request->string('search')->toString());
        $type = $this->type($request, $actor);

        /** @var Collection<int, array{0: int, 1: array<string, mixed>}> $rows */
        $rows = new Collection;

        foreach ($this->scannedTypes($type, $actor) as $key => $definition) {
            $this->trashedQuery($definition['model'], $search)
                ->get()
                ->each(function (Model $model) use ($key, $actor, $rows): void {
                    $rows->push([
                        (int) ($model->deleted_at?->getTimestamp() ?? 0),
                        TrashPresenter::present($key, $model, $actor),
                    ]);
                });
        }

        $sorted = $rows
            ->sortByDesc(fn (array $row): int => $row[0])
            ->map(fn (array $row): array => $row[1])
            ->values();

        $items = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($items, $sorted->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'query' => $request->query(),
        ]);
    }

    /**
     * The per-type trashed counts (ignoring search) the actor may view, plus the
     * total — used for the type tabs and the headline.
     *
     * @return array{total: int, types: list<array<string, mixed>>}
     */
    public function summary(User $actor): array
    {
        $types = [];
        $total = 0;

        foreach (TrashRegistry::viewableTypes($actor) as $key) {
            $definition = TrashRegistry::definition($key);
            $count = $definition['model']::onlyTrashed()->count();
            $total += $count;

            $types[] = [
                'key' => $key,
                'label' => $definition['label'],
                'plural' => $definition['plural'],
                'icon' => $definition['icon'],
                'count' => $count,
                'can_restore' => $actor->can($definition['restore']),
                'can_force_delete' => $actor->can($definition['forceDelete']),
            ];
        }

        return ['total' => $total, 'types' => $types];
    }

    /**
     * The selected type filter — `all`, or a specific viewable type key.
     */
    public function type(Request $request, User $actor): string
    {
        $type = $request->string('type')->toString();

        return in_array($type, TrashRegistry::viewableTypes($actor), true) ? $type : 'all';
    }

    public function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', self::DEFAULT_PER_PAGE);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    /**
     * The type definitions to scan for the listing, honouring the type filter.
     *
     * @return array<string, array<string, mixed>>
     */
    private function scannedTypes(string $type, User $actor): array
    {
        $viewable = TrashRegistry::viewableTypes($actor);

        return collect(TrashRegistry::types())
            ->only($type === 'all' ? $viewable : [$type])
            ->all();
    }

    /**
     * A trashed-only query for one model with the optional search applied.
     *
     * @param  class-string  $model
     * @return Builder<covariant Model>
     */
    private function trashedQuery(string $model, string $search): Builder
    {
        /** @var Builder<Model> $query */
        $query = $model::onlyTrashed();

        return $query->when(
            $search !== '' && method_exists($model, 'scopeSearch'),
            fn (Builder $query) => $query->search($search),
        );
    }
}
