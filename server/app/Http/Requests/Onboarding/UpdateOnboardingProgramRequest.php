<?php

namespace App\Http\Requests\Onboarding;

/**
 * Same shape as {@see StoreOnboardingProgramRequest}; a distinct class keeps the
 * controller signatures self-documenting and leaves room for update-only rules.
 */
class UpdateOnboardingProgramRequest extends StoreOnboardingProgramRequest {}
