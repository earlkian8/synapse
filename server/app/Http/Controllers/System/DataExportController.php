<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataExport\StoreDataExportRequest;
use App\Http\Resources\DataExportResource;
use App\Models\DataExport;
use App\Support\DataExport\ArchiveBuilder;
use App\Support\DataExport\DataExportCatalogue;
use App\Support\DataExport\DataExportException;
use App\Support\DataExport\DataExports;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data Export (ADR 0066) — a copy of the workspace's records, as an archive the
 * person who asked for it downloads. The rules live in {@see DataExports}.
 */
class DataExportController extends Controller
{
    /** How many past exports the history shows. */
    private const HISTORY = 25;

    /**
     * The screen: what the viewer may export, and the history of exports.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $organization = app(Tenancy::class)->organization();
        $organizationId = (int) $organization?->id;

        // Lazy: the page polls for `exports` alone while an archive is written,
        // and counting every dataset's rows each time would be wasted work.
        $datasets = fn (): array => collect(DataExportCatalogue::for($user))
            ->map(fn (array $dataset, string $key): array => [
                'key' => $key,
                'label' => $dataset['label'],
                'section' => $dataset['section'],
                'description' => $dataset['description'],
                'tables' => count($dataset['tables']),
                'has_files' => DataExportCatalogue::hasFiles($dataset),
                'records' => collect($dataset['tables'])
                    ->sum(fn (array $table): int => DataExportCatalogue::query($table, $organizationId)->count()),
            ])
            ->values()
            ->all();

        $history = DataExport::query()->with('requester')->latestFirst()->limit(self::HISTORY)->get();

        return Inertia::render('system/data-export/index', [
            'datasets' => $datasets,
            'exports' => DataExportResource::collection($history)->resolve($request),
            'can' => [
                'create' => $user->can('data-export.create'),
            ],
            'retention_days' => DataExport::RETENTION_DAYS,
            'archive_name' => fn (): string => $organization ? ArchiveBuilder::filename($organization) : 'data-export.zip',
        ]);
    }

    /**
     * Ask for an export; it is built once the response has gone.
     */
    public function store(StoreDataExportRequest $request, DataExports $exports): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $exports->request(
                $request->user(),
                $validated['datasets'],
                $validated['format'],
                (bool) ($validated['include_files'] ?? false),
            );
        } catch (DataExportException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Preparing your export. You\'ll be notified when it\'s ready to download.');
    }

    /**
     * Download an archive — only by the person who asked for it.
     */
    public function download(Request $request, DataExport $dataExport, DataExports $exports): StreamedResponse|RedirectResponse
    {
        $this->ensureThisWorkspace($dataExport);
        abort_unless($dataExport->isDownloadable(), 404);
        abort_unless($exports->canDownload($request->user(), $dataExport), 403);

        try {
            return $exports->download($dataExport);
        } catch (DataExportException $e) {
            return $this->respond($e->getMessage(), 'error');
        }
    }

    /**
     * Delete an export and its archive.
     */
    public function destroy(DataExport $dataExport, DataExports $exports): RedirectResponse
    {
        $this->ensureThisWorkspace($dataExport);

        try {
            $exports->delete($dataExport);
        } catch (DataExportException $e) {
            return $this->respond($e->getMessage(), 'error');
        }

        return $this->respond('Export deleted.');
    }

    /**
     * The binding is already confined to the current workspace; an archive of
     * personal data is checked again rather than trusted to that alone.
     */
    private function ensureThisWorkspace(DataExport $dataExport): void
    {
        abort_unless($dataExport->organization_id === app(Tenancy::class)->id(), 404);
    }

    /**
     * Flash a toast and bounce back to the screen.
     */
    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
