<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrashItemResource;
use App\Queries\TrashIndexQuery;
use App\Support\ActivityLogger;
use App\Support\Trash\TrashPresenter;
use App\Support\Trash\TrashRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Trash Bin — a unified view over every soft-deleted ("archived") record the
 * actor may see, with restore and permanent-delete actions.
 *
 * Permissions are never invented here: a type is listed only if the actor can
 * *view* it, and restore / permanent-delete each require the owning module's own
 * permission (see {@see TrashRegistry}), so the bin can't bypass RBAC.
 */
class TrashController extends Controller
{
    /**
     * Display the trash bin.
     */
    public function index(Request $request, TrashIndexQuery $query): Response
    {
        $user = $request->user();
        $summary = $query->summary($user);

        // Nobody with zero viewable trashable types should reach the page.
        abort_if($summary['types'] === [], 403);

        return Inertia::render('system/trash/index', [
            'items' => TrashItemResource::collection($query->paginate($request, $user)),
            'summary' => $summary,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'type' => $query->type($request, $user),
                'per_page' => $query->perPage($request),
            ],
        ]);
    }

    /**
     * Restore a single archived record.
     */
    public function restore(Request $request): RedirectResponse
    {
        [$type, $definition, $model] = $this->resolve($request, 'restore');

        $label = TrashPresenter::present($type, $model, $request->user())['title'];
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored {$definition['label']} {$label} from the trash",
            subject: $model,
            logName: 'trash',
            subjectLabel: $label,
        );

        return $this->respond("{$definition['label']} restored.");
    }

    /**
     * Permanently delete a single archived record.
     */
    public function forceDelete(Request $request): RedirectResponse
    {
        [$type, $definition, $model] = $this->resolve($request, 'forceDelete');

        $label = TrashPresenter::present($type, $model, $request->user())['title'];
        $this->cleanupFiles($type, $model);
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted {$definition['label']} {$label}",
            logName: 'trash',
            subjectLabel: $label,
        );

        return $this->respond("{$definition['label']} permanently deleted.");
    }

    /**
     * Restore or permanently delete a batch of selected records, re-authorising
     * each item by its own type so a tampered payload can't escalate.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['restore', 'delete'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string'],
            'items.*.id' => ['required', 'integer'],
        ]);

        $restoring = $validated['action'] === 'restore';
        $ability = $restoring ? 'restore' : 'forceDelete';
        $user = $request->user();
        $count = 0;

        foreach ($validated['items'] as $item) {
            $definition = TrashRegistry::definition($item['type']);

            if ($definition === null
                || ! $user->can($definition['view'])
                || ! $user->can($definition[$ability])) {
                continue;
            }

            $model = $definition['model']::onlyTrashed()->find($item['id']);

            if ($model === null) {
                continue;
            }

            if ($restoring) {
                $model->restore();
            } else {
                $this->cleanupFiles($item['type'], $model);
                $model->forceDelete();
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

        $verb = $restoring ? 'restored' : 'permanently deleted';

        return $this->respond(
            $count > 0 ? "{$count} item(s) {$verb}." : 'Nothing to update.',
            $count > 0 ? 'success' : 'warning',
        );
    }

    /**
     * Permanently delete everything the actor is allowed to force-delete.
     */
    public function empty(Request $request): RedirectResponse
    {
        $user = $request->user();
        $count = 0;

        foreach (TrashRegistry::types() as $type => $definition) {
            if (! $user->can($definition['view']) || ! $user->can($definition['forceDelete'])) {
                continue;
            }

            $definition['model']::onlyTrashed()->get()->each(function (Model $model) use ($type, &$count): void {
                $this->cleanupFiles($type, $model);
                $model->forceDelete();
                $count++;
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

        return $this->respond(
            $count > 0 ? "Trash emptied — {$count} item(s) permanently deleted." : 'The trash is already empty.',
            $count > 0 ? 'success' : 'warning',
        );
    }

    /**
     * Validate the {type, id} payload, authorise the ability for that type, and
     * return [type, definition, trashed model].
     *
     * @return array{0: string, 1: array<string, mixed>, 2: Model}
     */
    private function resolve(Request $request, string $ability): array
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'id' => ['required', 'integer'],
        ]);

        $definition = TrashRegistry::definition($validated['type']);
        abort_if($definition === null, 404);

        $user = $request->user();
        abort_unless($user->can($definition['view']) && $user->can($definition[$ability]), 403);

        $model = $definition['model']::onlyTrashed()->find($validated['id']);
        abort_if($model === null, 404);

        return [$validated['type'], $definition, $model];
    }

    /**
     * Best-effort cleanup of files a record owns on disk before it is purged,
     * so permanent deletion doesn't orphan uploaded photos.
     */
    private function cleanupFiles(string $type, Model $model): void
    {
        $path = match ($type) {
            'user' => $model->profile_photo,
            'employee' => $model->photo,
            default => null,
        };

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Flash a toast and bounce back to the listing.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
