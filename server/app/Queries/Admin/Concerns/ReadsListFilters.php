<?php

namespace App\Queries\Admin\Concerns;

use Illuminate\Http\Request;

/**
 * The filters every portal list reads the same way (ADR 0085): anything out of
 * range falls back to the default rather than failing, so a hand-edited address
 * still opens the list.
 *
 * A using class declares `SORTABLE`, `STATUSES`, `DEFAULT_SORT` and
 * `DEFAULT_DIRECTION`.
 */
trait ReadsListFilters
{
    /**
     * @var list<int>
     */
    public const PER_PAGE = [10, 15, 25, 50, 100];

    public function search(Request $request): string
    {
        return mb_substr(trim($request->string('search')->toString()), 0, 100);
    }

    public function status(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, static::STATUSES, true) ? $status : static::STATUSES[0];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function sort(Request $request): array
    {
        $sort = $request->string('sort')->toString();

        if (! in_array($sort, static::SORTABLE, true)) {
            return [static::DEFAULT_SORT, static::DEFAULT_DIRECTION];
        }

        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        return [$sort, $direction];
    }

    public function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', 15);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : 15;
    }

    /**
     * The filters as applied, echoed back so the portal shows what it got.
     *
     * @return array<string, mixed>
     */
    public function filters(Request $request): array
    {
        [$sort, $direction] = $this->sort($request);

        return [
            'search' => $this->search($request),
            'status' => $this->status($request),
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $this->perPage($request),
        ];
    }
}
