<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrashItemResource;
use App\Queries\TrashIndexQuery;
use App\Support\Trash\TrashBin;
use App\Support\Trash\TrashException;
use App\Support\Trash\TrashRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function restore(Request $request, TrashBin $bin): RedirectResponse
    {
        [$type, $definition, $model] = $this->resolve($request, 'restore');

        $bin->restore($type, $model);

        return $this->respond("{$definition['label']} restored.");
    }

    /**
     * Permanently delete a single archived record.
     */
    public function forceDelete(Request $request, TrashBin $bin): RedirectResponse
    {
        [$type, $definition, $model] = $this->resolve($request, 'forceDelete');

        try {
            $bin->forceDelete($type, $model, $request->user());
        } catch (TrashException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond("{$definition['label']} permanently deleted.");
    }

    /**
     * Restore or permanently delete a batch of selected records, re-authorising
     * each item by its own type so a tampered payload can't escalate.
     */
    public function bulk(Request $request, TrashBin $bin): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['restore', 'delete'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', 'string'],
            'items.*.id' => ['required', 'integer'],
        ]);

        $restoring = $validated['action'] === 'restore';
        $count = $bin->bulk($restoring, $validated['items'], $request->user());
        $verb = $restoring ? 'restored' : 'permanently deleted';

        return $this->respond(
            $count > 0 ? "{$count} item(s) {$verb}." : 'Nothing to update.',
            $count > 0 ? 'success' : 'warning',
        );
    }

    /**
     * Permanently delete everything the actor is allowed to force-delete.
     */
    public function empty(Request $request, TrashBin $bin): RedirectResponse
    {
        $count = $bin->empty($request->user());

        return $this->respond(
            $count > 0 ? "Trash emptied — {$count} item(s) permanently deleted." : 'The trash is already empty.',
            $count > 0 ? 'success' : 'warning',
        );
    }

    /**
     * Validate the {type, id} payload, authorise the ability for that type, and
     * return [type, definition, trashed model] — found in this workspace only.
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
        abort_unless(TrashRegistry::allows($request->user(), $validated['type'], $ability), 403);

        $model = TrashRegistry::find($validated['type'], (int) $validated['id']);
        abort_if($model === null, 404);

        return [$validated['type'], $definition, $model];
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
