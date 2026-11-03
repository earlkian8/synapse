<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\SkillTaxonomyRequest;
use App\Support\Recruitment\Skills\Esco\EscoSkills;
use Illuminate\Http\JsonResponse;

/**
 * What the posting form learns from ESCO (ADR 0100) about the skills the
 * catalogue doesn't know. Each answer is one ESCO request at most and is kept,
 * so a skill or title is asked about once; when ESCO is off or unreachable
 * the answers are empty and the form works from the catalogue alone.
 */
class SkillTaxonomyController extends Controller
{
    /**
     * How the ATS will read one skill: the catalogue's, ESCO's, or by its
     * words. Looking it up now also means its candidates' scores can use it
     * before the posting is even saved.
     */
    public function lookup(SkillTaxonomyRequest $request, EscoSkills $esco): JsonResponse
    {
        return response()->json($esco->reading($request->text()));
    }

    /**
     * ESCO skills that start with what HR is typing.
     */
    public function suggest(SkillTaxonomyRequest $request, EscoSkills $esco): JsonResponse
    {
        return response()->json(['suggestions' => $esco->suggest($request->text())]);
    }

    /**
     * The skills ESCO lists for a job title, essential and optional.
     */
    public function role(SkillTaxonomyRequest $request, EscoSkills $esco): JsonResponse
    {
        return response()->json(['role' => $esco->forRole($request->text())]);
    }
}
