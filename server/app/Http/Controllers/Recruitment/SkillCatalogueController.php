<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Support\Recruitment\Skills\SkillCatalogue;
use Illuminate\Http\JsonResponse;

class SkillCatalogueController extends Controller
{
    /**
     * The skills the ATS knows (ADR 0097), for the posting form to suggest them
     * and to say what else counts as each: its other names, the skills that
     * prove it, and the related ones that earn part of its credit. The same
     * for every workspace, so the browser may keep it for an hour.
     */
    public function __invoke(SkillCatalogue $catalogue): JsonResponse
    {
        return response()
            ->json($catalogue->describe())
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
