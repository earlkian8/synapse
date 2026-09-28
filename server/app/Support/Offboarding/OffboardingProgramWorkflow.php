<?php

namespace App\Support\Offboarding;

use App\Http\Requests\Offboarding\OffboardingProgramRequest;
use App\Models\OffboardingProgram;
use App\Support\ActivityLogger;
use App\Support\OffboardingProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes the clearance templates exits are seeded from
 * (ADR 0016): create, edit (with the blueprint sign-offs) and delete.
 *
 * The Offboarding Programs screen and the assistant both come through here, so
 * a template is written and recorded the same way whoever asked. Validation is
 * {@see OffboardingProgramRequest}, which both run first.
 *
 * A template only reaches exits started after the change —
 * {@see OffboardingProvisioner} copies its items onto a case when the exit
 * starts, and an exit in flight keeps the checklist it was given.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class OffboardingProgramWorkflow
{
    /**
     * @param  array<string, mixed>  $attributes  The template's own columns.
     * @param  list<array<string, mixed>>  $items  The blueprint sign-offs, in order.
     */
    public function create(array $attributes, array $items, string $channel = ''): OffboardingProgram
    {
        $program = DB::transaction(function () use ($attributes, $items): OffboardingProgram {
            $program = OffboardingProgram::create($attributes);
            $this->enforceSingleDefault($program);
            $this->syncItems($program, $items);

            return $program;
        });

        $this->log('created', "Created clearance template \"{$program->name}\"{$channel}", $program);

        return $program;
    }

    /**
     * Change a template. Only the columns given change; its sign-offs are
     * replaced by the list given, or left alone when none is given.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>|null  $items
     */
    public function update(OffboardingProgram $program, array $attributes, ?array $items = null, string $channel = ''): OffboardingProgram
    {
        DB::transaction(function () use ($program, $attributes, $items): void {
            $program->update($attributes);
            $this->enforceSingleDefault($program);

            if ($items !== null) {
                $this->syncItems($program, $items);
            }
        });

        $this->log('updated', "Updated clearance template \"{$program->name}\"{$channel}", $program);

        return $program;
    }

    /**
     * Delete a template. Exits in flight keep the items they were given; the
     * cases stop naming it.
     */
    public function delete(OffboardingProgram $program, string $channel = ''): void
    {
        $name = $program->name;
        $program->delete();

        $this->log('deleted', "Deleted clearance template \"{$name}\"{$channel}", null, $name);
    }

    /**
     * Keep at most one default template per tenant.
     */
    private function enforceSingleDefault(OffboardingProgram $program): void
    {
        if ($program->is_default) {
            OffboardingProgram::whereKeyNot($program->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }
    }

    /**
     * Replace a template's blueprint sign-offs wholesale (they carry no
     * history). A sign-off owned by "the employee's own department" names none.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(OffboardingProgram $program, array $items): void
    {
        $program->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $program->items()->create([
                'item' => $item['item'],
                'department_id' => ($item['use_employee_department'] ?? false) ? null : ($item['department_id'] ?? null),
                'use_employee_department' => (bool) ($item['use_employee_department'] ?? false),
                'sort_order' => $index,
            ]);
        }
    }

    private function log(string $event, string $description, ?OffboardingProgram $subject, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            logName: 'offboarding',
            subjectLabel: $label ?? $subject?->name,
        );
    }
}
