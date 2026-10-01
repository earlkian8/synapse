<?php

namespace App\Support\Trash;

use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Users\UserAccountException;
use App\Support\Users\UserAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Restoring and permanently deleting archived records (ADR 0057) — one record,
 * a selection, or everything the actor may purge.
 *
 * The Trash Bin screen and the assistant both come through here. Records are
 * found in this workspace only ({@see TrashRegistry::trashed()}), and each type
 * is authorised by its own module's permissions ({@see TrashRegistry::allows()})
 * — the bin never grants anything the module would not. An account goes
 * through {@see UserAccounts}, so the bin cannot delete what the Users screen
 * would refuse to: your own account, one another workspace shares, one with
 * more access than yours. Refusals are {@see TrashException}.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class TrashBin
{
    public function __construct(private readonly UserAccounts $accounts) {}

    /**
     * Put an archived record back. Returns its name.
     */
    public function restore(string $type, Model $model, string $channel = ''): string
    {
        $label = $this->label($type, $model);

        if ($model instanceof User) {
            $this->accounts->restore($model, $channel);

            return $label;
        }

        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: 'Restored '.TrashRegistry::definition($type)['label']." {$label} from the trash{$channel}",
            subject: $model,
            logName: 'trash',
            subjectLabel: $label,
        );

        return $label;
    }

    /**
     * Delete an archived record for good. Returns its name.
     *
     * @throws TrashException
     */
    public function forceDelete(string $type, Model $model, User $actor, string $channel = ''): string
    {
        $label = $this->label($type, $model);

        if ($model instanceof User) {
            try {
                $this->accounts->forceDelete($model, $actor, $channel);
            } catch (UserAccountException $e) {
                throw new TrashException($e->getMessage(), previous: $e);
            }

            return $label;
        }

        $this->purge($type, $model);

        ActivityLogger::log(
            event: 'deleted',
            description: 'Permanently deleted '.TrashRegistry::definition($type)['label']." {$label}{$channel}",
            logName: 'trash',
            subjectLabel: $label,
        );

        return $label;
    }

    /**
     * Restore or permanently delete a selection, re-authorising each item by
     * its own type so a tampered payload can't escalate. Items the actor may
     * not touch, that are gone, or that their own rules refuse are skipped.
     * Returns how many were done.
     *
     * @param  list<array{type: string, id: int}>  $items
     */
    public function bulk(bool $restoring, array $items, User $actor): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (! TrashRegistry::allows($actor, $item['type'], $restoring ? 'restore' : 'forceDelete')) {
                continue;
            }

            $model = TrashRegistry::find($item['type'], (int) $item['id']);

            if ($model === null || ! $this->quietly($restoring, $item['type'], $model, $actor)) {
                continue;
            }

            $count++;
        }

        if ($count > 0) {
            ActivityLogger::log(
                event: $restoring ? 'restored' : 'deleted',
                description: ($restoring ? 'Restored' : 'Permanently deleted')." {$count} item(s) from the trash",
                logName: 'trash',
                subjectLabel: "{$count} item(s)",
            );
        }

        return $count;
    }

    /**
     * Permanently delete everything in this workspace's bin that the actor may
     * purge. Returns how many were deleted.
     */
    public function empty(User $actor): int
    {
        $count = 0;

        foreach (array_keys(TrashRegistry::types()) as $type) {
            if (! TrashRegistry::allows($actor, $type, 'forceDelete')) {
                continue;
            }

            TrashRegistry::trashed($type)->get()->each(function (Model $model) use ($type, $actor, &$count): void {
                $count += $this->quietly(false, $type, $model, $actor) ? 1 : 0;
            });
        }

        if ($count > 0) {
            ActivityLogger::log(
                event: 'deleted',
                description: "Emptied the trash — {$count} item(s) permanently deleted",
                logName: 'trash',
                subjectLabel: "{$count} item(s)",
            );
        }

        return $count;
    }

    /**
     * The name a record goes by in the bin.
     */
    public function label(string $type, Model $model): string
    {
        return (string) match ($type) {
            'user', 'employee' => $model->full_name,
            default => $model->name,
        };
    }

    /**
     * One item of a sweep, recorded by the sweep's summary rather than on its
     * own. False when its own rules refuse it.
     */
    private function quietly(bool $restoring, string $type, Model $model, User $actor): bool
    {
        if ($restoring) {
            $model->restore();

            return true;
        }

        if ($model instanceof User) {
            $why = UserAccounts::whyNotPurge($model, $actor);

            if ($why !== null) {
                return false;
            }
        }

        $this->purge($type, $model);

        return true;
    }

    /**
     * Best-effort cleanup of files a record owns on disk, then the record.
     */
    private function purge(string $type, Model $model): void
    {
        $path = match ($type) {
            'user' => $model->profile_photo,
            'employee' => $model->photo,
            default => null,
        };

        if ($path) {
            Storage::disk('public')->delete($path);
        }

        $model->forceDelete();
    }
}
