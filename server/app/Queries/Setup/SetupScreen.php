<?php

namespace App\Queries\Setup;

use App\Http\Controllers\Setup\SetupWizardController;
use Illuminate\Http\Request;

/**
 * What one Company Setup screen shows: the records it lists, the options its
 * editors choose from, and what the signed-in person may do there.
 *
 * Every screen is read in two places — its own page under Company Setup, and the
 * setup wizard step that configures the same module
 * ({@see SetupWizardController}). Both render exactly this array, so a
 * wizard step can offer every action its Company Setup screen does without the
 * two ever drifting apart.
 */
interface SetupScreen
{
    /**
     * The screen's props, shaped as the client's `*PageProps` type for it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array;
}
