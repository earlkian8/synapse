<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\WorkLocationPeopleRequest;
use App\Http\Requests\Setup\WorkLocationRequest;
use App\Models\AttendancePunch;
use App\Models\WorkLocation;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes the company's sites (ADR 0040): create, edit, say who
 * is based where, archive, restore and permanently delete.
 *
 * The Locations screen and the assistant both come through here, so a site is
 * written and recorded the same way whoever asked. Validation is
 * {@see WorkLocationRequest} and {@see WorkLocationPeopleRequest}, which both run
 * first. Refusals are {@see WorkLocationException}, worded to be shown as they
 * are.
 *
 * A punch was judged where it was made: moving a fence, or who is based at it,
 * changes the punches still to come, never one already made.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class WorkLocationWorkflow
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, string $channel = ''): WorkLocation
    {
        $location = WorkLocation::create($attributes);

        $this->log('created', "Created work location \"{$location->name}\" ({$location->radius_meters} m fence){$channel}", $location);

        return $location;
    }

    /**
     * Change a site. Only the columns given change.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(WorkLocation $location, array $attributes, string $channel = ''): WorkLocation
    {
        $location->update($attributes);

        $this->log('updated', "Updated work location \"{$location->name}\"{$channel}", $location, [
            'changes' => array_keys($location->getChanges()),
        ]);

        return $location;
    }

    /**
     * Set who is based here, whole — the Locations screen's people dialog.
     * Anybody not listed stops being based here; somebody made primary here
     * stops having a primary site anywhere else.
     *
     * @param  list<int>  $employeeIds
     * @param  list<int>  $primaryIds
     */
    public function setPeople(WorkLocation $location, array $employeeIds, array $primaryIds, string $channel = ''): void
    {
        DB::transaction(function () use ($location, $employeeIds, $primaryIds): void {
            $location->employees()->sync(array_fill_keys($employeeIds, ['is_primary' => false]));
            $location->makePrimaryFor($primaryIds);
        });

        $count = count($employeeIds);

        $this->log('updated', "Set {$count} ".str('person')->plural($count)." as based at \"{$location->name}\"{$channel}", $location, [
            'employees' => $count,
            'primary' => count($primaryIds),
        ]);
    }

    /**
     * Base these people here as well, leaving everybody already based here as
     * they are. With `$primary`, it becomes their primary site — and no other
     * site stays theirs as primary.
     *
     * @param  list<int>  $employeeIds
     */
    public function base(WorkLocation $location, array $employeeIds, bool $primary, string $channel = ''): void
    {
        DB::transaction(function () use ($location, $employeeIds, $primary): void {
            $location->employees()->syncWithoutDetaching(array_fill_keys($employeeIds, []));

            if ($primary) {
                $location->makePrimaryFor($employeeIds);
            }
        });

        $count = count($employeeIds);

        $this->log('updated', "Based {$count} ".str('person')->plural($count)." at \"{$location->name}\"".($primary ? ' as their primary site' : '').$channel, $location, [
            'employees' => $count,
            'primary' => $primary,
        ]);
    }

    /**
     * Stop these people being based here. Anybody else based here stays.
     *
     * @param  list<int>  $employeeIds
     */
    public function unbase(WorkLocation $location, array $employeeIds, string $channel = ''): void
    {
        $location->employees()->detach($employeeIds);

        $count = count($employeeIds);

        $this->log('updated', "Stopped basing {$count} ".str('person')->plural($count)." at \"{$location->name}\"{$channel}", $location, [
            'employees' => $count,
        ]);
    }

    /**
     * Archive a site: punches stop being checked against it and it leaves both
     * precedence chains. Punches keep naming it.
     */
    public function archive(WorkLocation $location, string $channel = ''): void
    {
        $name = $location->name;
        $location->delete();

        $this->log('archived', "Archived work location \"{$name}\"{$channel}", null, label: $name);
    }

    public function restore(WorkLocation $location, string $channel = ''): void
    {
        $location->restore();

        $this->log('restored', "Restored work location \"{$location->name}\"{$channel}", $location);
    }

    /**
     * @throws WorkLocationException while any punch names the site
     */
    public function forceDelete(WorkLocation $location, string $channel = ''): void
    {
        if (AttendancePunch::withTrashed()->where('work_location_id', $location->id)->exists()) {
            throw new WorkLocationException('Punches were made at this location, so it is kept to say where they were. It stays archived.');
        }

        $name = $location->name;
        $location->forceDelete();

        $this->log('deleted', "Permanently deleted work location \"{$name}\"{$channel}", null, label: $name);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, ?WorkLocation $subject, array $properties = [], ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            properties: $properties,
            logName: 'company-setup',
            subjectLabel: $label ?? $subject?->name,
        );
    }
}
