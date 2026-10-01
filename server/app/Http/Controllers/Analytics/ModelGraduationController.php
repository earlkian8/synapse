<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\LocalModel;
use App\Support\Ml\Graduation\GraduationException;
use App\Support\Ml\Graduation\ModelGraduation;
use App\Support\Ml\MlException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Model graduation for the three predictive surfaces (ADR 0046): train a model on
 * the organisation's own records, switch the surface to it, switch back. Thin: each
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

        Inertia::flash('toast', $attempt->status === 'ready'
            ? ['type' => 'success', 'message' => 'Your model passed its check. Review the result, then switch to it when you’re ready.']
            : ['type' => 'info', 'message' => 'Your model didn’t pass its check, so nothing has changed. The panel says why.']);

        return back();
    }

    /**
     * Switch the surface to a model that passed its check. (Route parameters arrive
     * in order — the URI's `{localModel}`, then the `surface` default.)
     */
    public function activate(Request $request, ModelGraduation $graduation, LocalModel $localModel, string $surface): RedirectResponse
    {
        abort_unless($localModel->model === $surface, 404);

        try {
            $graduation->activate($localModel, $request->user());
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
