<?php

namespace App\Support\Recruitment;

use App\Http\Requests\Recruitment\StoreRecruitmentPipelineRequest;
use App\Models\RecruitmentPipeline;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Everything that changes the hiring processes postings run on (ADR 0029):
 * create, edit (name, default, and the ordered stages) and delete a pipeline.
 *
 * The Recruitment Pipelines screen and the assistant both come through here, so
 * a pipeline is written and recorded the same way whoever asked. Validation is
 * {@see StoreRecruitmentPipelineRequest::rulesFor()} and
 * {@see StoreRecruitmentPipelineRequest::validateStages()}, which both run
 * first. Refusals are {@see PipelineException}, worded to be shown as they are:
 *
 * - a stage candidates still sit on cannot be dropped;
 * - a pipeline postings still run on cannot be deleted;
 * - the default cannot simply be switched off — new postings, and the
 *   assistant's, resolve through it — only replaced by making another the
 *   default.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class PipelineWorkflow
{
    /**
     * Create a pipeline with its stages. A company's first pipeline is always
     * the default: nothing else could resolve a posting's pipeline otherwise.
     *
     * @param  list<array{name: string, kind: string}>  $stages
     */
    public function create(string $name, bool $isDefault, array $stages, string $channel = ''): RecruitmentPipeline
    {
        $pipeline = DB::transaction(function () use ($name, $isDefault, $stages): RecruitmentPipeline {
            $pipeline = RecruitmentPipeline::create([
                'name' => $name,
                'is_default' => $isDefault || ! RecruitmentPipeline::query()->exists(),
            ]);
            $pipeline->enforceSingleDefault();
            $pipeline->syncStages($stages);

            return $pipeline;
        });

        $this->log('created', "Created recruitment pipeline \"{$pipeline->name}\"{$channel}", $pipeline);

        return $pipeline;
    }

    /**
     * Change a pipeline. Its stages are replaced by the list given — kept stages
     * carry their id — or left alone when none is given.
     *
     * @param  list<array{id?: int|null, name: string, kind: string}>|null  $stages
     *
     * @throws PipelineException
     */
    public function update(RecruitmentPipeline $pipeline, string $name, bool $isDefault, ?array $stages, string $channel = ''): RecruitmentPipeline
    {
        if ($pipeline->is_default && ! $isDefault) {
            throw new PipelineException('This is the default pipeline. Make another pipeline the default instead of switching it off here.');
        }

        try {
            DB::transaction(function () use ($pipeline, $name, $isDefault, $stages): void {
                $pipeline->update(['name' => $name, 'is_default' => $isDefault]);
                $pipeline->enforceSingleDefault();

                if ($stages !== null) {
                    $pipeline->syncStages($stages);
                }
            });
        } catch (RuntimeException $e) {
            // syncStages() refuses to drop a stage candidates still sit on.
            throw new PipelineException($e->getMessage(), previous: $e);
        }

        $this->log('updated', "Updated recruitment pipeline \"{$pipeline->name}\"{$channel}", $pipeline);

        return $pipeline;
    }

    /**
     * Delete a pipeline. When it was the default, another becomes the default,
     * so postings still have one to resolve to.
     *
     * @throws PipelineException while a posting runs on it
     */
    public function delete(RecruitmentPipeline $pipeline, string $channel = ''): void
    {
        if ($pipeline->postings()->exists()) {
            throw new PipelineException('This pipeline is in use by one or more job postings and can\'t be deleted.');
        }

        $name = $pipeline->name;
        $wasDefault = $pipeline->is_default;
        $pipeline->delete();

        if ($wasDefault) {
            RecruitmentPipeline::query()->orderBy('name')->first()?->update(['is_default' => true]);
        }

        $this->log('deleted', "Deleted recruitment pipeline \"{$name}\"{$channel}", null, $name);
    }

    private function log(string $event, string $description, ?RecruitmentPipeline $subject, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            logName: 'recruitment',
            subjectLabel: $label ?? $subject?->name,
        );
    }
}
