<?php

namespace App\Support\Setup;

use App\Http\Requests\Setup\AwardTypeRequest;
use App\Models\AwardType;
use App\Support\ActivityLogger;

/**
 * Everything that changes the catalogue of recognitions the Awards module gives
 * out: create, edit (including retiring one from being given), archive, restore
 * and permanently delete.
 *
 * The Award Types screen and the assistant both come through here, so a type is
 * written and recorded the same way whoever asked. Validation is
 * {@see AwardTypeRequest}, which both run first. Refusals are
 * {@see AwardTypeException}, worded to be shown as they are.
 *
 * Awards already given keep their type, retired or archived.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class AwardTypeWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $channel = ''): AwardType
    {
        $type = AwardType::create($data);

        $this->log('created', "Created award type \"{$type->name}\"{$channel}", $type);

        return $type;
    }

    /**
     * Change a type. Only the keys given change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AwardType $type, array $data, string $channel = ''): AwardType
    {
        $type->update($data);

        $this->log('updated', "Updated award type \"{$type->name}\"{$channel}", $type);

        return $type;
    }

    public function archive(AwardType $type, string $channel = ''): void
    {
        $name = $type->name;
        $type->delete();

        $this->log('archived', "Archived award type \"{$name}\"{$channel}", null, $name);
    }

    public function restore(AwardType $type, string $channel = ''): void
    {
        $type->restore();

        $this->log('restored', "Restored award type \"{$type->name}\"{$channel}", $type);
    }

    /**
     * @throws AwardTypeException once it has been given out
     */
    public function forceDelete(AwardType $type, string $channel = ''): void
    {
        if ($type->awards()->exists()) {
            throw new AwardTypeException('This award type has been given out and cannot be permanently deleted.');
        }

        $name = $type->name;
        $type->forceDelete();

        $this->log('deleted', "Permanently deleted award type \"{$name}\"{$channel}", null, $name);
    }

    private function log(string $event, string $description, ?AwardType $subject, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $subject,
            logName: 'company-setup',
            subjectLabel: $label ?? $subject?->name,
        );
    }
}
