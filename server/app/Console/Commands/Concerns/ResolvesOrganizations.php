<?php

namespace App\Console\Commands\Concerns;

use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * The `--organization=` option shared by the attendance maintenance commands: an
 * id or a slug narrows the run to one organisation; without it, every
 * organisation is walked. No tenant is bound on the console, so the query is
 * unscoped by construction.
 */
trait ResolvesOrganizations
{
    /**
     * The organisations to walk, or null (with the error already printed) when
     * the option names none.
     *
     * @return Collection<int, Organization>|null
     */
    protected function organizations(): ?Collection
    {
        $needle = trim((string) $this->option('organization'));

        if ($needle === '') {
            return Organization::query()->orderBy('id')->get();
        }

        $organizations = Organization::query()
            ->where('slug', $needle)
            ->when(ctype_digit($needle), fn ($query) => $query->orWhere('id', (int) $needle))
            ->get();

        if ($organizations->isEmpty()) {
            $this->error("No organisation has the id or slug \"{$needle}\".");

            return null;
        }

        return $organizations;
    }
}
