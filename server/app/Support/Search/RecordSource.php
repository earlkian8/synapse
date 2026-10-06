<?php

namespace App\Support\Search;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A kind of record global search can find — a model, the permission its
 * screen checks, and how one row is shown.
 *
 * Matching is the model's own `scopeSearch()`, applied once per word and ANDed
 * ({@see GlobalSearch::matchEveryWord()}), so the palette finds what the
 * module's list would. Rows whose {@see rankColumns()} equal what was typed
 * come first, then those starting with its first word, then the rest in the
 * source's own {@see order()}.
 */
abstract class RecordSource implements SearchSource
{
    /** The ability the record's own route checks (`can:` middleware). */
    abstract protected function permission(): string;

    /**
     * The rows that may be found, before any word is matched. Select only the
     * columns the result shows.
     *
     * @return Builder<*>
     */
    abstract protected function query(): Builder;

    abstract protected function toResult(Model $row): SearchResult;

    /**
     * Columns of the model's own table that make a match "exact": `name`,
     * `employee_no`… Empty means the source's order alone decides.
     *
     * @return list<string>
     */
    protected function rankColumns(): array
    {
        return [];
    }

    /**
     * How rows that rank the same are ordered. Newest first unless a source
     * has a more natural order (by name, for people).
     *
     * @param  Builder<*>  $query
     */
    protected function order(Builder $query): void
    {
        $query->orderByDesc($query->getModel()->getQualifiedKeyName());
    }

    public function permissionName(): string
    {
        return $this->permission();
    }

    public function permits(User $user): bool
    {
        return $user->can($this->permission());
    }

    public function find(User $user, array $words, string $phrase): Collection
    {
        $query = GlobalSearch::matchEveryWord($this->query(), $words);

        $this->rank($query, $words, $phrase);
        $this->order($query);

        return $query->limit(GlobalSearch::LIMIT)
            ->get()
            ->map(fn (Model $row): SearchResult => $this->toResult($row))
            ->values();
    }

    /**
     * `case when <a rank column is the phrase> then 0 when <one starts with the
     * first word> then 1 else 2 end` — portable SQL: `lower()` on both sides
     * and an explicit LIKE escape, so `%` and `_` typed are taken literally. The
     * escape is `!`, not a backslash, which PDO's SQL scanners do not all read
     * the same way inside a quoted literal.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $words
     */
    private function rank(Builder $query, array $words, string $phrase): void
    {
        $columns = array_map(
            fn (string $column): string => 'lower('.$query->getModel()->qualifyColumn($column).')',
            $this->rankColumns(),
        );

        if ($columns === [] || $words === []) {
            return;
        }

        $exact = implode(' or ', array_map(fn (string $column): string => $column.' = ?', $columns));
        $prefix = implode(' or ', array_map(fn (string $column): string => $column." like ? escape '!'", $columns));
        $startsWith = GlobalSearch::escapeLike($words[0]).'%';

        $query->orderByRaw(
            "case when ({$exact}) then 0 when ({$prefix}) then 1 else 2 end",
            [...array_fill(0, count($columns), $phrase), ...array_fill(0, count($columns), $startsWith)],
        );
    }
}
