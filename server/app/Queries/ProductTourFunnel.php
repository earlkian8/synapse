<?php

namespace App\Queries;

use App\Models\ProductTourRun;
use App\Support\ProductTour;
use Illuminate\Support\Collection;

/**
 * How people move through the tour, stop by stop (ADR 0080) — for the bound
 * workspace, one platform and version, over a period:
 *
 *  - **runs**: started, walked to the end, skipped, and **abandoned** (no
 *    answer an hour after starting: the tab was closed, the app put away);
 *  - **per stop**, in the tour's order: how many runs showed it, how many
 *    reached it, and how many ended there without finishing — where people
 *    leave.
 *
 * Read from {@see ProductTourRun}; nothing is stored as a total.
 */
class ProductTourFunnel
{
    /** A run with no answer this long after starting was left. */
    public const ABANDONED_AFTER_MINUTES = 60;

    /** What each stop is called, by its id. */
    public const LABELS = [
        'welcome' => 'Welcome',
        'workspace' => 'Workspace',
        'nav-main' => 'Dashboard',
        'nav-talent' => 'Talent acquisition',
        'nav-workforce' => 'Your workforce',
        'nav-offboarding' => 'Offboarding',
        'nav-analytics' => 'Analytics & AI',
        'nav-setup' => 'Company setup',
        'nav-system' => 'System',
        'search' => 'Search',
        'notifications' => 'Notifications',
        'help' => 'Help',
        'account' => 'Your account',
        'assistant' => 'Assistant',
        'finish' => 'Send-off',
        // The mobile app's tour.
        'home' => 'Home',
        'clock' => 'Clock',
        'attendance' => 'Attendance',
        'requests' => 'Leave',
        'profile' => 'Profile',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $platform, int $version, int $months): array
    {
        $runs = ProductTourRun::query()
            ->where('platform', $platform)
            ->where('version', $version)
            ->where('started_at', '>=', now()->subMonths($months))
            ->get(['id', 'user_id', 'mode', 'steps', 'furthest_index', 'last_step', 'outcome', 'started_at']);

        $cutoff = now()->subMinutes(self::ABANDONED_AFTER_MINUTES);
        $abandoned = fn (ProductTourRun $run): bool => $run->outcome === null && $run->started_at->lt($cutoff);
        $left = $runs->filter(fn (ProductTourRun $run): bool => $run->outcome === ProductTour::SKIPPED || $abandoned($run));

        return [
            'totals' => [
                'started' => $runs->count(),
                'people' => $runs->pluck('user_id')->unique()->count(),
                'completed' => $runs->where('outcome', ProductTour::COMPLETED)->count(),
                'skipped' => $runs->where('outcome', ProductTour::SKIPPED)->count(),
                'abandoned' => $runs->filter($abandoned)->count(),
                'running' => $runs->filter(fn (ProductTourRun $run): bool => $run->outcome === null && ! $abandoned($run))->count(),
                'by_mode' => collect(ProductTourRun::MODES)->mapWithKeys(fn (string $mode): array => [$mode => $runs->where('mode', $mode)->count()])->all(),
            ],
            'steps' => $this->steps($runs, $left),
        ];
    }

    /**
     * @param  Collection<int, ProductTourRun>  $runs
     * @param  Collection<int, ProductTourRun>  $left
     * @return list<array{id: string, label: string, shown: int, reached: int, left_here: int}>
     */
    private function steps(Collection $runs, Collection $left): array
    {
        // Each stop's place in the tour: the earliest it appears in any run,
        // then its average place, so stops a role never sees still fall in line.
        $places = [];

        foreach ($runs as $run) {
            $steps = array_values($run->steps ?? []);
            $last = max(1, count($steps) - 1);

            foreach ($steps as $index => $id) {
                $places[$id][] = $index / $last;
            }
        }

        uasort($places, fn (array $a, array $b): int => [min($a), array_sum($a) / count($a)] <=> [min($b), array_sum($b) / count($b)]);

        $out = [];

        foreach (array_keys($places) as $id) {
            $shown = 0;
            $reached = 0;

            foreach ($runs as $run) {
                $index = array_search($id, array_values($run->steps ?? []), true);

                if ($index === false) {
                    continue;
                }

                $shown++;
                $reached += $index <= $run->furthest_index ? 1 : 0;
            }

            $out[] = [
                'id' => (string) $id,
                'label' => self::LABELS[$id] ?? str((string) $id)->replace('-', ' ')->ucfirst()->toString(),
                'shown' => $shown,
                'reached' => $reached,
                'left_here' => $left->where('last_step', $id)->count(),
            ];
        }

        return $out;
    }
}
