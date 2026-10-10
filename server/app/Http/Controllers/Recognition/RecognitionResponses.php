<?php

namespace App\Http\Controllers\Recognition;

use App\Models\Employee;
use App\Support\Recognition\RecognitionException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * How the recognition screens answer (ADR 0071): a refusal that belongs to a
 * form field comes back as that field's error, any other as a warning toast,
 * and the person acting is resolved to their employee record or refused.
 */
trait RecognitionResponses
{
    /**
     * Run a workflow call, then toast `$success` (a string, or a closure given
     * the call's result). A refusal comes back in its own words.
     *
     * @param  string|Closure(mixed): string  $success
     */
    protected function attempt(Closure $call, string|Closure $success): RedirectResponse
    {
        try {
            $result = $call();
        } catch (RecognitionException $e) {
            if ($e->field !== null) {
                throw ValidationException::withMessages([$e->field => $e->getMessage()]);
            }

            return $this->toast($e->getMessage(), 'warning');
        }

        return $this->toast(is_string($success) ? $success : $success($result));
    }

    protected function toast(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }

    /**
     * The signed-in person's employee record here, or a 403 that says why.
     */
    protected function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
