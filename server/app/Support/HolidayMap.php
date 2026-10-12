<?php

namespace App\Support;

use App\Models\Holiday;

/**
 * The holidays of many people across a range, as {@see HolidayCalendar::forEmployees()}
 * resolved them (ADR 0078): the ones kept everywhere, and for each base site that
 * keeps local ones, the site's calendar. Ask it for a date, and for a person when
 * it matters who is asking.
 */
final readonly class HolidayMap
{
    /**
     * @param  array<string, Holiday>  $everywhere  Keyed "Y-m-d".
     * @param  array<int, array<string, Holiday>>  $bySite  Site id → its calendar, the everywhere holidays included.
     * @param  array<int, int>  $siteOf  Employee id → base site id.
     */
    public function __construct(
        private array $everywhere,
        private array $bySite,
        private array $siteOf,
    ) {}

    public static function empty(): self
    {
        return new self([], [], []);
    }

    /**
     * The holiday on a date — the person's, when one is named; otherwise the one
     * kept everywhere.
     */
    public function on(string $date, ?int $employeeId = null): ?Holiday
    {
        return $this->for($employeeId)[$date] ?? null;
    }

    /**
     * Every holiday a person keeps in the range, keyed "Y-m-d".
     *
     * @return array<string, Holiday>
     */
    public function for(?int $employeeId): array
    {
        $site = $employeeId !== null ? ($this->siteOf[$employeeId] ?? null) : null;

        return $site !== null && isset($this->bySite[$site]) ? $this->bySite[$site] : $this->everywhere;
    }

    /**
     * The holidays kept everywhere, keyed "Y-m-d".
     *
     * @return array<string, Holiday>
     */
    public function everywhere(): array
    {
        return $this->everywhere;
    }

    /**
     * Whether any person in the map keeps a local holiday in the range.
     */
    public function hasLocal(): bool
    {
        return $this->bySite !== [];
    }
}
