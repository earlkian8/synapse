<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\ActivateLocalModelRequest;
use App\Models\LocalModel;
use App\Support\Ml\Graduation\GraduationException;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Model graduation for the three predictive surfaces (ADR 0046): train a model on
 * the organisation's own records, switch the surface to it — even one that did not
 * pass its check, when that is acknowledged (ADR 0107) — keep the model in use
 * instead, or switch back. Thin: each
 * route is registered once per surface — which it receives as `$surface` — behind
 * that surface's `*.manage` permission, and delegates to {@see ModelGraduation}.
 */
class ModelGraduationController extends Controller
{
    /**
     * Train the surface's model on the organisation's own records and check it.
     */
    public function train(Request $request, ModelGraduation $graduation, string $surface): RedirectResponse
    {
        try {
            $attempt = $graduation->train($surface, $request->user());
        } catch (GraduationException|MlException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', match (true) {
            $attempt->passed => ['type' => 'success', 'message' => 'Your model passed its check. Review the result, then switch to it when you’re ready.'],
            $attempt->status === 'ready' => ['type' => 'info', 'message' => 'Your model didn’t pass its check. Nothing has changed — review why, then keep the current model or use yours anyway.'],
            default => ['type' => 'info', 'message' => 'Your model didn’t pass its check and couldn’t be kept, so nothing has changed. The panel says why.'],
        });

        return back();
    }

    /**
     * Switch the surface to the model on offer — one that did not pass its check
     * only with `despite_check`. (Route parameters arrive in order — the URI's
     * `{localModel}`, then the `surface` default.)
     */
    public function activate(ActivateLocalModelRequest $request, ModelGraduation $graduation, LocalModel $localModel, string $surface): RedirectResponse
    {
        abort_unless($localModel->model === $surface, 404);

        try {
            $graduation->activate($localModel, $request->user(), despiteCheck: $request->despiteCheck());
        } catch (GraduationException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ModelGraduation::SURFACES[$surface]['title'].' now uses your organisation’s own model. Run it again to score everyone with it.',
        ]);

        return back();
    }

    /**
     * Keep the model in use, and set the one on offer aside.
     */
    public function dismiss(Request $request, ModelGraduation $graduation, LocalModel $localModel, string $surface): RedirectResponse
    {
        abort_unless($localModel->model === $surface, 404);

        try {
            $graduation->dismiss($localModel, $request->user());
        } catch (GraduationException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ModelGraduation::SURFACES[$surface]['title'].' keeps '
                .(LocalModel::activeFor($surface) ? 'your model in use' : 'the general model').'. The new model was set aside.',
        ]);

        return back();
    }

    /**
     * Switch the surface back to the general model.
     */
    public function revert(Request $request, ModelGraduation $graduation, string $surface): RedirectResponse
    {
        try {
            $graduation->revert($surface, $request->user());
        } catch (GraduationException $e) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => ModelGraduation::SURFACES[$surface]['title'].' is back on the general model. Run it again to rescore everyone.',
        ]);

        return back();
    }
}
