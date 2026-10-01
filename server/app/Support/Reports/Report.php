<?php

namespace App\Support\Reports;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Collection;

/**
 * A single auditable report.
 *
 * Every report is the single source of truth for the figures it presents: it owns its
 * filters, its columns, and the exact rows behind them — so the on-screen table, the
 * totals, and the CSV export can never disagree. Reports read tenant-scoped models
 * (the global {@see OrganizationScope} confines them to the active
 * organisation) and reuse the canonical query classes wherever one already exists, so
 * the numbers match the rest of the app exactly.
 *
 * `rows()` returns display-ready scalar values (dates already formatted, enums already
 * labelled). That one shape feeds both the table and the export, which is what keeps an
 * export faithful to what the auditor saw on screen.
 */
interface Report
{
    /** Stable URL slug, e.g. `employee-masterlist`. */
    public function key(): string;

    /** Human title. */
    public function name(): string;

    /** One-line description of what the report attests to. */
    public function description(): string;

    /** The hub section this report files under (e.g. "Workforce"). */
    public function group(): string;

    /** The permission required to view (and export) the report. */
    public function permission(): string;

    /**
     * Declarative filter definitions, in display order. Each is an array with at least
     * `key`, `type` (select|daterange|month|search), `label`, and — for selects —
     * `options` (`[{value,label}]`) and an optional `default`.
     *
     * @return list<array<string, mixed>>
     */
    public function filters(): array;

    /**
     * Column definitions, in display order: `key`, `label`, and an optional
     * `align` (left|right) and `type` (text|number|date|badge) for the renderer.
     *
     * @return list<array<string, mixed>>
     */
    public function columns(): array;

    /**
     * The full, ordered result set for the given normalised filter params — one assoc
     * array per row, keyed by column key, holding display-ready scalars.
     *
     * @param  array<string, mixed>  $params
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $params): Collection;

    /**
     * Decision-making visualisations derived from the *whole* result set (not the
     * page on screen). Each is a spec the runner renders as a hand-drawn chart:
     * `['type' => 'donut'|'bars', 'title' => string, 'segments'|'bars' => [{label,value}]]`.
     * Return an empty array for a table-only report.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function charts(Collection $rows, array $params): array;

    /**
     * The summary line shown beneath the table (and footed on the export): labelled
     * totals/aggregates computed over the *entire* result set, not the current page.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string}>
     */
    public function summary(Collection $rows, array $params): array;
}
